<?php
/**
 * 勤務可能日登録・シフト確認画面（従業員用）
 *
 * 従来の3画面（シフト確認・勤務可能日登録・休み申請）を1つのカレンダーに統合したもの。
 *
 * - カレンダー右上の「＋ 勤務可能日を登録」ボタンから、勤務可能日をポップアップ登録できる
 * - 登録済み勤務可能日は、勤務可能日登録ポップアップ内のミニカレンダーで確認・削除できる
 * - カレンダー内の自分のシフト（予定）を開くと、休み申請ができる
 * - 休み申請中（店長処理前）のシフトを開くと、申請をキャンセルできる
 * - 承認済みで代勤担当になっているシフトを開くと、代勤キャンセル申請を送れる
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('employee');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/services/substitute_matcher.php';
require_once __DIR__ . '/../../app/services/cancellation_request_service.php';
require_once __DIR__ . '/../../app/includes/status_labels.php';
require_once __DIR__ . '/../../app/includes/calendar_ui.php';
require_once __DIR__ . '/../../app/includes/shift_table_helpers.php';
require_once __DIR__ . '/../../app/includes/schema_helpers.php';

$pageTitle = '勤務可能日登録・シフト確認';
$basePath  = '../../public/';

$user       = currentUser();
$employeeId = (int) $user['employee_id'];

$errorMessage   = '';
$successMessage = '';

$calendarMonth = getCalendarMonth();
$focusDateText = (string) ($_GET['calendar_date'] ?? date('Y-m-d'));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $focusDateText) !== 1) {
    $focusDateText = date('Y-m-d');
}
$focusDate = new DateTimeImmutable($focusDateText);
$weekStart = $focusDate->modify('-' . ((int) $focusDate->format('N') - 1) . ' days');
$previousWeekStart = $weekStart->modify('-7 days');
$nextWeekStart = $weekStart->modify('+7 days');
$calendarMonth = $weekStart->format('Y-m');
$calendarView  = 'week';
$formActionUrl = 'shifts.php?' . http_build_query([
    'calendar_month' => $calendarMonth,
    'calendar_view'  => $calendarView,
    'calendar_date'  => $weekStart->format('Y-m-d'),
]);

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

/**
 * 現在のカレンダー月を維持したまま、完了メッセージ付きでこの画面へ戻る。
 */
function redirectToCalendar(string $formActionUrl, string $msg): void
{
    $sep = str_contains($formActionUrl, '?') ? '&' : '?';
    header('Location: ' . $formActionUrl . $sep . 'msg=' . urlencode($msg));
    exit;
}

// ------------------------------------------------------------
// POST処理
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_availability') {
        $availableDate = trim($_POST['available_date'] ?? '');
        $startTime     = trim($_POST['start_time'] ?? '');
        $endTime       = trim($_POST['end_time'] ?? '');
        $note          = trim($_POST['note'] ?? '');

        if ($availableDate === '' || $startTime === '' || $endTime === '') {
            $errorMessage = '勤務可能日・開始時刻・終了時刻は必須です。';
        } elseif ($startTime >= $endTime) {
            $errorMessage = '開始時刻は終了時刻より前にしてください。';
        } else {
            // 同じ日付で時間帯が重複する登録を防ぐ。
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM availability
                 WHERE employee_id = :employee_id
                   AND available_date = :available_date
                   AND NOT (end_time <= :start_time OR start_time >= :end_time)'
            );
            $stmt->execute([
                'employee_id'    => $employeeId,
                'available_date' => $availableDate,
                'start_time'     => $startTime,
                'end_time'       => $endTime,
            ]);

            if ((int) $stmt->fetchColumn() > 0) {
                $errorMessage = '同じ日付の時間帯と重複する勤務可能日が既に登録されています。';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO availability (employee_id, available_date, start_time, end_time, note, created_by)
                     VALUES (:employee_id, :available_date, :start_time, :end_time, :note, :created_by)'
                );
                $stmt->execute([
                    'employee_id'    => $employeeId,
                    'available_date' => $availableDate,
                    'start_time'     => $startTime,
                    'end_time'       => $endTime,
                    'note'           => $note !== '' ? $note : null,
                    'created_by'     => (int) $user['id'],
                ]);

                redirectToCalendar($formActionUrl, 'availability_created');
            }
        }
    } elseif ($action === 'delete_availability') {
        $id = (int) ($_POST['id'] ?? 0);

        // employee_id を条件に含め、他人の勤務可能日を削除できないようにする。
        $pdo->prepare('DELETE FROM availability WHERE id = :id AND employee_id = :employee_id')
            ->execute(['id' => $id, 'employee_id' => $employeeId]);

        redirectToCalendar($formActionUrl, 'availability_deleted');
    } elseif ($action === 'create_leave') {
        $shiftId = (int) ($_POST['shift_id'] ?? 0);
        $reason  = trim($_POST['reason'] ?? '');

        if ($shiftId <= 0) {
            $errorMessage = '対象シフトを確認できませんでした。';
        } else {
            // 自分のシフトかつ申請可能な状態（予定）であることを確認する
            $stmt = $pdo->prepare(
                "SELECT id FROM shifts WHERE id = :id AND employee_id = :employee_id AND status = 'scheduled'"
            );
            $stmt->execute(['id' => $shiftId, 'employee_id' => $employeeId]);

            if ($stmt->fetch() === false) {
                $errorMessage = '指定されたシフトは休み申請の対象になっていません。';
            } else {
                // 同じシフトに対する休み申請の重複を防ぐ
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM leave_requests
                     WHERE shift_id = :shift_id AND status IN ('pending', 'matching', 'no_candidate')"
                );
                $stmt->execute(['shift_id' => $shiftId]);

                if ((int) $stmt->fetchColumn() > 0) {
                    $errorMessage = 'このシフトは既に休み申請済みです。';
                } else {
                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare(
                            "INSERT INTO leave_requests (shift_id, employee_id, reason, status)
                             VALUES (:shift_id, :employee_id, :reason, 'pending')"
                        );
                        $stmt->execute([
                            'shift_id'    => $shiftId,
                            'employee_id' => $employeeId,
                            'reason'      => $reason !== '' ? $reason : null,
                        ]);
                        $leaveRequestId = (int) $pdo->lastInsertId();

                        $pdo->prepare("UPDATE shifts SET status = 'leave_requested' WHERE id = :id")
                            ->execute(['id' => $shiftId]);

                        // 代勤候補抽出・通知作成・休み申請ステータス更新
                        processSubstituteMatching($pdo, $leaveRequestId);

                        $pdo->commit();
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        throw $e;
                    }

                    redirectToCalendar($formActionUrl, 'leave_created');
                }
            }
        }
    } elseif ($action === 'cancel_leave') {
        $leaveRequestId = (int) ($_POST['leave_request_id'] ?? 0);

        if ($leaveRequestId <= 0) {
            $errorMessage = 'キャンセルする休み申請を確認できませんでした。';
        } else {
            $cancelResult = cancelLeaveRequest($pdo, $leaveRequestId, $employeeId);

            if ($cancelResult === 'cancelled') {
                redirectToCalendar($formActionUrl, 'leave_cancelled');
            }

            if ($cancelResult === 'not_found') {
                $errorMessage = '指定された休み申請が見つかりません。自分の申請のみキャンセルできます。';
            } else {
                $errorMessage = 'この休み申請は、既に処理済みのためキャンセルできません。';
            }
        }
    } elseif ($action === 'request_after_approval_cancel') {
        $leaveRequestId = (int) ($_POST['leave_request_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if ($leaveRequestId <= 0) {
            $errorMessage = '対象の休み申請を確認できませんでした。';
        } else {
            $result = createAfterApprovalCancellationRequest(
                $pdo,
                $leaveRequestId,
                $employeeId,
                $reason
            );

            if ($result === 'created') {
                redirectToCalendar($formActionUrl, 'after_approval_cancel_requested');
            }
            if ($result === 'already_pending') {
                $errorMessage = 'この休み申請は、既にキャンセル申請中です。';
            } elseif ($result === 'not_found') {
                $errorMessage = '指定された休み申請が見つかりません。自分の申請のみ操作できます。';
            } else {
                $errorMessage = 'この休み申請は、承認後キャンセル申請の対象ではありません。';
            }
        }
    } elseif ($action === 'request_substitute_cancel') {
        $leaveRequestId = (int) ($_POST['leave_request_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if ($leaveRequestId <= 0) {
            $errorMessage = '対象の代勤シフトを確認できませんでした。';
        } else {
            $result = createSubstituteAfterApprovalCancellationRequest(
                $pdo,
                $leaveRequestId,
                $employeeId,
                $reason
            );

            if ($result === 'created') {
                redirectToCalendar($formActionUrl, 'substitute_cancel_requested');
            }
            if ($result === 'already_pending') {
                $errorMessage = 'この代勤は、既にキャンセル申請中です。';
            } elseif ($result === 'not_found') {
                $errorMessage = '指定された代勤シフトが見つかりません。自分の代勤のみ操作できます。';
            } else {
                $errorMessage = 'この代勤は、キャンセル申請の対象ではありません。';
            }
        }
    }
}

// ------------------------------------------------------------
// 完了メッセージ（リダイレクト後）
// ------------------------------------------------------------
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'availability_created':
            $successMessage = '勤務可能日を登録しました。';
            break;
        case 'availability_deleted':
            $successMessage = '勤務可能日を削除しました。';
            break;
        case 'leave_created':
            $successMessage = '休み申請を登録しました。代勤候補の抽出を行いました。';
            break;
        case 'leave_cancelled':
            $successMessage = '休み申請をキャンセルしました。';
            break;
        case 'substitute_cancel_requested':
            $successMessage = '代勤キャンセル申請を店長へ送信しました。店長の確認をお待ちください。';
            break;
        case 'after_approval_cancel_requested':
            $successMessage = '承認後キャンセル申請を店長へ送信しました。店長の確認をお待ちください。';
            break;
    }
}

// ------------------------------------------------------------
// シフト表に表示する担当者一覧（ログイン中従業員を最上段にする）
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT id, name
     FROM employees
     WHERE is_active = 1
     ORDER BY CASE WHEN id = :employee_id THEN 0 ELSE 1 END, id'
);
$stmt->execute(['employee_id' => $employeeId]);
$activeEmployees = $stmt->fetchAll();

$activeManagers = $pdo->query(
    "SELECT id, name FROM users WHERE role = 'manager' ORDER BY id"
)->fetchAll();

// ------------------------------------------------------------
// シフト一覧（無効化済みを除く）
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT s.*,
            e.name AS employee_name,
            mu.name AS manager_name,
            COALESCE(e.name, mu.name) AS assignee_name,
            CASE WHEN s.manager_user_id IS NOT NULL THEN 'manager' ELSE 'employee' END AS assignee_type,
            s.related_leave_request_id,
            lr.id AS leave_request_id,
            lr.employee_id AS requester_employee_id,
            requester.name AS requester_employee_name,
            lr.status AS leave_status,
            cr_requester.status AS requester_cancellation_status,
            cr_requester.reason AS requester_cancellation_reason,
            cr_substitute.status AS substitute_cancellation_status,
            cr_substitute.reason AS substitute_cancellation_reason
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
     LEFT JOIN cancellation_requests cr_requester
        ON cr_requester.id = (
            SELECT MAX(crr.id)
            FROM cancellation_requests crr
            WHERE crr.leave_request_id = lr.id
              AND crr.request_type = 'requester_after_approval'
        )
     LEFT JOIN cancellation_requests cr_substitute
        ON cr_substitute.id = (
            SELECT MAX(crs.id)
            FROM cancellation_requests crs
            WHERE crs.leave_request_id = lr.id
             AND crs.request_type = 'substitute_after_approval'
        )
     WHERE s.status <> 'cancelled'
       AND (e.id IS NOT NULL OR mu.id IS NOT NULL)
     ORDER BY s.shift_date, s.start_time"
);
$stmt->execute();
$shifts = $stmt->fetchAll();

// ------------------------------------------------------------
// 自分の勤務可能日一覧
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT * FROM availability WHERE employee_id = :employee_id ORDER BY available_date, start_time'
);
$stmt->execute(['employee_id' => $employeeId]);
$availabilityList = $stmt->fetchAll();

// ------------------------------------------------------------
// シフト表イベントの組み立て
// ------------------------------------------------------------
$shiftTableRows = [];
$tablePeople = [];
$tablePersonKeys = [];
foreach ($activeEmployees as $employee) {
    $personKey = 'employee:' . (int) $employee['id'];
    $tablePeople[] = [
        'key'        => $personKey,
        'name'       => $employee['name'],
        'role_label' => ((int) $employee['id'] === $employeeId) ? 'あなた' : '従業員',
    ];
    $tablePersonKeys[$personKey] = true;
}
foreach ($activeManagers as $manager) {
    $personKey = 'manager:' . (int) $manager['id'];
    $tablePeople[] = [
        'key'        => $personKey,
        'name'       => $manager['name'],
        'role_label' => '店長',
    ];
    $tablePersonKeys[$personKey] = true;
}

foreach ($shifts as $shift) {
    $timeRange = formatCalendarTime($shift['start_time']) . '-' . formatCalendarTime($shift['end_time']);
    $position = $shift['position'] !== null && $shift['position'] !== '' ? $shift['position'] : 'シフト';
    $isCurrentShiftEmployee = (int) $shift['employee_id'] === $employeeId;
    $isLeaveRequester = $shift['leave_request_id'] !== null && (int) $shift['requester_employee_id'] === $employeeId;
    $requesterCancellationStatus = $shift['requester_cancellation_status'] ?? null;
    $substituteCancellationStatus = $shift['substitute_cancellation_status'] ?? null;
    $hasPendingCancellation = $requesterCancellationStatus === 'pending' || $substituteCancellationStatus === 'pending';

    $displayState = buildShiftTableDisplayState(
        $shift,
        'employee',
        $hasPendingCancellation,
        $isCurrentShiftEmployee,
        $isLeaveRequester
    );
    $calendarStatusLabel = $displayState['status_label'];
    $calendarStatusBadgeClass = $displayState['badge_class'];
    $statusHtml = renderStatusBadge($calendarStatusLabel, $calendarStatusBadgeClass);

    // 休み申請ができる条件：自分の「予定」シフト
    $canRequestLeave = $isCurrentShiftEmployee && $shift['status'] === 'scheduled';

    // 店長処理前で、本人が休み申請をキャンセルできる条件
    $canCancelLeave = (
        $isLeaveRequester
        && in_array($shift['leave_status'], ['matching', 'no_candidate'], true)
    );

    // 承認後、休み申請者本人が「やっぱり出勤できる」とキャンセル申請できる条件
    $canRequestAfterApprovalCancel = (
        $isLeaveRequester
        && in_array($shift['status'], ['substituted', 'leave_approved'], true)
        && $shift['leave_status'] === 'approved'
        && ($shift['status'] === 'leave_approved' || !$isCurrentShiftEmployee)
        && $requesterCancellationStatus !== 'pending'
    );

    // 代勤キャンセル申請が出せる条件（代勤担当が確定済みで、休み申請者本人ではない）
    $canRequestSubstituteCancel = (
        $shift['status'] === 'substituted'
        && $shift['leave_request_id'] !== null
        && $shift['leave_status'] === 'approved'
        && $isCurrentShiftEmployee
        && !$isLeaveRequester
        && $substituteCancellationStatus !== 'pending'
    );

    ob_start();
    ?>
    <table>
        <tbody>
            <tr><th>勤務日</th><td><?php echo htmlspecialchars($shift['shift_date']); ?></td></tr>
            <tr><th>時間</th><td><?php echo htmlspecialchars($timeRange); ?></td></tr>
            <tr><th>担当業務・ポジション</th><td><?php echo htmlspecialchars($shift['position'] ?? ''); ?></td></tr>
            <tr><th>備考</th><td><?php echo nl2br(htmlspecialchars($shift['note'] ?? '')); ?></td></tr>
            <tr><th>状態</th><td><?php echo $statusHtml; ?></td></tr>
        </tbody>
    </table>

    <?php if ($canRequestLeave): ?>
    <form method="post" action="<?php echo htmlspecialchars($formActionUrl); ?>">
        <input type="hidden" name="action" value="create_leave">
        <input type="hidden" name="shift_id" value="<?php echo (int) $shift['id']; ?>">
        <div class="form-group">
            <label for="leave_reason_<?php echo (int) $shift['id']; ?>">休み申請理由</label>
            <textarea
                id="leave_reason_<?php echo (int) $shift['id']; ?>"
                name="reason"
                rows="2"
                placeholder="例: 通院のため"
            ></textarea>
        </div>
        <button type="submit" class="btn">休み申請をする</button>
    </form>
    <?php elseif ($canCancelLeave): ?>
        <p class="page-description">
            休み申請の状態：<?php echo renderStatusBadge($calendarStatusLabel, $calendarStatusBadgeClass); ?>
        </p>
        <p class="page-description">店長が承認・却下する前であれば、申請をキャンセルできます。</p>
        <form method="post" action="<?php echo htmlspecialchars($formActionUrl); ?>">
            <input type="hidden" name="action" value="cancel_leave">
            <input type="hidden" name="leave_request_id" value="<?php echo (int) $shift['leave_request_id']; ?>">
            <button type="submit" class="btn btn-secondary">休み申請をキャンセルする</button>
        </form>
    <?php elseif ($canRequestAfterApprovalCancel): ?>
    <form method="post" action="<?php echo htmlspecialchars($formActionUrl); ?>">
        <input type="hidden" name="action" value="request_after_approval_cancel">
        <input type="hidden" name="leave_request_id" value="<?php echo (int) $shift['leave_request_id']; ?>">
        <div class="form-group">
            <label for="after_cancel_reason_main_<?php echo (int) $shift['id']; ?>">キャンセル理由</label>
            <textarea
                id="after_cancel_reason_main_<?php echo (int) $shift['id']; ?>"
                name="reason"
                rows="2"
                placeholder="例：出勤できるようになりました"
            ></textarea>
        </div>
        <button type="submit" class="btn">承認後キャンセル申請を送る</button>
    </form>
    <?php elseif ($canRequestSubstituteCancel): ?>
    <form method="post" action="<?php echo htmlspecialchars($formActionUrl); ?>">
        <input type="hidden" name="action" value="request_substitute_cancel">
        <input type="hidden" name="leave_request_id" value="<?php echo (int) $shift['leave_request_id']; ?>">
        <div class="form-group">
            <label for="sub_cancel_reason_<?php echo (int) $shift['id']; ?>">キャンセル理由</label>
            <textarea
                id="sub_cancel_reason_<?php echo (int) $shift['id']; ?>"
                name="reason"
                rows="2"
                placeholder="例：体調不良のため対応できなくなりました"
            ></textarea>
        </div>
        <button type="submit" class="btn">代勤キャンセル申請を送る</button>
    </form>
    <?php elseif ($requesterCancellationStatus === 'pending' || $substituteCancellationStatus === 'pending'): ?>
        <?php echo renderStatusBadge('キャンセル申請中', 'badge-warning'); ?>
        <p class="page-description">店長の確認をお待ちください。</p>
    <?php elseif ($shift['status'] === 'replacement_pending'): ?>
        <?php echo renderStatusBadge('代勤キャンセル承認済み', 'badge-inactive'); ?>
        <p class="page-description">キャンセルが承認され、この代勤の担当から外れました。店長が代勤者を再調整中です。</p>
    <?php elseif ($requesterCancellationStatus === 'rejected' || $substituteCancellationStatus === 'rejected'): ?>
        <?php echo renderStatusBadge('代勤キャンセル却下済み', 'badge-danger'); ?>
        <p class="page-description">前回のキャンセル申請は却下されました。現在の代勤予定は維持されています。</p>
    <?php endif; ?>
    <?php
    $detailHtml = ob_get_clean();

    $eventClass = $displayState['event_class'];

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

    $shiftTableRows[$personKey][$shift['shift_date']][] = [
        'id'          => (int) $shift['id'],
        'title'       => $position,
        'time_range'  => $timeRange,
        'start_time'  => $shift['start_time'],
        'end_time'    => $shift['end_time'],
        'class'       => $eventClass,
        'detail_html' => $detailHtml,
    ];

    if (shouldShowRequesterLeaveTableEvent($shift)) {
        $requesterName = $shift['requester_employee_name'] ?? '休み申請者';
        $requesterEventState = buildShiftTableRequesterEventState($shift, 'employee', $hasPendingCancellation);
        $leaveApprovedStatusHtml = renderStatusBadge(
            $requesterEventState['status_label'],
            $requesterEventState['badge_class']
        );

        ob_start();
        ?>
        <table>
            <tbody>
                <tr><th>勤務日</th><td><?php echo htmlspecialchars($shift['shift_date']); ?></td></tr>
                <tr><th>時間</th><td><?php echo htmlspecialchars($timeRange); ?></td></tr>
                <tr><th>休み申請者</th><td><?php echo htmlspecialchars($requesterName); ?></td></tr>
                <tr><th>現在の担当者</th><td><?php echo htmlspecialchars($shift['assignee_name'] ?? ''); ?></td></tr>
                <tr><th>担当業務・ポジション</th><td><?php echo htmlspecialchars($shift['position'] ?? ''); ?></td></tr>
                <tr><th>状態</th><td><?php echo $leaveApprovedStatusHtml; ?></td></tr>
            </tbody>
        </table>

        <?php if ($canRequestAfterApprovalCancel): ?>
        <form method="post" action="<?php echo htmlspecialchars($formActionUrl); ?>">
            <input type="hidden" name="action" value="request_after_approval_cancel">
            <input type="hidden" name="leave_request_id" value="<?php echo (int) $shift['leave_request_id']; ?>">
            <div class="form-group">
                <label for="after_cancel_reason_<?php echo (int) $shift['id']; ?>">キャンセル理由</label>
                <textarea
                    id="after_cancel_reason_<?php echo (int) $shift['id']; ?>"
                    name="reason"
                    rows="2"
                    placeholder="例：出勤できるようになったため"
                ></textarea>
            </div>
            <button type="submit" class="btn">承認後キャンセル申請を送る</button>
        </form>
        <?php elseif ($requesterCancellationStatus === 'pending'): ?>
            <?php echo renderStatusBadge('キャンセル申請中', 'badge-warning'); ?>
            <p class="page-description">店長の確認をお待ちください。</p>
        <?php elseif ($requesterCancellationStatus === 'rejected'): ?>
            <?php echo renderStatusBadge('キャンセル却下済み', 'badge-danger'); ?>
            <p class="page-description">前回のキャンセル申請は却下されました。現在の休み予定は維持されています。</p>
        <?php elseif ($requesterEventState['is_replacement_pending']): ?>
            <p class="page-description">承認済みだった代勤者のキャンセルが承認されました。店長が新しい代勤者を再調整中です。</p>
        <?php endif; ?>
        <?php
        $leaveApprovedDetailHtml = ob_get_clean();

        $requesterKey = 'employee:' . (int) $shift['requester_employee_id'];
        $shiftTableRows[$requesterKey][$shift['shift_date']][] = [
            'id'          => 'leave-approved-' . (int) $shift['id'],
            'title'       => $requesterEventState['status_label'],
            'time_range'  => $timeRange,
            'start_time'  => $shift['start_time'],
            'end_time'    => $shift['end_time'],
            'class'       => $requesterEventState['event_class'],
            'detail_html' => $leaveApprovedDetailHtml,
        ];
    }
}

// 同じセルに複数イベントがある場合、時刻順に並べる
foreach ($shiftTableRows as &$dateRows) {
    foreach ($dateRows as &$events) {
        usort($events, function (array $a, array $b): int {
            return calendarTimeToMinutes($a['start_time'] ?? null) <=> calendarTimeToMinutes($b['start_time'] ?? null);
        });
    }
    unset($events);
}
unset($dateRows);

$weekDays = [];
for ($i = 0; $i < 7; $i++) {
    $weekDays[] = $weekStart->modify('+' . $i . ' days');
}
$weekdays = [1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土', 7 => '日'];
$todayDate = date('Y-m-d');
$weekLabel = $weekStart->format('Y年n月j日') . '〜' . $weekStart->modify('+6 days')->format('n月j日');
$availabilityCalendarRows = [];
foreach ($availabilityList as $availability) {
    $availabilityCalendarRows[] = [
        'id'         => (int) $availability['id'],
        'date'       => $availability['available_date'],
        'start_time' => formatCalendarTime($availability['start_time']),
        'end_time'   => formatCalendarTime($availability['end_time']),
        'note'       => $availability['note'] ?? '',
    ];
}

// ------------------------------------------------------------
// 「＋ 勤務可能日を登録」ポップアップ用フォーム
// ------------------------------------------------------------
ob_start();
?>
<button
    type="button"
    class="calendar-add-button"
    data-calendar-title="勤務可能日を登録"
    data-calendar-detail="calendar-add-availability-form"
    data-calendar-modal-class="calendar-modal-wide"
>
    <span class="calendar-add-plus" aria-hidden="true">+</span> 勤務可能日を登録
</button>
<div id="calendar-add-availability-form" class="calendar-detail-source" hidden>
    <form method="post" action="<?php echo htmlspecialchars($formActionUrl); ?>">
        <input type="hidden" name="action" value="create_availability">
        <div class="shift-create-layout">
            <div class="shift-create-fields">
                <div class="form-group">
                    <label for="new_available_date">勤務可能日</label>
                    <input type="date" id="new_available_date" name="available_date" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>">
                </div>
                <div class="form-group">
                    <label for="new_start_time">開始時刻</label>
                    <input type="time" id="new_start_time" name="start_time">
                </div>
                <div class="form-group">
                    <label for="new_end_time">終了時刻</label>
                    <input type="time" id="new_end_time" name="end_time">
                </div>
                <div class="form-group">
                    <label for="new_note">備考</label>
                    <textarea id="new_note" name="note" rows="3"></textarea>
                </div>
                <button type="submit" class="btn">登録する</button>
            </div>

            <aside class="shift-availability-check" data-employee-availability-check>
                <p class="shift-availability-title">登録済み勤務可能日</p>
                <div class="shift-availability-calendar" data-employee-availability-calendar></div>
                <div class="shift-selected-availability" data-employee-selected-availability></div>
            </aside>
        </div>
    </form>
</div>
<?php
$addAvailabilityToolbarHtml = ob_get_clean();

require_once __DIR__ . '/../../app/includes/header.php';
?>

<div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
<div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>

<div class="section">
    <div class="calendar-legend">
        <span class="calendar-legend-item"><span class="calendar-legend-swatch calendar-event-shift"></span>シフト予定</span>
        <span class="calendar-legend-item"><span class="calendar-legend-swatch calendar-event-warning"></span>申請中・店長確認待ち</span>
        <span class="calendar-legend-item"><span class="calendar-legend-swatch calendar-event-substituted"></span>代勤シフト</span>
        <span class="calendar-legend-item"><span class="calendar-legend-swatch calendar-event-leave-approved"></span>休み承認済み</span>
    </div>
    <div id="calendar-ui" class="calendar-controls" data-calendar-preserve-scroll>
        <div class="calendar-month-nav">
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars(calendarUrl('shifts.php', $previousWeekStart->format('Y-m'), $calendarView, $previousWeekStart->format('Y-m-d'))); ?>">← 前の週</a>
            <span class="shift-week-label"><?php echo htmlspecialchars($weekLabel); ?></span>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars(calendarUrl('shifts.php', $nextWeekStart->format('Y-m'), $calendarView, $nextWeekStart->format('Y-m-d'))); ?>">次の週 →</a>
        </div>
        <?php if ($addAvailabilityToolbarHtml !== ''): ?>
        <div class="calendar-toolbar">
            <?php echo $addAvailabilityToolbarHtml; ?>
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
                                $dayEvents = $shiftTableRows[$personKey][$dateText] ?? [];
                                $visibleDayEvents = array_slice($dayEvents, 0, 3);
                                $hiddenEventCount = max(0, count($dayEvents) - count($visibleDayEvents));
                                $cellDetailId = 'employee-shift-cell-detail-' . md5($personKey . '-' . $dateText);
                                if (count($dayEvents) === 1) {
                                    $cellClasses[] = 'is-single-shift';
                                }
                                if (!empty($dayEvents)) {
                                    $cellClasses[] = 'is-clickable';
                                }
                                ?>
                                <td
                                    class="<?php echo htmlspecialchars(implode(' ', $cellClasses)); ?>"
                                    <?php if (!empty($dayEvents)): ?>
                                    data-calendar-title="<?php echo htmlspecialchars($person['name'] . ' ' . $dateText . ' の予定一覧'); ?>"
                                    data-calendar-detail="<?php echo htmlspecialchars($cellDetailId); ?>"
                                    <?php endif; ?>
                                >
                                    <?php if (empty($dayEvents)): ?>
                                        <span class="shift-table-empty">-</span>
                                    <?php else: ?>
                                        <?php foreach ($visibleDayEvents as $eventItem): ?>
                                            <?php $detailId = 'employee-shift-detail-' . md5((string) $eventItem['id'] . '-' . $personKey . '-' . $dateText); ?>
                                            <button
                                                type="button"
                                                class="shift-table-shift <?php echo htmlspecialchars($eventItem['class']); ?>"
                                                data-calendar-title="<?php echo htmlspecialchars($eventItem['title'] . ' ' . $eventItem['time_range']); ?>"
                                                data-calendar-detail="<?php echo htmlspecialchars($detailId); ?>"
                                            >
                                                <?php echo htmlspecialchars($eventItem['time_range']); ?>
                                            </button>
                                            <div id="<?php echo htmlspecialchars($detailId); ?>" class="calendar-detail-source" hidden>
                                                <?php echo $eventItem['detail_html']; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if ($hiddenEventCount > 0): ?>
                                            <button
                                                type="button"
                                                class="shift-table-more-button"
                                                data-calendar-title="<?php echo htmlspecialchars($person['name'] . ' ' . $dateText . ' の予定一覧'); ?>"
                                                data-calendar-detail="<?php echo htmlspecialchars($cellDetailId); ?>"
                                            >
                                                他<?php echo (int) $hiddenEventCount; ?>件
                                            </button>
                                        <?php endif; ?>
                                        <div id="<?php echo htmlspecialchars($cellDetailId); ?>" class="calendar-detail-source" hidden>
                                            <?php foreach ($dayEvents as $eventItem): ?>
                                            <div class="calendar-day-detail-item">
                                                <h4><?php echo htmlspecialchars($eventItem['title'] . ' ' . $eventItem['time_range']); ?></h4>
                                                <?php echo $eventItem['detail_html']; ?>
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

<script type="application/json" id="employee-availability-data">
<?php
echo json_encode(
    $availabilityCalendarRows,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.querySelector('[data-calendar-modal]');
    const modalPanel = modal ? modal.querySelector('.calendar-modal-panel') : null;
    const modalTitle = modal ? modal.querySelector('[data-calendar-modal-title]') : null;
    const modalBody = modal ? modal.querySelector('[data-calendar-modal-body]') : null;
    const scrollKey = 'employee-shifts-scroll-position';
    const availabilityDataElement = document.getElementById('employee-availability-data');
    const employeeAvailabilityRows = availabilityDataElement ? JSON.parse(availabilityDataElement.textContent || '[]') : [];
    const availabilityDeleteUrl = <?php echo json_encode($formActionUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

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
        if (button.dataset.calendarDetail === 'calendar-add-availability-form') {
            window.setTimeout(updateEmployeeAvailabilityCalendar, 0);
        }
    };

    const closeCalendarModal = function () {
        if (!modal || !modalBody) {
            return;
        }

        modal.hidden = true;
        modalBody.innerHTML = '';
        document.body.classList.remove('calendar-modal-open');
    };

    const escapeHtml = function (value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
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

    const getInitialAvailabilityMonth = function (selectedDate) {
        if (selectedDate !== '') {
            const selectedMonth = selectedDate.slice(0, 7);
            const hasAvailabilityInSelectedMonth = employeeAvailabilityRows.some(function (row) {
                return row.date.slice(0, 7) === selectedMonth;
            });
            if (hasAvailabilityInSelectedMonth) {
                return selectedMonth;
            }
        }
        if (employeeAvailabilityRows.length > 0) {
            return employeeAvailabilityRows[0].date.slice(0, 7);
        }

        const today = new Date();
        return today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0');
    };

    const renderSelectedAvailability = function (detailElement, selectedDate) {
        if (selectedDate === '') {
            detailElement.innerHTML = '';
            return;
        }

        const rows = employeeAvailabilityRows.filter(function (row) {
            return row.date === selectedDate;
        });

        if (rows.length === 0) {
            detailElement.innerHTML = '<p>登録なし</p>';
            return;
        }

        detailElement.innerHTML = '<p>選択日の勤務可能時間</p><ul>'
            + rows.map(function (row) {
                const note = row.note ? '<span>' + escapeHtml(row.note) + '</span>' : '';
                return '<li>'
                    + '<div class="availability-time-card-content">'
                    + '<strong>' + escapeHtml(row.start_time + '-' + row.end_time) + '</strong>'
                    + note
                    + '</div>'
                    + '<button type="button" class="availability-delete-button" data-delete-availability-id="' + escapeHtml(row.id) + '" aria-label="勤務可能日を削除">🗑</button>'
                    + '</li>';
            }).join('')
            + '</ul>';
    };

    const renderAvailabilityCalendar = function (calendarElement, selectedDate, month) {
        const availableDates = new Set(employeeAvailabilityRows.map(function (row) {
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

            dayHtml += '<button type="button" class="' + classes.join(' ') + '" data-employee-availability-date="' + escapeHtml(dateText) + '">'
                + date.getDate()
                + '</button>';
        }

        calendarElement.innerHTML = ''
            + '<div class="shift-mini-calendar-header">'
            + '<button type="button" class="shift-mini-calendar-nav" data-employee-availability-month-offset="-1">‹</button>'
            + '<strong>' + escapeHtml(formatMonthLabel(month)) + '</strong>'
            + '<button type="button" class="shift-mini-calendar-nav" data-employee-availability-month-offset="1">›</button>'
            + '</div>'
            + '<div class="shift-mini-calendar-weekdays">' + weekdays.map(function (weekday) {
                return '<span>' + weekday + '</span>';
            }).join('') + '</div>'
            + '<div class="shift-mini-calendar-days">' + dayHtml + '</div>';
    };

    const updateEmployeeAvailabilityCalendar = function () {
        const activeModal = document.querySelector('[data-calendar-modal]:not([hidden])');
        const form = activeModal ? activeModal.querySelector('form') : null;
        const calendarElement = activeModal ? activeModal.querySelector('[data-employee-availability-calendar]') : null;
        const detailElement = activeModal ? activeModal.querySelector('[data-employee-selected-availability]') : null;
        const dateInput = form ? form.querySelector('[name="available_date"]') : null;
        if (!form || !calendarElement || !detailElement || !dateInput) {
            return;
        }

        const selectedDate = dateInput.value || '';
        const checkElement = activeModal.querySelector('[data-employee-availability-check]');
        if (!checkElement.dataset.calendarMonth || selectedDate.slice(0, 7) !== checkElement.dataset.lastSelectedMonth) {
            checkElement.dataset.calendarMonth = getInitialAvailabilityMonth(selectedDate);
        }
        checkElement.dataset.lastSelectedMonth = selectedDate.slice(0, 7);
        renderAvailabilityCalendar(calendarElement, selectedDate, checkElement.dataset.calendarMonth);
        renderSelectedAvailability(detailElement, selectedDate);
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

        const monthButton = event.target.closest('[data-employee-availability-month-offset]');
        if (monthButton) {
            const checkElement = monthButton.closest('[data-employee-availability-check]');
            if (checkElement) {
                const currentMonth = checkElement.dataset.calendarMonth || getInitialAvailabilityMonth('');
                checkElement.dataset.calendarMonth = addMonths(currentMonth, Number(monthButton.dataset.employeeAvailabilityMonthOffset));
                updateEmployeeAvailabilityCalendar();
            }
        }

        const dateButton = event.target.closest('[data-employee-availability-date]');
        if (dateButton) {
            const form = dateButton.closest('form');
            const dateInput = form ? form.querySelector('[name="available_date"]') : null;
            if (dateInput) {
                dateInput.value = dateButton.dataset.employeeAvailabilityDate;
                dateInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        const deleteButton = event.target.closest('[data-delete-availability-id]');
        if (deleteButton) {
            const availabilityId = deleteButton.dataset.deleteAvailabilityId || '';
            if (availabilityId === '') {
                return;
            }
            if (!window.confirm('この勤務可能日を削除しますか？')) {
                return;
            }

            sessionStorage.setItem(scrollKey, String(window.scrollY));
            const form = document.createElement('form');
            form.method = 'post';
            form.action = availabilityDeleteUrl;
            form.innerHTML = ''
                + '<input type="hidden" name="action" value="delete_availability">'
                + '<input type="hidden" name="id" value="' + escapeHtml(availabilityId) + '">';
            document.body.appendChild(form);
            form.submit();
        }
    });

    document.addEventListener('change', function (event) {
        if (event.target.closest('[data-calendar-modal]:not([hidden]) form') && event.target.name === 'available_date') {
            updateEmployeeAvailabilityCalendar();
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
});
</script>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
