<?php
/**
 * 代勤候補抽出モード設定画面（店長用）
 *
 * - 店長が現在の代勤候補抽出モード（matching_settings.current_matching_mode）を
 *   「通常」「人員確保優先」「スキル重視」の3つから選択・保存する
 * - 保存したモードは、休み申請登録時の代勤候補抽出（processSubstituteMatching()）で使用される
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/services/substitute_matcher.php';

$pageTitle = '設定';
$basePath  = '../../public/';

$errorMessage   = '';
$successMessage = '';

/**
 * 指定SQLでID一覧を取得する。
 */
function fetchSettingRecordIds(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * ID配列を使ってDELETE文を実行する。
 */
function deleteSettingRecordsByIds(PDO $pdo, string $sqlPrefix, array $ids): int
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (empty($ids)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare($sqlPrefix . " IN ($placeholders)");
    $stmt->execute($ids);

    return $stmt->rowCount();
}

/**
 * 初期データSQLを実行する。
 *
 * seed.sql は管理者が用意した固定ファイルのみを読み込む。
 * ユーザー入力のパスやSQLは受け取らない。
 */
function resetDemoDataFromSeed(PDO $pdo): void
{
    $seedPath = __DIR__ . '/../../database/seed.sql';
    $sql = file_get_contents($seedPath);

    if ($sql === false) {
        throw new RuntimeException('初期データファイルを読み込めませんでした。');
    }

    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql);
    if ($statements === false) {
        throw new RuntimeException('初期データSQLの解析に失敗しました。');
    }

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }
}

// ------------------------------------------------------------
// POST処理（抽出モードの保存）
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_mode') {
    $mode = $_POST['matching_mode'] ?? '';

    if (!in_array($mode, getMatchingModes(), true)) {
        $errorMessage = '不正な抽出モードが指定されました。';
    } else {
        setCurrentMatchingMode($pdo, $mode);
        header('Location: matching_settings.php?msg=updated');
        exit;
    }
}

// ------------------------------------------------------------
// POST処理（初期データへリセット）
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_demo_data') {
    try {
        resetDemoDataFromSeed($pdo);
        header('Location: matching_settings.php?msg=demo_reset');
        exit;
    } catch (Throwable $e) {
        $errorMessage = '初期状態へのリセット中にエラーが発生しました。データベースの状態を確認してください。';
    }
}

// ------------------------------------------------------------
// POST処理（指定期間の記録削除）
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_records') {
    $deleteStartDate = $_POST['delete_start_date'] ?? '';
    $deleteEndDate   = $_POST['delete_end_date'] ?? '';

    $startDate = DateTime::createFromFormat('Y-m-d', $deleteStartDate);
    $endDate   = DateTime::createFromFormat('Y-m-d', $deleteEndDate);
    $isValidStart = $startDate !== false && $startDate->format('Y-m-d') === $deleteStartDate;
    $isValidEnd   = $endDate !== false && $endDate->format('Y-m-d') === $deleteEndDate;

    if (!$isValidStart || !$isValidEnd) {
        $errorMessage = '削除期間の日付を正しく入力してください。';
    } elseif ($startDate > $endDate) {
        $errorMessage = '削除開始日は削除終了日以前の日付を指定してください。';
    } else {
        try {
            $pdo->beginTransaction();

            $targetLeaveRequestIds = fetchSettingRecordIds(
                $pdo,
                'SELECT DISTINCT lr.id
                   FROM leave_requests lr
                   JOIN shifts s ON s.id = lr.shift_id
                  WHERE s.shift_date BETWEEN :start_date AND :end_date',
                [
                    'start_date' => $deleteStartDate,
                    'end_date'   => $deleteEndDate,
                ]
            );

            $targetShiftIds = fetchSettingRecordIds(
                $pdo,
                'SELECT DISTINCT s.id
                   FROM shifts s
                  WHERE s.shift_date BETWEEN :start_date AND :end_date',
                [
                    'start_date' => $deleteStartDate,
                    'end_date'   => $deleteEndDate,
                ]
            );

            if (!empty($targetLeaveRequestIds)) {
                $relatedManualShiftIds = fetchSettingRecordIds(
                    $pdo,
                    'SELECT DISTINCT id
                       FROM shifts
                      WHERE related_leave_request_id IN (' . implode(',', array_fill(0, count($targetLeaveRequestIds), '?')) . ')',
                    $targetLeaveRequestIds
                );
                $targetShiftIds = array_values(array_unique(array_merge($targetShiftIds, $relatedManualShiftIds)));
            }

            $targetCandidateIds = [];
            $targetCancellationIds = [];
            $targetApprovalIds = [];
            if (!empty($targetLeaveRequestIds)) {
                $targetCandidateIds = fetchSettingRecordIds(
                    $pdo,
                    'SELECT DISTINCT id
                       FROM substitute_candidates
                      WHERE leave_request_id IN (' . implode(',', array_fill(0, count($targetLeaveRequestIds), '?')) . ')',
                    $targetLeaveRequestIds
                );

                $targetCancellationIds = fetchSettingRecordIds(
                    $pdo,
                    'SELECT DISTINCT id
                       FROM cancellation_requests
                      WHERE leave_request_id IN (' . implode(',', array_fill(0, count($targetLeaveRequestIds), '?')) . ')',
                    $targetLeaveRequestIds
                );

                $targetApprovalIds = fetchSettingRecordIds(
                    $pdo,
                    'SELECT DISTINCT id
                       FROM approvals
                      WHERE leave_request_id IN (' . implode(',', array_fill(0, count($targetLeaveRequestIds), '?')) . ')',
                    $targetLeaveRequestIds
                );
            }

            $deletedNotifications = deleteSettingRecordsByIds(
                $pdo,
                'DELETE FROM notifications WHERE related_leave_request_id',
                $targetLeaveRequestIds
            );

            $deletedViewStates = 0;
            $deletedViewStates += deleteSettingRecordsByIds(
                $pdo,
                "DELETE FROM request_view_states WHERE item_type IN ('leave', 'leave_cancel_before', 'manager_leave') AND item_id",
                $targetLeaveRequestIds
            );
            $deletedViewStates += deleteSettingRecordsByIds(
                $pdo,
                "DELETE FROM request_view_states WHERE item_type IN ('substitute', 'substitute_cancel_before') AND item_id",
                $targetCandidateIds
            );
            $deletedViewStates += deleteSettingRecordsByIds(
                $pdo,
                "DELETE FROM request_view_states WHERE item_type IN ('leave_cancel_after', 'substitute_cancel_after', 'manager_cancel') AND item_id",
                $targetCancellationIds
            );

            $stmt = $pdo->prepare(
                'DELETE FROM availability
                  WHERE available_date BETWEEN :start_date AND :end_date'
            );
            $stmt->execute([
                'start_date' => $deleteStartDate,
                'end_date'   => $deleteEndDate,
            ]);
            $deletedAvailability = $stmt->rowCount();

            $deletedShifts = deleteSettingRecordsByIds(
                $pdo,
                'DELETE FROM shifts WHERE id',
                $targetShiftIds
            );

            $pdo->commit();

            $successMessage = sprintf(
                '%s～%sの記録を削除しました。（シフト:%d件、勤務可能日:%d件、休み申請:%d件、代勤候補:%d件、キャンセル申請:%d件、承認記録:%d件、通知:%d件、表示状態:%d件）',
                $deleteStartDate,
                $deleteEndDate,
                $deletedShifts,
                $deletedAvailability,
                count($targetLeaveRequestIds),
                count($targetCandidateIds),
                count($targetCancellationIds),
                count($targetApprovalIds),
                $deletedNotifications,
                $deletedViewStates
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = '記録削除中にエラーが発生しました。削除は中断されました。';
        }
    }
}

// ------------------------------------------------------------
// 完了メッセージ（リダイレクト後）
// ------------------------------------------------------------
if (isset($_GET['msg']) && $_GET['msg'] === 'updated') {
    $successMessage = '代勤候補抽出モードを更新しました。';
} elseif (isset($_GET['msg']) && $_GET['msg'] === 'demo_reset') {
    $successMessage = '初期状態にリセットしました。';
}

$currentMode = getCurrentMatchingMode($pdo);

$recordCalendarRows = [];
$stmt = $pdo->query(
    "SELECT target_date, SUM(shift_count) AS shift_count, SUM(availability_count) AS availability_count,
            SUM(leave_count) AS leave_count, SUM(candidate_count) AS candidate_count,
            SUM(cancellation_count) AS cancellation_count, SUM(approval_count) AS approval_count
     FROM (
        SELECT shift_date AS target_date, COUNT(*) AS shift_count, 0 AS availability_count,
               0 AS leave_count, 0 AS candidate_count, 0 AS cancellation_count, 0 AS approval_count
          FROM shifts
         GROUP BY shift_date
        UNION ALL
        SELECT available_date AS target_date, 0, COUNT(*), 0, 0, 0, 0
          FROM availability
         GROUP BY available_date
        UNION ALL
        SELECT s.shift_date AS target_date, 0, 0, COUNT(DISTINCT lr.id), 0, 0, 0
          FROM leave_requests lr
          JOIN shifts s ON s.id = lr.shift_id
         GROUP BY s.shift_date
        UNION ALL
        SELECT s.shift_date AS target_date, 0, 0, 0, COUNT(DISTINCT sc.id), 0, 0
          FROM substitute_candidates sc
          JOIN leave_requests lr ON lr.id = sc.leave_request_id
          JOIN shifts s ON s.id = lr.shift_id
         GROUP BY s.shift_date
        UNION ALL
        SELECT s.shift_date AS target_date, 0, 0, 0, 0, COUNT(DISTINCT cr.id), 0
          FROM cancellation_requests cr
          JOIN leave_requests lr ON lr.id = cr.leave_request_id
          JOIN shifts s ON s.id = lr.shift_id
         GROUP BY s.shift_date
        UNION ALL
        SELECT s.shift_date AS target_date, 0, 0, 0, 0, 0, COUNT(DISTINCT a.id)
          FROM approvals a
          JOIN leave_requests lr ON lr.id = a.leave_request_id
          JOIN shifts s ON s.id = lr.shift_id
         GROUP BY s.shift_date
     ) record_counts
     GROUP BY target_date
     ORDER BY target_date"
);
foreach ($stmt->fetchAll() as $row) {
    $recordCalendarRows[] = [
        'date'               => $row['target_date'],
        'shift_count'        => (int) $row['shift_count'],
        'availability_count' => (int) $row['availability_count'],
        'leave_count'        => (int) $row['leave_count'],
        'candidate_count'    => (int) $row['candidate_count'],
        'cancellation_count' => (int) $row['cancellation_count'],
        'approval_count'     => (int) $row['approval_count'],
    ];
}

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    休み申請時の代勤候補抽出で使用する抽出モードや、古い記録の削除を管理します。
</p>

<p class="page-description">
    抽出モードは、
    以後の休み申請に対する候補者のスコア計算に使用されます。
    変更内容は今後の休み申請から反映され、過去の申請には影響しません。
</p>

<div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
<div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>

<div class="section">
    <h2>現在の抽出モード</h2>
    <p class="page-description">
        現在の抽出モード：<strong><?php echo htmlspecialchars(getMatchingModeLabel($currentMode)); ?></strong>
    </p>

    <form method="post" action="matching_settings.php">
        <input type="hidden" name="action" value="update_mode">
        <div class="settings-card-list">
            <?php foreach (getMatchingModes() as $mode): ?>
            <label class="settings-option-card <?php echo $mode === $currentMode ? 'is-selected' : ''; ?>" for="mode_<?php echo htmlspecialchars($mode); ?>">
                <input
                    type="radio"
                    id="mode_<?php echo htmlspecialchars($mode); ?>"
                    name="matching_mode"
                    value="<?php echo htmlspecialchars($mode); ?>"
                    <?php echo $mode === $currentMode ? 'checked' : ''; ?>
                >
                <span class="settings-option-title"><?php echo htmlspecialchars(getMatchingModeLabel($mode)); ?></span>
                <span class="settings-option-description"><?php echo htmlspecialchars(getMatchingModeDescription($mode)); ?></span>
            </label>
            <?php endforeach; ?>
        </div>
        <button type="submit" class="btn">この内容で保存する</button>
    </form>
</div>

<div class="section settings-danger-section">
    <h2>記録削除</h2>
    <p class="danger-message">
        指定した期間のシフト、勤務可能日、休み申請、代勤候補、承認記録、キャンセル申請、関連通知、申請確認の表示状態を削除します。
        削除した記録は元に戻せません。
    </p>

    <button type="button" class="btn btn-danger" data-record-delete-open>削除する期間を指定する</button>
</div>

<div class="section settings-danger-section">
    <h2>初期データリセット</h2>
    <p class="danger-message">
        現在のアカウント、シフト、勤務可能日、休み申請、代勤候補、通知、承認記録、キャンセル申請をすべて初期化し、
        初期データに戻します。
    </p>
    <form
        method="post"
        action="matching_settings.php"
        data-confirm-message="現在のデータをすべて削除し、初期状態に戻します。この操作は元に戻せません。本当にリセットしますか？"
    >
        <input type="hidden" name="action" value="reset_demo_data">
        <button type="submit" class="btn btn-danger">初期データにリセット</button>
    </form>
</div>

<div class="calendar-modal" data-record-delete-modal hidden>
    <div class="calendar-modal-backdrop" data-record-delete-close></div>
    <div class="calendar-modal-panel calendar-modal-wide" role="dialog" aria-modal="true" aria-labelledby="record-delete-modal-title">
        <button type="button" class="calendar-modal-close" data-record-delete-close>×</button>
        <h3 id="record-delete-modal-title">削除する期間を指定</h3>
        <div class="record-delete-modal-layout">
            <form
                method="post"
                action="matching_settings.php"
                class="record-delete-form"
                data-confirm-message="指定期間のシフト関連記録を完全に削除します。この操作は元に戻せません。本当に削除しますか？"
            >
                <input type="hidden" name="action" value="delete_records">
                <div class="record-delete-grid">
                    <label>
                        <span>削除開始日</span>
                        <input type="date" name="delete_start_date" required>
                    </label>
                    <label>
                        <span>削除終了日</span>
                        <input type="date" name="delete_end_date" required>
                    </label>
                </div>
                <p class="record-delete-note">例：2026年7月1日～2026年7月31日</p>
                <div class="record-delete-preview" data-record-delete-preview>
                    <p>期間を選択すると削除対象件数を確認できます。</p>
                </div>
                <button type="submit" class="btn btn-danger">削除する</button>
            </form>

            <aside class="record-delete-calendar-panel">
                <p class="shift-availability-title">シフト関連データ</p>
                <div class="record-delete-calendar" data-record-delete-calendar></div>
                <div class="record-delete-selected" data-record-delete-selected></div>
            </aside>
        </div>
    </div>
</div>

<script type="application/json" id="record-delete-calendar-data">
<?php echo json_encode($recordCalendarRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
</script>

<script>
(function () {
    const modal = document.querySelector('[data-record-delete-modal]');
    const openButton = document.querySelector('[data-record-delete-open]');
    const calendarElement = document.querySelector('[data-record-delete-calendar]');
    const selectedElement = document.querySelector('[data-record-delete-selected]');
    const previewElement = document.querySelector('[data-record-delete-preview]');
    const dataElement = document.getElementById('record-delete-calendar-data');
    const rows = dataElement ? JSON.parse(dataElement.textContent || '[]') : [];
    const recordsByDate = rows.reduce(function (carry, row) {
        carry[row.date] = row;
        return carry;
    }, {});
    const weekdays = ['日', '月', '火', '水', '木', '金', '土'];
    let activeMonth = new Date();

    function padNumber(value) {
        return String(value).padStart(2, '0');
    }

    function formatDate(date) {
        return date.getFullYear() + '-' + padNumber(date.getMonth() + 1) + '-' + padNumber(date.getDate());
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function totalCount(row) {
        if (!row) {
            return 0;
        }
        return Number(row.shift_count || 0)
            + Number(row.availability_count || 0)
            + Number(row.leave_count || 0)
            + Number(row.candidate_count || 0)
            + Number(row.cancellation_count || 0)
            + Number(row.approval_count || 0);
    }

    function emptyTotals() {
        return {
            shift_count: 0,
            availability_count: 0,
            leave_count: 0,
            candidate_count: 0,
            cancellation_count: 0,
            approval_count: 0
        };
    }

    function addTotals(totals, row) {
        totals.shift_count += Number(row.shift_count || 0);
        totals.availability_count += Number(row.availability_count || 0);
        totals.leave_count += Number(row.leave_count || 0);
        totals.candidate_count += Number(row.candidate_count || 0);
        totals.cancellation_count += Number(row.cancellation_count || 0);
        totals.approval_count += Number(row.approval_count || 0);
    }

    function renderTotalsHtml(totals) {
        const total = totalCount(totals);
        return '<p>削除対象：<strong>' + total + '件</strong></p>'
            + '<ul>'
            + '<li><span>シフト</span><strong>' + escapeHtml(totals.shift_count) + '件</strong></li>'
            + '<li><span>勤務可能日</span><strong>' + escapeHtml(totals.availability_count) + '件</strong></li>'
            + '<li><span>休み申請</span><strong>' + escapeHtml(totals.leave_count) + '件</strong></li>'
            + '<li><span>代勤候補</span><strong>' + escapeHtml(totals.candidate_count) + '件</strong></li>'
            + '<li><span>キャンセル申請</span><strong>' + escapeHtml(totals.cancellation_count) + '件</strong></li>'
            + '<li><span>承認記録</span><strong>' + escapeHtml(totals.approval_count) + '件</strong></li>'
            + '</ul>';
    }

    function calculateRangeTotals(start, end) {
        const totals = emptyTotals();
        if (!start || !end || start > end) {
            return totals;
        }

        rows.forEach(function (row) {
            if (row.date >= start && row.date <= end) {
                addTotals(totals, row);
            }
        });

        return totals;
    }

    function updatePreview(form) {
        if (!previewElement || !form) {
            return emptyTotals();
        }
        const start = form.querySelector('[name="delete_start_date"]').value;
        const end = form.querySelector('[name="delete_end_date"]').value;

        if (!start || !end) {
            previewElement.innerHTML = '<p>期間を選択すると削除対象件数を確認できます。</p>';
            return emptyTotals();
        }
        if (start > end) {
            previewElement.innerHTML = '<p class="record-delete-preview-warning">削除開始日は削除終了日以前の日付を指定してください。</p>';
            return emptyTotals();
        }

        const totals = calculateRangeTotals(start, end);
        previewElement.innerHTML = renderTotalsHtml(totals);
        return totals;
    }

    function renderSelected(dateText) {
        const row = recordsByDate[dateText];
        if (!selectedElement) {
            return;
        }
        if (!row || totalCount(row) === 0) {
            selectedElement.innerHTML = '<p>' + escapeHtml(dateText) + '：記録なし</p>';
            return;
        }

        selectedElement.innerHTML = '<p>' + escapeHtml(dateText) + '</p>'
            + '<ul>'
            + '<li><span>シフト</span><strong>' + escapeHtml(row.shift_count) + '件</strong></li>'
            + '<li><span>勤務可能日</span><strong>' + escapeHtml(row.availability_count) + '件</strong></li>'
            + '<li><span>休み申請</span><strong>' + escapeHtml(row.leave_count) + '件</strong></li>'
            + '<li><span>代勤候補</span><strong>' + escapeHtml(row.candidate_count) + '件</strong></li>'
            + '<li><span>キャンセル申請</span><strong>' + escapeHtml(row.cancellation_count) + '件</strong></li>'
            + '<li><span>承認記録</span><strong>' + escapeHtml(row.approval_count) + '件</strong></li>'
            + '</ul>';
    }

    function renderCalendar() {
        if (!calendarElement) {
            return;
        }
        const year = activeMonth.getFullYear();
        const month = activeMonth.getMonth();
        const firstDate = new Date(year, month, 1);
        const startDate = new Date(firstDate);
        startDate.setDate(firstDate.getDate() - firstDate.getDay());

        let dayHtml = '';
        for (let index = 0; index < 42; index += 1) {
            const current = new Date(startDate);
            current.setDate(startDate.getDate() + index);
            const dateText = formatDate(current);
            const row = recordsByDate[dateText];
            const count = totalCount(row);
            const classes = ['shift-mini-calendar-day'];
            if (current.getMonth() !== month) {
                classes.push('is-outside-month');
            }
            if (count > 0) {
                classes.push('is-available');
            }

            dayHtml += '<button type="button" class="' + classes.join(' ') + '" data-record-delete-date="' + escapeHtml(dateText) + '">'
                + '<span>' + current.getDate() + '</span>'
                + (count > 0 ? '<small>' + count + '件</small>' : '')
                + '</button>';
        }

        calendarElement.innerHTML = ''
            + '<div class="shift-mini-calendar-header">'
            + '<button type="button" class="shift-mini-calendar-nav" data-record-delete-month-offset="-1">‹</button>'
            + '<strong>' + year + '年' + (month + 1) + '月</strong>'
            + '<button type="button" class="shift-mini-calendar-nav" data-record-delete-month-offset="1">›</button>'
            + '</div>'
            + '<div class="shift-mini-calendar-weekdays">' + weekdays.map(function (weekday) {
                return '<span>' + weekday + '</span>';
            }).join('') + '</div>'
            + '<div class="shift-mini-calendar-days">' + dayHtml + '</div>';
    }

    function openModal() {
        if (!modal) {
            return;
        }
        renderCalendar();
        selectedElement.innerHTML = '<p>日付を選択すると件数を確認できます。</p>';
        modal.hidden = false;
        document.body.classList.add('calendar-modal-open');
    }

    function closeModal() {
        if (!modal) {
            return;
        }
        modal.hidden = true;
        document.body.classList.remove('calendar-modal-open');
    }

    if (openButton) {
        openButton.addEventListener('click', openModal);
    }

    document.querySelectorAll('[data-record-delete-close]').forEach(function (button) {
        button.addEventListener('click', closeModal);
    });

    document.addEventListener('click', function (event) {
        const monthButton = event.target.closest('[data-record-delete-month-offset]');
        if (monthButton) {
            activeMonth.setMonth(activeMonth.getMonth() + Number(monthButton.dataset.recordDeleteMonthOffset || 0));
            renderCalendar();
            return;
        }

        const dateButton = event.target.closest('[data-record-delete-date]');
        if (dateButton) {
            renderSelected(dateButton.dataset.recordDeleteDate || '');
        }
    });

    document.querySelectorAll('.record-delete-form').forEach(function (form) {
        form.querySelectorAll('[name="delete_start_date"], [name="delete_end_date"]').forEach(function (input) {
            input.addEventListener('change', function () {
                updatePreview(form);
            });
        });

        form.addEventListener('submit', function (event) {
            var start = form.querySelector('[name="delete_start_date"]').value;
            var end = form.querySelector('[name="delete_end_date"]').value;
            var totals = updatePreview(form);
            var message = form.dataset.confirmMessage
                + "\n\n対象期間：" + start + " ～ " + end
                + "\n削除対象：" + totalCount(totals) + "件";

            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('form[data-confirm-message]:not(.record-delete-form)').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.dataset.confirmMessage || '実行してよろしいですか？')) {
                event.preventDefault();
            }
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
