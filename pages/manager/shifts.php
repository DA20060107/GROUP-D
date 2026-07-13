<?php
/**
 * シフト作成・確認画面（店長用）
 *
 * シフトの新規作成、登録済みシフトの確認、シフトの無効化を行う。
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/services/substitute_matcher.php';
require_once __DIR__ . '/../../app/includes/status_labels.php';
require_once __DIR__ . '/../../app/includes/calendar_ui.php';
require_once __DIR__ . '/../../app/includes/shift_table_helpers.php';
require_once __DIR__ . '/../../app/includes/schema_helpers.php';

$pageTitle = 'シフト作成・確認';
$basePath  = '../../public/';

$errorMessage   = '';
$successMessage = '';
$openShiftCreateModal = false;
$manualSubstituteFor = (int) ($_POST['manual_substitute_for'] ?? ($_GET['manual_substitute_for'] ?? 0));
$manualSubstituteRequest = null;
$managerUser = currentUser();
$managerId = (int) $managerUser['id'];

$newShiftForm = [
    'assignee_key' => '',
    'shift_date'  => '',
    'start_time'  => '',
    'end_time'    => '',
    'position'    => '',
    'note'        => '',
];

/**
 * 店長シフト登録に必要なカラムを既存DBへ補う。
 * schema.sql を未再実行のローカル環境でも画面が動くようにするための保険。
 */
function ensureShiftManagerAssigneeColumns(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME, IS_NULLABLE
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'shifts'
           AND COLUMN_NAME IN ('employee_id', 'manager_user_id')"
    );
    $stmt->execute();
    $columns = [];
    foreach ($stmt->fetchAll() as $column) {
        $columns[$column['COLUMN_NAME']] = $column['IS_NULLABLE'];
    }

    if (!isset($columns['manager_user_id'])) {
        $pdo->exec(
            "ALTER TABLE shifts
             ADD COLUMN manager_user_id INT NULL COMMENT '担当店長（users.id、従業員シフトの場合はNULL）' AFTER employee_id"
        );
    }

    if (($columns['employee_id'] ?? 'NO') !== 'YES') {
        $pdo->exec(
            "ALTER TABLE shifts
             MODIFY COLUMN employee_id INT NULL COMMENT '担当従業員（店長シフトの場合はNULL）'"
        );
    }
}

ensureShiftManagerAssigneeColumns($pdo);
ensureManualSubstituteShiftSchema($pdo);

// ------------------------------------------------------------
// POST処理
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_shift') {
        $assigneeKey = (string) ($_POST['assignee_key'] ?? ($_POST['employee_id'] ?? ''));
        $manualSubstituteFor = (int) ($_POST['manual_substitute_for'] ?? 0);
        $isManualSubstituteRegistration = $manualSubstituteFor > 0;
        $shiftDate  = trim($_POST['shift_date'] ?? '');
        $startTime  = trim($_POST['start_time'] ?? '');
        $endTime    = trim($_POST['end_time'] ?? '');
        $position   = trim($_POST['position'] ?? '');
        $note       = trim($_POST['note'] ?? '');

        $newShiftForm = [
            'assignee_key' => $assigneeKey,
            'shift_date'  => $shiftDate,
            'start_time'  => $startTime,
            'end_time'    => $endTime,
            'position'    => $position,
            'note'        => $note,
        ];

        if ($assigneeKey === '' || $shiftDate === '' || $startTime === '' || $endTime === '') {
            $errorMessage = '担当者・勤務日・開始時刻・終了時刻は必須です。';
            $openShiftCreateModal = true;
        } elseif ($startTime >= $endTime) {
            $errorMessage = '開始時刻は終了時刻より前にしてください。';
            $openShiftCreateModal = true;
        } else {
            $employeeId = null;
            $managerUserId = null;
            if (preg_match('/^employee:(\d+)$/', $assigneeKey, $matches) === 1) {
                $employeeId = (int) $matches[1];
                $stmt = $pdo->prepare('SELECT id FROM employees WHERE id = :id AND is_active = 1');
                $stmt->execute(['id' => $employeeId]);
                $assigneeExists = $stmt->fetch() !== false;
            } elseif (preg_match('/^manager:(\d+)$/', $assigneeKey, $matches) === 1) {
                $managerUserId = (int) $matches[1];
                $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND role = 'manager'");
                $stmt->execute(['id' => $managerUserId]);
                $assigneeExists = $stmt->fetch() !== false;
            } else {
                $assigneeExists = false;
            }

            if (!$assigneeExists) {
                $errorMessage = '指定された担当者が存在しないか、選択できない状態です。';
                $openShiftCreateModal = true;
            } else {
                $pdo->beginTransaction();
                try {
                    $shiftStatus = 'scheduled';
                    $relatedLeaveRequestId = null;

                    if ($isManualSubstituteRegistration) {
                        $stmt = $pdo->prepare(
                            "SELECT lr.id, lr.shift_id, lr.employee_id AS requester_employee_id, lr.status AS leave_status,
                                    s.status AS shift_status, s.shift_date, s.start_time, s.end_time
                             FROM leave_requests lr
                             JOIN shifts s ON s.id = lr.shift_id
                             WHERE lr.id = :leave_request_id
                               AND lr.status IN ('matching', 'no_candidate', 'replacement_pending')
                             FOR UPDATE"
                        );
                        $stmt->execute(['leave_request_id' => $manualSubstituteFor]);
                        $manualTarget = $stmt->fetch();

                        if ($manualTarget === false) {
                            $pdo->rollBack();
                            $errorMessage = '手動対応する休み申請が見つからないか、状態が変わっています。';
                            $openShiftCreateModal = true;
                        } elseif ($employeeId !== null && (int) $manualTarget['requester_employee_id'] === (int) $employeeId) {
                            $pdo->rollBack();
                            $errorMessage = '休み申請者本人は代勤担当者として登録できません。';
                            $openShiftCreateModal = true;
                        } else {
                            // 手動対応の代勤シフトは、元の休み申請対象シフトと同じ日付・時間で固定する。
                            // 画面上の入力値が改ざんされても、サーバー側では必ず元シフトの値を使用する。
                            $shiftDate = $manualTarget['shift_date'];
                            $startTime = substr($manualTarget['start_time'], 0, 5);
                            $endTime   = substr($manualTarget['end_time'], 0, 5);

                            $pdo->prepare(
                                "INSERT INTO approvals (leave_request_id, substitute_candidate_id, manager_id, status, approved_at)
                                 SELECT :leave_request_id, NULL, :manager_id, 'approved', NOW()
                                 WHERE NOT EXISTS (
                                     SELECT 1
                                     FROM approvals
                                     WHERE leave_request_id = :leave_request_id_check
                                       AND status = 'approved'
                                 )"
                            )->execute([
                                'leave_request_id'       => $manualSubstituteFor,
                                'manager_id'             => $managerId,
                                'leave_request_id_check' => $manualSubstituteFor,
                            ]);

                            $pdo->prepare(
                                "UPDATE leave_requests
                                 SET status = 'approved'
                                 WHERE id = :leave_request_id"
                            )->execute(['leave_request_id' => $manualSubstituteFor]);

                            $pdo->prepare(
                                "UPDATE shifts
                                 SET employee_id = :employee_id,
                                     manager_user_id = NULL,
                                     status = 'leave_approved'
                                 WHERE id = :shift_id"
                            )->execute([
                                'employee_id' => $manualTarget['requester_employee_id'],
                                'shift_id'    => $manualTarget['shift_id'],
                            ]);

                            $pdo->prepare(
                                "UPDATE shifts
                                 SET status = 'cancelled'
                                 WHERE related_leave_request_id = :leave_request_id
                                   AND status IN ('substituted', 'replacement_pending')"
                            )->execute(['leave_request_id' => $manualSubstituteFor]);

                            // 手動対応時も、「代勤可能」と回答済みの候補者はバックアップ候補として残す。
                            // まだ未回答の候補者だけ期限切れにする。
                            $pdo->prepare(
                                "UPDATE substitute_candidates
                                 SET status = 'expired'
                                 WHERE leave_request_id = :leave_request_id
                                   AND status = 'proposed'"
                            )->execute(['leave_request_id' => $manualSubstituteFor]);

                            $shiftStatus = 'substituted';
                            $relatedLeaveRequestId = $manualSubstituteFor;
                        }
                    }

                    if ($errorMessage === '') {
                        $stmt = $pdo->prepare(
                            "INSERT INTO shifts
                                (employee_id, manager_user_id, related_leave_request_id, shift_date, start_time, end_time, position, note, status)
                             VALUES
                                (:employee_id, :manager_user_id, :related_leave_request_id, :shift_date, :start_time, :end_time, :position, :note, :status)"
                        );
                        $stmt->execute([
                            'employee_id'               => $employeeId,
                            'manager_user_id'           => $managerUserId,
                            'related_leave_request_id'  => $relatedLeaveRequestId,
                            'shift_date'                => $shiftDate,
                            'start_time'                => $startTime,
                            'end_time'                  => $endTime,
                            'position'                  => $position !== '' ? $position : null,
                            'note'                      => $note !== '' ? $note : null,
                            'status'                    => $shiftStatus,
                        ]);

                        if ($isManualSubstituteRegistration) {
                            insertNotificationForEmployee(
                                $pdo,
                                (int) $manualTarget['requester_employee_id'],
                                'approval_result',
                                '休み申請が承認されました',
                                sprintf(
                                    '%sの%s〜%sの休み申請が承認され、店長が代勤シフトを登録しました。',
                                    date('n月j日', strtotime($shiftDate)),
                                    substr($startTime, 0, 5),
                                    substr($endTime, 0, 5)
                                ),
                                $manualSubstituteFor
                            );
                            $substituteNotificationMessage = sprintf(
                                '%sの%s〜%sのシフトに、代勤担当として登録されました。',
                                date('n月j日', strtotime($shiftDate)),
                                substr($startTime, 0, 5),
                                substr($endTime, 0, 5)
                            );
                            if ($employeeId !== null) {
                                insertNotificationForEmployee(
                                    $pdo,
                                    (int) $employeeId,
                                    'approval_result',
                                    '代勤シフトが登録されました',
                                    $substituteNotificationMessage,
                                    $manualSubstituteFor
                                );
                            } elseif ($managerUserId !== null) {
                                $pdo->prepare(
                                    "INSERT INTO notifications (user_id, type, title, message, is_read, related_leave_request_id)
                                     VALUES (:user_id, 'approval_result', '代勤シフトが登録されました', :message, 0, :leave_request_id)"
                                )->execute([
                                    'user_id'          => $managerUserId,
                                    'message'          => $substituteNotificationMessage,
                                    'leave_request_id' => $manualSubstituteFor,
                                ]);
                            }
                            $pdo->commit();
                            header('Location: shifts.php?msg=manual_substitute_created');
                            exit;
                        }

                        $pdo->commit();
                        header('Location: shifts.php?msg=created');
                        exit;
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            }
        }
    } elseif ($action === 'cancel_shift') {
        $id = (int) ($_POST['shift_id'] ?? 0);

        $stmt = $pdo->prepare('SELECT id FROM shifts WHERE id = :id');
        $stmt->execute(['id' => $id]);

        if ($stmt->fetch() === false) {
            $errorMessage = '指定されたシフトが見つかりません。';
        } else {
            $pdo->prepare("UPDATE shifts SET status = 'cancelled' WHERE id = :id")
                ->execute(['id' => $id]);

            header('Location: shifts.php?msg=cancelled');
            exit;
        }
    }
}

if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'created':
            $successMessage = 'シフトを登録しました。';
            break;
        case 'manual_substitute_created':
            $successMessage = '手動対応の代勤シフトを登録しました。';
            break;
        case 'cancelled':
            $successMessage = 'シフトを無効化しました。';
            break;
    }
}

// ------------------------------------------------------------
// シフト作成フォーム用：有効な従業員一覧
// ------------------------------------------------------------
$activeEmployees = $pdo->query(
    'SELECT id, name FROM employees WHERE is_active = 1 ORDER BY id'
)->fetchAll();

$stmt = $pdo->prepare(
    "SELECT id, name
     FROM users
     WHERE role = 'manager'
     ORDER BY CASE WHEN id = :manager_id THEN 0 ELSE 1 END, id"
);
$stmt->execute(['manager_id' => $managerId]);
$activeManagers = $stmt->fetchAll();

if ($manualSubstituteFor > 0) {
    $stmt = $pdo->prepare(
        "SELECT lr.id AS leave_request_id, lr.status AS leave_status,
                req.name AS requester_name,
                s.shift_date, s.start_time, s.end_time, s.position, s.note, s.status AS shift_status
         FROM leave_requests lr
         JOIN shifts s ON s.id = lr.shift_id
         JOIN employees req ON req.id = lr.employee_id
         WHERE lr.id = :leave_request_id
           AND lr.status IN ('matching', 'no_candidate', 'replacement_pending')"
    );
    $stmt->execute(['leave_request_id' => $manualSubstituteFor]);
    $manualSubstituteRequest = $stmt->fetch() ?: null;

    if ($manualSubstituteRequest !== null) {
        $openShiftCreateModal = true;
        if ($newShiftForm['shift_date'] === '') {
            $newShiftForm['shift_date'] = $manualSubstituteRequest['shift_date'];
        }
        if ($newShiftForm['start_time'] === '') {
            $newShiftForm['start_time'] = substr($manualSubstituteRequest['start_time'], 0, 5);
        }
        if ($newShiftForm['end_time'] === '') {
            $newShiftForm['end_time'] = substr($manualSubstituteRequest['end_time'], 0, 5);
        }
        if ($newShiftForm['position'] === '') {
            $newShiftForm['position'] = (string) ($manualSubstituteRequest['position'] ?? '');
        }
        if ($newShiftForm['note'] === '') {
            $newShiftForm['note'] = '手動対応の代勤シフト';
        }
    }
}

// ------------------------------------------------------------
// シフト一覧（無効化済みを除く）
// ------------------------------------------------------------
$shifts = $pdo->query(
    "SELECT s.*,
            e.name AS employee_name,
            mu.name AS manager_name,
            COALESCE(e.name, mu.name) AS assignee_name,
            CASE WHEN s.manager_user_id IS NOT NULL THEN 'manager' ELSE 'employee' END AS assignee_type,
            s.related_leave_request_id,
            lr.id AS leave_request_id,
            lr.status AS leave_status,
            lr.employee_id AS requester_employee_id,
            requester.name AS requester_employee_name
     FROM shifts s
     LEFT JOIN employees e ON e.id = s.employee_id
     LEFT JOIN users mu ON mu.id = s.manager_user_id AND mu.role = 'manager'
     LEFT JOIN leave_requests lr
        ON lr.id = (
            SELECT MAX(lr2.id)
            FROM leave_requests lr2
            WHERE lr2.shift_id = s.id
               OR lr2.id = s.related_leave_request_id
        )
     LEFT JOIN employees requester ON requester.id = lr.employee_id
     WHERE s.status <> 'cancelled'
       AND (e.id IS NOT NULL OR mu.id IS NOT NULL)
     ORDER BY s.shift_date, s.start_time"
)->fetchAll();

// ------------------------------------------------------------
// 勤務可能日一覧（シフト作成時の参考情報としてカレンダーへ集約表示）
// ------------------------------------------------------------
$availabilityRows = $pdo->query(
    'SELECT a.*, e.name AS employee_name
     FROM availability a
     JOIN employees e ON e.id = a.employee_id
     WHERE e.is_active = 1
     ORDER BY a.available_date, a.start_time, e.id'
)->fetchAll();

$focusDateText = (string) ($_GET['calendar_date'] ?? date('Y-m-d'));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $focusDateText) !== 1) {
    $focusDateText = date('Y-m-d');
}
$focusDate = new DateTimeImmutable($focusDateText);
$weekStart = $focusDate->modify('-' . ((int) $focusDate->format('N') - 1) . ' days');
$previousWeekStart = $weekStart->modify('-7 days');
$nextWeekStart = $weekStart->modify('+7 days');
$calendarMonth = $weekStart->format('Y-m');
$calendarView = 'week';
$shiftTableRows = [];
foreach ($shifts as $shift) {
    $timeRange = formatCalendarTime($shift['start_time']) . '-' . formatCalendarTime($shift['end_time']);
    $position = $shift['position'] !== null && $shift['position'] !== '' ? $shift['position'] : '担当未設定';
    $displayState = buildShiftTableDisplayState($shift, 'manager');
    $statusHtml = renderStatusBadge($displayState['status_label'], $displayState['badge_class']);
    $assigneeName = $shift['assignee_name'] ?? '';
    $assigneeTypeLabel = $shift['assignee_type'] === 'manager' ? '店長' : '従業員';
    $showApprovalButton = $shift['leave_request_id'] !== null
        && in_array($shift['leave_status'], ['matching', 'no_candidate', 'replacement_pending'], true);

    ob_start();
    ?>
    <table>
        <tbody>
            <tr><th>勤務日</th><td><?php echo htmlspecialchars($shift['shift_date']); ?></td></tr>
            <tr><th>時間</th><td><?php echo htmlspecialchars($timeRange); ?></td></tr>
            <tr><th>担当者</th><td><?php echo htmlspecialchars($assigneeName); ?></td></tr>
            <tr><th>区分</th><td><?php echo htmlspecialchars($assigneeTypeLabel); ?></td></tr>
            <tr><th>担当業務・ポジション</th><td><?php echo htmlspecialchars($shift['position'] ?? ''); ?></td></tr>
            <tr><th>備考</th><td><?php echo nl2br(htmlspecialchars($shift['note'] ?? '')); ?></td></tr>
            <tr><th>状態</th><td><?php echo $statusHtml; ?></td></tr>
        </tbody>
    </table>
    <?php if ($showApprovalButton): ?>
        <div class="notification-detail-actions">
            <a class="btn" href="approvals.php#lr-<?php echo (int) $shift['leave_request_id']; ?>">承認画面で確認する</a>
        </div>
    <?php endif; ?>
    <form method="post" action="shifts.php">
        <input type="hidden" name="action" value="cancel_shift">
        <input type="hidden" name="shift_id" value="<?php echo (int) $shift['id']; ?>">
        <button type="submit" class="btn btn-secondary">無効化</button>
    </form>
    <?php
    $detailHtml = ob_get_clean();

    $eventClass = $displayState['event_class'];

    $assigneeKey = $shift['assignee_type'] === 'manager'
        ? 'manager:' . (int) $shift['manager_user_id']
        : 'employee:' . (int) $shift['employee_id'];
    $shiftDate = $shift['shift_date'];
    $shiftTableRows[$assigneeKey][$shiftDate][] = [
        'id'          => (int) $shift['id'],
        'employee'    => $assigneeName,
        'time_range'  => $timeRange,
        'position'    => $position,
        'class'       => $eventClass,
        'detail_html' => $detailHtml,
        'title'       => $assigneeName . ' ' . $shiftDate . ' ' . $timeRange,
    ];

    if (shouldShowRequesterLeaveTableEvent($shift)) {
        $requesterName = $shift['requester_employee_name'] ?? '休み申請者';
        $requesterEventState = buildShiftTableRequesterEventState($shift, 'manager');
        $requesterEventLabel = $requesterEventState['status_label'];
        $leaveApprovedStatusHtml = renderStatusBadge($requesterEventLabel, $requesterEventState['badge_class']);

        ob_start();
        ?>
        <table>
            <tbody>
                <tr><th>勤務日</th><td><?php echo htmlspecialchars($shift['shift_date']); ?></td></tr>
                <tr><th>時間</th><td><?php echo htmlspecialchars($timeRange); ?></td></tr>
                <tr><th>休み申請者</th><td><?php echo htmlspecialchars($requesterName); ?></td></tr>
                <tr><th>現在の担当者</th><td><?php echo htmlspecialchars($assigneeName); ?></td></tr>
                <tr><th>状態</th><td><?php echo $leaveApprovedStatusHtml; ?></td></tr>
            </tbody>
        </table>
        <?php if ($requesterEventState['is_replacement_pending'] && $shift['leave_request_id'] !== null): ?>
            <div class="notification-detail-actions">
                <a class="btn" href="approvals.php#lr-<?php echo (int) $shift['leave_request_id']; ?>">承認画面で確認する</a>
            </div>
        <?php endif; ?>
        <?php
        $leaveApprovedDetailHtml = ob_get_clean();

        $requesterKey = 'employee:' . (int) $shift['requester_employee_id'];
        $shiftTableRows[$requesterKey][$shiftDate][] = [
            'id'          => 'leave-approved-' . (int) $shift['id'],
            'employee'    => $requesterName,
            'time_range'  => $timeRange,
            'position'    => $requesterEventLabel,
            'class'       => $requesterEventState['event_class'],
            'detail_html' => $leaveApprovedDetailHtml,
            'title'       => $requesterName . ' ' . $shiftDate . ' ' . $timeRange . ' ' . $requesterEventLabel,
        ];
    }
}

$tablePeople = [];
$tablePersonKeys = [];
foreach ($activeManagers as $manager) {
    $personKey = 'manager:' . (int) $manager['id'];
    $tablePeople[] = [
        'key'        => $personKey,
        'name'       => $manager['name'],
        'role_label' => ((int) $manager['id'] === $managerId) ? '店長（あなた）' : '店長',
    ];
    $tablePersonKeys[$personKey] = true;
}
foreach ($activeEmployees as $employee) {
    $personKey = 'employee:' . (int) $employee['id'];
    $tablePeople[] = [
        'key'        => $personKey,
        'name'       => $employee['name'],
        'role_label' => '従業員',
    ];
    $tablePersonKeys[$personKey] = true;
}
foreach ($shifts as $shift) {
    $personKey = $shift['assignee_type'] === 'manager'
        ? 'manager:' . (int) $shift['manager_user_id']
        : 'employee:' . (int) $shift['employee_id'];
    if (!isset($tablePersonKeys[$personKey])) {
        $tablePeople[] = [
            'key'        => $personKey,
            'name'       => $shift['assignee_name'],
            'role_label' => $shift['assignee_type'] === 'manager' ? '店長' : '従業員',
        ];
        $tablePersonKeys[$personKey] = true;
    }
}

$weekDays = [];
for ($i = 0; $i < 7; $i++) {
    $weekDays[] = $weekStart->modify('+' . $i . ' days');
}
$weekdays = [1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土', 7 => '日'];
$todayDate = date('Y-m-d');
$weekLabel = $weekStart->format('Y年n月j日') . '〜' . $weekStart->modify('+6 days')->format('n月j日');

$availabilityByDate = [];
foreach ($availabilityRows as $availability) {
    $availabilityByDate[$availability['available_date']][] = [
        'date'          => $availability['available_date'],
        'employee_name' => $availability['employee_name'],
        'start_time'    => formatCalendarTime($availability['start_time']),
        'end_time'      => formatCalendarTime($availability['end_time']),
        'note'          => $availability['note'] ?? '',
    ];
}

$availabilityByEmployee = [];
foreach ($activeEmployees as $employee) {
    $availabilityByEmployee[(string) $employee['id']] = [];
}
foreach ($availabilityRows as $availability) {
    $employeeId = (string) $availability['employee_id'];
    $availabilityByEmployee[$employeeId][] = [
        'date'          => $availability['available_date'],
        'employee_name' => $availability['employee_name'],
        'start_time'    => formatCalendarTime($availability['start_time']),
        'end_time'      => formatCalendarTime($availability['end_time']),
        'note'          => $availability['note'] ?? '',
    ];
}

ob_start();
?>
<button
    type="button"
    class="calendar-add-button"
    data-calendar-title="<?php echo $manualSubstituteRequest !== null ? '手動対応の代勤シフト登録' : 'シフトの新規作成'; ?>"
    data-calendar-detail="shift-create-form-detail"
    data-calendar-modal-class="calendar-modal-wide"
>
    <span class="calendar-add-plus">＋</span> シフトを作成
</button>
<div id="shift-create-form-detail" class="calendar-detail-source" hidden>
    <?php if (empty($activeEmployees) && empty($activeManagers)): ?>
        <p class="page-description">シフト登録できる担当者が登録されていません。</p>
    <?php else: ?>
    <form method="post" action="shifts.php" data-shift-create-form>
        <input type="hidden" name="action" value="create_shift">
        <?php if ($manualSubstituteRequest !== null): ?>
            <input type="hidden" name="manual_substitute_for" value="<?php echo (int) $manualSubstituteRequest['leave_request_id']; ?>">
            <p class="manager-card-note">
                <?php echo htmlspecialchars($manualSubstituteRequest['requester_name']); ?>さんの休み申請に対する代勤シフトとして登録します。
            </p>
        <?php endif; ?>
        <div class="shift-create-layout">
            <div class="shift-create-fields">
                <div class="form-group">
                    <label for="shift_assignee_key">担当者</label>
                    <select id="shift_assignee_key" name="assignee_key">
                        <option value="">選択してください</option>
                        <?php if (!empty($activeEmployees)): ?>
                        <optgroup label="従業員">
                            <?php foreach ($activeEmployees as $emp): ?>
                            <?php $optionValue = 'employee:' . (int) $emp['id']; ?>
                            <option value="<?php echo htmlspecialchars($optionValue); ?>" <?php echo ($optionValue === (string) $newShiftForm['assignee_key']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($emp['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                        <?php if (!empty($activeManagers)): ?>
                        <optgroup label="店長">
                            <?php foreach ($activeManagers as $manager): ?>
                            <?php $optionValue = 'manager:' . (int) $manager['id']; ?>
                            <option value="<?php echo htmlspecialchars($optionValue); ?>" <?php echo ($optionValue === (string) $newShiftForm['assignee_key']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($manager['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="shift_date">勤務日</label>
                    <input type="date" id="shift_date" name="shift_date" value="<?php echo htmlspecialchars($newShiftForm['shift_date']); ?>" <?php echo $manualSubstituteRequest !== null ? 'readonly' : ''; ?>>
                </div>
                <div class="form-group">
                    <label for="shift_start_time">開始時刻</label>
                    <input type="time" id="shift_start_time" name="start_time" value="<?php echo htmlspecialchars($newShiftForm['start_time']); ?>" <?php echo $manualSubstituteRequest !== null ? 'readonly' : ''; ?>>
                </div>
                <div class="form-group">
                    <label for="shift_end_time">終了時刻</label>
                    <input type="time" id="shift_end_time" name="end_time" value="<?php echo htmlspecialchars($newShiftForm['end_time']); ?>" <?php echo $manualSubstituteRequest !== null ? 'readonly' : ''; ?>>
                </div>
                <div class="form-group">
                    <label for="shift_position">担当業務・ポジション</label>
                    <input type="text" id="shift_position" name="position" placeholder="例：ホール、キッチン" value="<?php echo htmlspecialchars($newShiftForm['position']); ?>">
                </div>
                <div class="form-group">
                    <label for="shift_note">備考</label>
                    <textarea id="shift_note" name="note" rows="3"><?php echo htmlspecialchars($newShiftForm['note']); ?></textarea>
                </div>
                <button type="submit" class="btn">登録する</button>
            </div>

            <aside class="shift-availability-check" data-shift-availability-check hidden>
                <p class="shift-availability-title" data-shift-availability-title>全従業員の勤務可能日</p>
                <p class="shift-availability-message" data-shift-availability-message hidden></p>
                <div class="shift-availability-calendar" data-shift-availability-calendar></div>
                <div class="shift-selected-availability" data-shift-selected-availability></div>
            </aside>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php
$shiftToolbarHtml = ob_get_clean();

require_once __DIR__ . '/../../app/includes/header.php';
?>

<div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
<div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>

<div class="section">
    <h2>シフト一覧</h2>
    <div class="calendar-legend">
        <span class="calendar-legend-item"><span class="calendar-legend-swatch calendar-event-shift"></span>通常シフト</span>
        <span class="calendar-legend-item"><span class="calendar-legend-swatch calendar-event-warning"></span>要対応</span>
        <span class="calendar-legend-item"><span class="calendar-legend-swatch calendar-event-substituted"></span>代勤シフト</span>
        <span class="calendar-legend-item"><span class="calendar-legend-swatch calendar-event-leave-approved"></span>休み承認済み</span>
        <span class="calendar-legend-item"><span class="calendar-legend-swatch calendar-event-no-candidate"></span>候補者なし</span>
    </div>
    <div id="calendar-ui" class="calendar-controls" data-calendar-preserve-scroll>
        <div class="calendar-month-nav">
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars(calendarUrl('shifts.php', $previousWeekStart->format('Y-m'), $calendarView, $previousWeekStart->format('Y-m-d'))); ?>">← 前の週</a>
            <span class="shift-week-label"><?php echo htmlspecialchars($weekLabel); ?></span>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars(calendarUrl('shifts.php', $nextWeekStart->format('Y-m'), $calendarView, $nextWeekStart->format('Y-m-d'))); ?>">次の週 →</a>
        </div>
        <?php if ($shiftToolbarHtml !== ''): ?>
        <div class="calendar-toolbar">
            <?php echo $shiftToolbarHtml; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="shift-table-wrapper shift-table-week-wrapper">
        <table class="shift-table shift-table-week">
            <thead>
                <tr>
                    <th class="shift-table-corner">担当者</th>
                    <?php foreach ($weekDays as $day): ?>
                        <?php
                        $dateText = $day->format('Y-m-d');
                        $weekdayIndex = (int) $day->format('w');
                        $weekdayLabelIndex = (int) $day->format('N');
                        $dayClasses = ['shift-table-day'];
                        if ($weekdayIndex === 0) {
                            $dayClasses[] = 'is-sunday';
                        } elseif ($weekdayIndex === 6) {
                            $dayClasses[] = 'is-saturday';
                        }
                        if ($dateText === $todayDate) {
                            $dayClasses[] = 'is-today';
                        }
                        ?>
                        <th class="<?php echo htmlspecialchars(implode(' ', $dayClasses)); ?>">
                            <span><?php echo htmlspecialchars($day->format('j')); ?></span>
                            <small><?php echo htmlspecialchars($weekdays[$weekdayLabelIndex]); ?></small>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tablePeople)): ?>
                    <tr>
                        <th class="shift-table-employee">未登録</th>
                        <td class="shift-table-empty-row" colspan="<?php echo count($weekDays); ?>">表示できる担当者がいません。</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tablePeople as $person): ?>
                        <?php $personKey = (string) $person['key']; ?>
                        <tr>
                            <th class="shift-table-employee">
                                <span class="shift-table-person-name"><?php echo htmlspecialchars($person['name']); ?></span>
                                <small><?php echo htmlspecialchars($person['role_label']); ?></small>
                            </th>
                            <?php foreach ($weekDays as $day): ?>
                                <?php
                                $dateText = $day->format('Y-m-d');
                                $weekdayIndex = (int) $day->format('w');
                                $cellClasses = ['shift-table-cell'];
                                if ($weekdayIndex === 0) {
                                    $cellClasses[] = 'is-sunday';
                                } elseif ($weekdayIndex === 6) {
                                    $cellClasses[] = 'is-saturday';
                                }
                                if ($dateText === $todayDate) {
                                    $cellClasses[] = 'is-today';
                                }
                                $dayShifts = $shiftTableRows[$personKey][$dateText] ?? [];
                                $visibleDayShifts = array_slice($dayShifts, 0, 3);
                                $hiddenShiftCount = max(0, count($dayShifts) - count($visibleDayShifts));
                                $cellDetailId = 'shift-cell-detail-' . md5($personKey . '-' . $dateText);
                                if (count($dayShifts) === 1) {
                                    $cellClasses[] = 'is-single-shift';
                                }
                                if (!empty($dayShifts)) {
                                    $cellClasses[] = 'is-clickable';
                                }
                                ?>
                                <td
                                    class="<?php echo htmlspecialchars(implode(' ', $cellClasses)); ?>"
                                    <?php if (!empty($dayShifts)): ?>
                                    data-calendar-title="<?php echo htmlspecialchars($person['name'] . ' ' . $dateText . ' のシフト一覧'); ?>"
                                    data-calendar-detail="<?php echo htmlspecialchars($cellDetailId); ?>"
                                    <?php endif; ?>
                                >
                                    <?php if (empty($dayShifts)): ?>
                                        <span class="shift-table-empty">-</span>
                                    <?php else: ?>
                                        <?php foreach ($visibleDayShifts as $shiftItem): ?>
                                            <?php $detailId = 'shift-detail-' . md5((string) $shiftItem['id'] . '-' . $personKey . '-' . $dateText); ?>
                                            <button
                                                type="button"
                                                class="shift-table-shift <?php echo htmlspecialchars($shiftItem['class']); ?>"
                                                data-calendar-title="<?php echo htmlspecialchars($shiftItem['title']); ?>"
                                                data-calendar-detail="<?php echo htmlspecialchars($detailId); ?>"
                                            >
                                                <?php echo htmlspecialchars($shiftItem['time_range']); ?>
                                            </button>
                                            <div id="<?php echo htmlspecialchars($detailId); ?>" class="calendar-detail-source" hidden>
                                                <?php echo $shiftItem['detail_html']; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if ($hiddenShiftCount > 0): ?>
                                            <button
                                                type="button"
                                                class="shift-table-more-button"
                                                data-calendar-title="<?php echo htmlspecialchars($person['name'] . ' ' . $dateText . ' のシフト一覧'); ?>"
                                                data-calendar-detail="<?php echo htmlspecialchars($cellDetailId); ?>"
                                            >
                                                他<?php echo (int) $hiddenShiftCount; ?>件
                                            </button>
                                        <?php endif; ?>
                                        <div id="<?php echo htmlspecialchars($cellDetailId); ?>" class="calendar-detail-source" hidden>
                                            <?php foreach ($dayShifts as $shiftItem): ?>
                                            <div class="calendar-day-detail-item">
                                                <h4><?php echo htmlspecialchars($shiftItem['title']); ?></h4>
                                                <?php echo $shiftItem['detail_html']; ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="calendar-modal" data-calendar-modal hidden>
    <div class="calendar-modal-backdrop" data-calendar-close></div>
    <div class="calendar-modal-panel" role="dialog" aria-modal="true" aria-labelledby="calendar-modal-title">
        <button type="button" class="calendar-modal-close" data-calendar-close>×</button>
        <h3 id="calendar-modal-title" data-calendar-modal-title>詳細</h3>
        <div class="calendar-modal-body" data-calendar-modal-body></div>
    </div>
</div>

<script type="application/json" id="shift-availability-data">
<?php
echo json_encode(
    [
        'byEmployee' => $availabilityByEmployee,
        'byDate'     => $availabilityByDate,
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const dataElement = document.getElementById('shift-availability-data');
    const availabilityData = dataElement ? JSON.parse(dataElement.textContent || '{}') : {};
    const availabilityByEmployee = availabilityData.byEmployee || {};
    const availabilityByDate = availabilityData.byDate || {};
    const shouldOpenShiftCreateModal = <?php echo $openShiftCreateModal ? 'true' : 'false'; ?>;
    const modal = document.querySelector('[data-calendar-modal]');
    const modalPanel = modal ? modal.querySelector('.calendar-modal-panel') : null;
    const modalTitle = modal ? modal.querySelector('[data-calendar-modal-title]') : null;
    const modalBody = modal ? modal.querySelector('[data-calendar-modal-body]') : null;
    const scrollKey = 'manager-shifts-scroll-position';

    const savedScroll = sessionStorage.getItem(scrollKey);
    if (savedScroll !== null) {
        window.scrollTo(0, Number(savedScroll));
        sessionStorage.removeItem(scrollKey);
    }

    const openCalendarModal = function (button) {
        if (!modal || !modalPanel || !modalTitle || !modalBody) {
            return;
        }

        const detail = document.getElementById(button.dataset.calendarDetail || '');
        if (!detail) {
            return;
        }

        modalTitle.textContent = button.dataset.calendarTitle || '詳細';
        modalBody.innerHTML = detail.innerHTML;
        modalPanel.classList.remove('calendar-modal-wide');
        if (button.dataset.calendarModalClass) {
            modalPanel.classList.add(button.dataset.calendarModalClass);
        }
        modal.hidden = false;
        document.body.classList.add('calendar-modal-open');
    };

    const closeCalendarModal = function () {
        if (!modal) {
            return;
        }

        modal.hidden = true;
        document.body.classList.remove('calendar-modal-open');
    };

    document.addEventListener('click', function (event) {
        const detailButton = event.target.closest('[data-calendar-detail]');
        if (detailButton) {
            openCalendarModal(detailButton);
            return;
        }

        if (event.target.closest('[data-calendar-close]')) {
            closeCalendarModal();
            return;
        }

        const calendarLink = event.target.closest('[data-calendar-preserve-scroll] a');
        if (calendarLink) {
            sessionStorage.setItem(scrollKey, String(window.scrollY));
        }
    });

    document.addEventListener('submit', function () {
        sessionStorage.setItem(scrollKey, String(window.scrollY));
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeCalendarModal();
        }
    });

    const toMinutes = function (time) {
        if (!time || !time.includes(':')) {
            return null;
        }

        const parts = time.split(':');
        return (Number(parts[0]) * 60) + Number(parts[1]);
    };

    const setMessage = function (messageElement, text, status) {
        messageElement.hidden = false;
        messageElement.textContent = text;
        messageElement.className = 'shift-availability-message is-' + status;
    };

    const clearMessage = function (messageElement) {
        messageElement.hidden = true;
        messageElement.textContent = '';
        messageElement.className = 'shift-availability-message';
    };

    const formatMonthLabel = function (month) {
        const parts = month.split('-');
        return Number(parts[0]) + '年' + Number(parts[1]) + '月';
    };

    const addMonths = function (month, diff) {
        const parts = month.split('-');
        const date = new Date(Number(parts[0]), Number(parts[1]) - 1 + diff, 1);
        return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');
    };

    const flattenAvailabilityByDate = function () {
        return Object.values(availabilityByDate).flat();
    };

    const getInitialMonth = function (rows, selectedDate) {
        if (selectedDate !== '') {
            return selectedDate.slice(0, 7);
        }

        if (rows.length > 0) {
            return rows[0].date.slice(0, 7);
        }

        const today = new Date();
        return today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0');
    };

    const renderAvailabilityCalendar = function (calendarElement, rows, selectedDate, month) {
        const availableDates = new Set(rows.map(function (row) {
            return row.date;
        }));
        const parts = month.split('-');
        const year = Number(parts[0]);
        const monthIndex = Number(parts[1]) - 1;
        const firstDay = new Date(year, monthIndex, 1);
        const start = new Date(year, monthIndex, 1 - firstDay.getDay());
        const weekdays = ['日', '月', '火', '水', '木', '金', '土'];
        let dayHtml = '';

        for (let i = 0; i < 42; i++) {
            const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
            const dateText = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
            const classes = ['shift-mini-calendar-day'];
            if (date.getMonth() !== monthIndex) {
                classes.push('is-outside-month');
            }
            if (availableDates.has(dateText)) {
                classes.push('is-available');
            }
            if (selectedDate === dateText) {
                classes.push('is-selected');
            }

            dayHtml += '<button type="button" class="' + classes.join(' ') + '" data-shift-calendar-date="' + escapeHtml(dateText) + '">'
                + date.getDate()
                + '</button>';
        }

        calendarElement.innerHTML = ''
            + '<div class="shift-mini-calendar-header">'
            + '<button type="button" class="shift-mini-calendar-nav" data-shift-month-offset="-1">‹</button>'
            + '<strong>' + escapeHtml(formatMonthLabel(month)) + '</strong>'
            + '<button type="button" class="shift-mini-calendar-nav" data-shift-month-offset="1">›</button>'
            + '</div>'
            + '<div class="shift-mini-calendar-weekdays">' + weekdays.map(function (weekday) {
                return '<span>' + weekday + '</span>';
            }).join('') + '</div>'
            + '<div class="shift-mini-calendar-days">' + dayHtml + '</div>';
    };

    const renderSelectedAvailability = function (detailElement, dateRows, selectedDate, showEmployeeName) {
        if (selectedDate === '') {
            detailElement.innerHTML = '';
            return;
        }

        if (dateRows.length === 0) {
            detailElement.innerHTML = '<p>登録なし</p>';
            return;
        }

        detailElement.innerHTML = '<p>選択日の勤務可能時間</p><ul>'
            + dateRows.map(function (row) {
                const note = row.note ? '<span>' + escapeHtml(row.note) + '</span>' : '';
                const mainText = showEmployeeName
                    ? row.employee_name + '　' + row.start_time + '-' + row.end_time
                    : row.start_time + '-' + row.end_time;
                return '<li><strong>' + escapeHtml(mainText) + '</strong>' + note + '</li>';
            }).join('')
            + '</ul>';
    };

    const escapeHtml = function (value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    };

    const updateShiftAvailabilityCheck = function () {
        const form = document.querySelector('[data-calendar-modal]:not([hidden]) [data-shift-create-form]');
        if (!form) {
            return;
        }

        const assigneeKey = form.querySelector('[name="assignee_key"]')?.value || '';
        const isEmployeeAssignee = assigneeKey.startsWith('employee:');
        const isManagerAssignee = assigneeKey.startsWith('manager:');
        const employeeId = isEmployeeAssignee ? assigneeKey.replace('employee:', '') : '';
        const shiftDate = form.querySelector('[name="shift_date"]')?.value || '';
        const startTime = form.querySelector('[name="start_time"]')?.value || '';
        const endTime = form.querySelector('[name="end_time"]')?.value || '';
        const messageElement = form.querySelector('[data-shift-availability-message]');
        const calendarElement = form.querySelector('[data-shift-availability-calendar]');
        const selectedElement = form.querySelector('[data-shift-selected-availability]');
        const checkElement = form.querySelector('[data-shift-availability-check]');
        const titleElement = form.querySelector('[data-shift-availability-title]');

        if (!messageElement || !calendarElement || !selectedElement || !checkElement || !titleElement) {
            return;
        }

        checkElement.hidden = false;
        const isAllEmployees = assigneeKey === '';
        titleElement.textContent = isAllEmployees ? '全従業員の勤務可能日' : (isManagerAssignee ? '店長シフト' : '選択中の従業員の勤務可能日');
        if (isManagerAssignee) {
            calendarElement.innerHTML = '';
            selectedElement.innerHTML = '';
            setMessage(messageElement, '店長は勤務可能日チェック対象外です。', 'success');
            return;
        }
        const rows = isAllEmployees ? flattenAvailabilityByDate() : (availabilityByEmployee[employeeId] || []);
        const lastShiftDate = form.dataset.lastShiftDate || '';
        const lastAssigneeKey = form.dataset.lastAssigneeKey || '';
        if (assigneeKey !== lastAssigneeKey) {
            checkElement.dataset.calendarMonth = '';
        }
        if (!checkElement.dataset.calendarMonth || (shiftDate !== '' && shiftDate !== lastShiftDate)) {
            checkElement.dataset.calendarMonth = getInitialMonth(rows, shiftDate);
        }
        form.dataset.lastAssigneeKey = assigneeKey;
        form.dataset.lastShiftDate = shiftDate;
        const calendarMonth = checkElement.dataset.calendarMonth;
        renderAvailabilityCalendar(calendarElement, rows, shiftDate, calendarMonth);

        if (rows.length === 0) {
            selectedElement.innerHTML = '';
            setMessage(messageElement, '勤務可能日なし', 'warning');
            return;
        }

        const dateRows = shiftDate === ''
            ? []
            : (isAllEmployees ? (availabilityByDate[shiftDate] || []) : rows.filter(function (row) {
                return row.date === shiftDate;
            }));
        renderSelectedAvailability(selectedElement, dateRows, shiftDate, isAllEmployees);

        if (shiftDate === '') {
            clearMessage(messageElement);
            return;
        }

        if (dateRows.length === 0) {
            setMessage(messageElement, 'この日は未登録です。', 'warning');
            return;
        }

        if (startTime === '' || endTime === '') {
            setMessage(messageElement, isAllEmployees ? '勤務可能者あり' : 'この日は勤務可能です。', 'success');
            return;
        }

        const startMinutes = toMinutes(startTime);
        const endMinutes = toMinutes(endTime);
        const isCovered = dateRows.some(function (row) {
            const availableStart = toMinutes(row.start_time);
            const availableEnd = toMinutes(row.end_time);
            return availableStart !== null
                && availableEnd !== null
                && startMinutes !== null
                && endMinutes !== null
                && availableStart <= startMinutes
                && availableEnd >= endMinutes;
        });

        if (isCovered) {
            setMessage(messageElement, '勤務可能です。', 'success');
        } else {
            setMessage(messageElement, '勤務可能時間外です。', 'warning');
        }
    };

    document.addEventListener('input', function (event) {
        if (event.target.closest('[data-shift-create-form]')) {
            updateShiftAvailabilityCheck();
        }
    });

    document.addEventListener('change', function (event) {
        if (event.target.closest('[data-shift-create-form]')) {
            updateShiftAvailabilityCheck();
        }
    });

    document.addEventListener('click', function (event) {
        const monthButton = event.target.closest('[data-shift-month-offset]');
        if (monthButton) {
            const form = monthButton.closest('[data-shift-create-form]');
            const checkElement = form?.querySelector('[data-shift-availability-check]');
            if (checkElement) {
                const currentMonth = checkElement.dataset.calendarMonth || getInitialMonth([], '');
                checkElement.dataset.calendarMonth = addMonths(currentMonth, Number(monthButton.dataset.shiftMonthOffset));
                updateShiftAvailabilityCheck();
            }
            return;
        }

        const dateButton = event.target.closest('[data-shift-calendar-date]');
        if (dateButton) {
            const form = dateButton.closest('[data-shift-create-form]');
            const dateInput = form?.querySelector('[name="shift_date"]');
            if (dateInput) {
                dateInput.value = dateButton.dataset.shiftCalendarDate;
                dateInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
            return;
        }

        const button = event.target.closest('[data-calendar-detail="shift-create-form-detail"]');
        if (button) {
            window.setTimeout(updateShiftAvailabilityCheck, 0);
        }
    });

    if (shouldOpenShiftCreateModal) {
        const createButton = document.querySelector('[data-calendar-detail="shift-create-form-detail"]');
        if (createButton) {
            createButton.click();
        }
    }
});
</script>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
