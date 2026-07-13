<?php
/**
 * 申請確認画面（従業員用）
 *
 * 休み申請・代勤申請・休みキャンセル申請・代勤キャンセル申請を確認する。
 * 申請本体は削除せず、非表示・お気に入りは request_view_states でユーザー別に管理する。
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('employee');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/includes/status_labels.php';
require_once __DIR__ . '/../../app/includes/pagination.php';
require_once __DIR__ . '/../../app/includes/request_view_helpers.php';

$pageTitle = '申請確認';
$basePath  = '../../public/';

$user       = currentUser();
$userId     = (int) $user['id'];
$employeeId = (int) $user['employee_id'];

ensureRequestViewStatesTable($pdo);
cleanupOldRequestViewItems($pdo, $userId, $employeeId);

$filter = normalizeRequestViewFilter($_GET['filter'] ?? 'all');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$redirectUrl = requestViewPageUrl($filter, $page);

if (($_GET['from'] ?? '') === 'notifications') {
    $backUrl = 'notifications.php';
}

function requestViewTime(?string $time): string
{
    return $time !== null && $time !== '' ? substr($time, 0, 5) : '';
}

function requestViewShiftLabel(array $row): string
{
    $label = (string) ($row['shift_date'] ?? '-');
    $start = requestViewTime($row['start_time'] ?? null);
    $end = requestViewTime($row['end_time'] ?? null);
    if ($start !== '' && $end !== '') {
        $label .= ' ' . $start . '-' . $end;
    }
    if (!empty($row['position'])) {
        $label .= '（' . $row['position'] . '）';
    }

    return $label;
}

function requestViewLeaveStatusLabel(?string $status): string
{
    $labels = [
        'pending'                 => '受付中',
        'matching'                => '申請中・店長確認待ち',
        'no_candidate'            => '申請中・店長確認待ち',
        'approved'                => '休み承認済み',
        'rejected'                => '却下済み',
        'cancelled'               => '承認前キャンセル済み',
        'cancelled_after_approval'=> '承認後キャンセル済み',
        'replacement_pending'     => '代勤者再調整中',
    ];

    return $labels[$status] ?? (string) $status;
}

function requestViewLeaveStatusClass(?string $status): string
{
    $classes = [
        'pending'                 => 'badge-inactive',
        'matching'                => 'badge-warning',
        'no_candidate'            => 'badge-warning',
        'approved'                => 'badge-success',
        'rejected'                => 'badge-danger',
        'cancelled'               => 'badge-inactive',
        'cancelled_after_approval'=> 'badge-inactive',
        'replacement_pending'     => 'badge-warning',
    ];

    return $classes[$status] ?? 'badge-inactive';
}

function requestViewCancelStatusLabel(?string $status): string
{
    $labels = [
        'pending'  => '店長確認待ち',
        'approved' => '承認済み',
        'rejected' => '却下済み',
    ];

    return $labels[$status] ?? (string) $status;
}

function requestViewCancelStatusClass(?string $status): string
{
    $classes = [
        'pending'  => 'badge-warning',
        'approved' => 'badge-success',
        'rejected' => 'badge-danger',
    ];

    return $classes[$status] ?? 'badge-inactive';
}

function requestViewSubstituteStatusLabel(array $row, int $employeeId): string
{
    $candidateId = (int) $row['candidate_id'];
    $approvedCandidateId = isset($row['approved_candidate_id']) ? (int) $row['approved_candidate_id'] : 0;
    $currentShiftEmployeeId = isset($row['current_shift_employee_id']) ? (int) $row['current_shift_employee_id'] : 0;

    if (
        ($approvedCandidateId === $candidateId || $currentShiftEmployeeId === $employeeId)
        && $row['leave_status'] === 'approved'
        && $row['shift_status'] === 'substituted'
    ) {
        return '代勤確定';
    }

    if ($row['candidate_status'] === 'accepted' && in_array($row['leave_status'], ['matching', 'no_candidate', 'replacement_pending'], true)) {
        return '代勤可能回答済み・店長確認待ち';
    }

    if ($row['candidate_status'] === 'expired' && $row['leave_status'] === 'rejected') {
        return '休み申請却下';
    }

    if ($row['candidate_status'] === 'expired') {
        return '処理済み・対象外';
    }

    return '代勤可能回答済み';
}

function requestViewSubstituteStatusClass(string $statusLabel): string
{
    if ($statusLabel === '代勤確定') {
        return 'badge-success';
    }

    if ($statusLabel === '代勤可能回答済み・店長確認待ち') {
        return 'badge-warning';
    }

    if ($statusLabel === '休み申請却下') {
        return 'badge-danger';
    }

    return 'badge-inactive';
}

function requestViewDetailTable(array $rows): string
{
    ob_start();
    ?>
    <table>
        <tbody>
            <?php foreach ($rows as $label => $value): ?>
            <tr>
                <th><?php echo htmlspecialchars($label); ?></th>
                <td><?php echo nl2br(htmlspecialchars((string) ($value ?? '-'))); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}

function addRequestViewItem(array &$items, array $item): void
{
    $item['key'] = requestViewStateKey($item['item_type'], (int) $item['item_id']);
    $items[] = $item;
}

$items = [];

// ------------------------------------------------------------
// 休み申請
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT lr.id AS leave_request_id, lr.reason AS leave_reason, lr.status AS leave_status,
            lr.created_at, lr.updated_at,
            s.shift_date, s.start_time, s.end_time, s.position,
            s.status AS shift_status,
            current_emp.name AS current_shift_employee_name
     FROM leave_requests lr
     JOIN shifts s ON s.id = lr.shift_id
     JOIN employees current_emp ON current_emp.id = s.employee_id
     WHERE lr.employee_id = :employee_id
     ORDER BY lr.created_at DESC'
);
$stmt->execute(['employee_id' => $employeeId]);
foreach ($stmt->fetchAll() as $row) {
    $statusLabel = requestViewLeaveStatusLabel($row['leave_status']);
    $statusClass = requestViewLeaveStatusClass($row['leave_status']);
    $detailHtml = requestViewDetailTable([
        '対象シフト'       => requestViewShiftLabel($row),
        '申請状態'         => $statusLabel,
        '現在の担当者'     => $row['current_shift_employee_name'],
        '申請理由'         => $row['leave_reason'] ?: '-',
        '申請日時'         => $row['created_at'],
        '最終更新日時'     => $row['updated_at'],
    ]);

    addRequestViewItem($items, [
        'item_type'     => 'leave',
        'item_id'       => (int) $row['leave_request_id'],
        'category'      => 'leave',
        'category_label'=> requestViewFilterLabel('leave'),
        'phase_label'   => '-',
        'title'         => '休み申請',
        'status_label'  => $statusLabel,
        'status_class'  => $statusClass,
        'shift_label'   => requestViewShiftLabel($row),
        'date_label'    => $row['created_at'],
        'sort_at'       => $row['updated_at'] ?: $row['created_at'],
        'detail_html'   => $detailHtml,
        'is_completed'  => in_array($row['leave_status'], ['approved', 'rejected', 'cancelled', 'cancelled_after_approval'], true),
    ]);

    if ($row['leave_status'] === 'cancelled') {
        addRequestViewItem($items, [
            'item_type'     => 'leave_cancel_before',
            'item_id'       => (int) $row['leave_request_id'],
            'category'      => 'leave_cancel',
            'category_label'=> requestViewFilterLabel('leave_cancel'),
            'phase_label'   => '承認前',
            'title'         => '休みキャンセル申請',
            'status_label'  => 'キャンセル済み',
            'status_class'  => 'badge-inactive',
            'shift_label'   => requestViewShiftLabel($row),
            'date_label'    => $row['updated_at'],
            'sort_at'       => $row['updated_at'] ?: $row['created_at'],
            'detail_html'   => requestViewDetailTable([
                '対象シフト'   => requestViewShiftLabel($row),
                '区分'         => '承認前キャンセル',
                '状態'         => 'キャンセル済み',
                '申請理由'     => $row['leave_reason'] ?: '-',
                '更新日時'     => $row['updated_at'],
            ]),
            'is_completed'  => true,
        ]);
    }
}

// ------------------------------------------------------------
// 休みキャンセル申請（承認後）
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT cr.id AS cancellation_id, cr.status AS cancellation_status,
            cr.reason AS cancellation_reason, cr.created_at, cr.updated_at, cr.decided_at,
            lr.id AS leave_request_id,
            s.shift_date, s.start_time, s.end_time, s.position
     FROM cancellation_requests cr
     JOIN leave_requests lr ON lr.id = cr.leave_request_id
     JOIN shifts s ON s.id = lr.shift_id
     WHERE cr.request_type = 'requester_after_approval'
       AND cr.requested_by_employee_id = :employee_id
     ORDER BY cr.created_at DESC"
);
$stmt->execute(['employee_id' => $employeeId]);
foreach ($stmt->fetchAll() as $row) {
    $statusLabel = requestViewCancelStatusLabel($row['cancellation_status']);
    $statusClass = requestViewCancelStatusClass($row['cancellation_status']);

    addRequestViewItem($items, [
        'item_type'     => 'leave_cancel_after',
        'item_id'       => (int) $row['cancellation_id'],
        'category'      => 'leave_cancel',
        'category_label'=> requestViewFilterLabel('leave_cancel'),
        'phase_label'   => '承認後',
        'title'         => '休みキャンセル申請',
        'status_label'  => $statusLabel,
        'status_class'  => $statusClass,
        'shift_label'   => requestViewShiftLabel($row),
        'date_label'    => $row['created_at'],
        'sort_at'       => $row['decided_at'] ?: ($row['updated_at'] ?: $row['created_at']),
        'detail_html'   => requestViewDetailTable([
            '対象シフト'   => requestViewShiftLabel($row),
            '区分'         => '承認後キャンセル',
            '状態'         => $statusLabel,
            'キャンセル理由' => $row['cancellation_reason'] ?: '-',
            '申請日時'     => $row['created_at'],
            '判断日時'     => $row['decided_at'] ?: '-',
        ]),
        'is_completed'  => in_array($row['cancellation_status'], ['approved', 'rejected'], true),
    ]);
}

// ------------------------------------------------------------
// 代勤申請（代勤可能回答）
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT sc.id AS candidate_id, sc.status AS candidate_status,
            sc.responded_at, sc.updated_at, sc.match_reason,
            lr.id AS leave_request_id, lr.status AS leave_status, lr.reason AS leave_reason,
            requester.name AS requester_name,
            s.shift_date, s.start_time, s.end_time, s.position,
            s.employee_id AS current_shift_employee_id,
            s.status AS shift_status,
            approved_candidate.substitute_candidate_id AS approved_candidate_id,
            approved_candidate.status AS approval_status
     FROM substitute_candidates sc
     JOIN leave_requests lr ON lr.id = sc.leave_request_id
     JOIN shifts s ON s.id = lr.shift_id
     JOIN employees requester ON requester.id = lr.employee_id
     LEFT JOIN approvals approved_candidate
       ON approved_candidate.id = (
            SELECT MAX(a2.id)
            FROM approvals a2
            WHERE a2.leave_request_id = lr.id
       )
     WHERE sc.candidate_employee_id = :employee_id
       AND sc.status IN ('accepted', 'expired')
       AND sc.responded_at IS NOT NULL
     ORDER BY COALESCE(sc.responded_at, sc.updated_at) DESC"
);
$stmt->execute(['employee_id' => $employeeId]);
foreach ($stmt->fetchAll() as $row) {
    $statusLabel = requestViewSubstituteStatusLabel($row, $employeeId);
    $statusClass = requestViewSubstituteStatusClass($statusLabel);
    $isWaitingManager = $row['candidate_status'] === 'accepted'
        && in_array($row['leave_status'], ['matching', 'no_candidate', 'replacement_pending'], true);

    addRequestViewItem($items, [
        'item_type'     => 'substitute',
        'item_id'       => (int) $row['candidate_id'],
        'category'      => 'substitute',
        'category_label'=> requestViewFilterLabel('substitute'),
        'phase_label'   => '-',
        'title'         => '代勤申請',
        'status_label'  => $statusLabel,
        'status_class'  => $statusClass,
        'shift_label'   => requestViewShiftLabel($row),
        'date_label'    => $row['responded_at'] ?: $row['updated_at'],
        'sort_at'       => $row['responded_at'] ?: $row['updated_at'],
        'detail_html'   => requestViewDetailTable([
            '対象シフト'   => requestViewShiftLabel($row),
            '状態'         => $statusLabel,
            '休み申請者'   => $row['requester_name'],
            '休み申請理由' => $row['leave_reason'] ?: '-',
            '抽出理由'     => $row['match_reason'] ?: '-',
            '回答日時'     => $row['responded_at'] ?: '-',
        ]),
        'is_completed'  => !$isWaitingManager,
    ]);
}

// ------------------------------------------------------------
// 代勤キャンセル申請（承認前：代勤不可回答）
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT sc.id AS candidate_id, sc.status AS candidate_status,
            sc.responded_at, sc.updated_at, sc.match_reason,
            lr.reason AS leave_reason,
            requester.name AS requester_name,
            s.shift_date, s.start_time, s.end_time, s.position
     FROM substitute_candidates sc
     JOIN leave_requests lr ON lr.id = sc.leave_request_id
     JOIN shifts s ON s.id = lr.shift_id
     JOIN employees requester ON requester.id = lr.employee_id
     WHERE sc.candidate_employee_id = :employee_id
       AND sc.status = 'declined'
     ORDER BY COALESCE(sc.responded_at, sc.updated_at) DESC"
);
$stmt->execute(['employee_id' => $employeeId]);
foreach ($stmt->fetchAll() as $row) {
    addRequestViewItem($items, [
        'item_type'     => 'substitute_cancel_before',
        'item_id'       => (int) $row['candidate_id'],
        'category'      => 'substitute_cancel',
        'category_label'=> requestViewFilterLabel('substitute_cancel'),
        'phase_label'   => '承認前',
        'title'         => '代勤キャンセル申請',
        'status_label'  => '代勤不可回答済み',
        'status_class'  => 'badge-inactive',
        'shift_label'   => requestViewShiftLabel($row),
        'date_label'    => $row['responded_at'] ?: $row['updated_at'],
        'sort_at'       => $row['responded_at'] ?: $row['updated_at'],
        'detail_html'   => requestViewDetailTable([
            '対象シフト'   => requestViewShiftLabel($row),
            '区分'         => '承認前の代勤辞退',
            '状態'         => '代勤不可回答済み',
            '休み申請者'   => $row['requester_name'],
            '休み申請理由' => $row['leave_reason'] ?: '-',
            '回答日時'     => $row['responded_at'] ?: '-',
        ]),
        'is_completed'  => true,
    ]);
}

// ------------------------------------------------------------
// 代勤キャンセル申請（承認後）
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT cr.id AS cancellation_id, cr.status AS cancellation_status,
            cr.reason AS cancellation_reason, cr.created_at, cr.updated_at, cr.decided_at,
            requester.name AS requester_name,
            s.shift_date, s.start_time, s.end_time, s.position
     FROM cancellation_requests cr
     JOIN leave_requests lr ON lr.id = cr.leave_request_id
     JOIN shifts s ON s.id = lr.shift_id
     JOIN employees requester ON requester.id = lr.employee_id
     WHERE cr.request_type = 'substitute_after_approval'
       AND cr.requested_by_employee_id = :employee_id
     ORDER BY cr.created_at DESC"
);
$stmt->execute(['employee_id' => $employeeId]);
foreach ($stmt->fetchAll() as $row) {
    $statusLabel = requestViewCancelStatusLabel($row['cancellation_status']);
    $statusClass = requestViewCancelStatusClass($row['cancellation_status']);

    addRequestViewItem($items, [
        'item_type'     => 'substitute_cancel_after',
        'item_id'       => (int) $row['cancellation_id'],
        'category'      => 'substitute_cancel',
        'category_label'=> requestViewFilterLabel('substitute_cancel'),
        'phase_label'   => '承認後',
        'title'         => '代勤キャンセル申請',
        'status_label'  => $statusLabel,
        'status_class'  => $statusClass,
        'shift_label'   => requestViewShiftLabel($row),
        'date_label'    => $row['created_at'],
        'sort_at'       => $row['decided_at'] ?: ($row['updated_at'] ?: $row['created_at']),
        'detail_html'   => requestViewDetailTable([
            '対象シフト'     => requestViewShiftLabel($row),
            '区分'           => '承認後の代勤キャンセル',
            '状態'           => $statusLabel,
            '休み申請者'     => $row['requester_name'],
            'キャンセル理由' => $row['cancellation_reason'] ?: '-',
            '申請日時'       => $row['created_at'],
            '判断日時'       => $row['decided_at'] ?: '-',
        ]),
        'is_completed'  => in_array($row['cancellation_status'], ['approved', 'rejected'], true),
    ]);
}

$states = fetchRequestViewStates($pdo, $userId);
foreach ($items as &$item) {
    $state = $states[$item['key']] ?? ['is_hidden' => 0, 'is_favorite' => 0];
    $item['is_hidden'] = (int) $state['is_hidden'];
    $item['is_favorite'] = (int) $state['is_favorite'];
}
unset($item);

// ------------------------------------------------------------
// POST処理（表示状態のみ変更。申請本体は変更しない）
// ------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    $itemType = (string) ($_POST['item_type'] ?? '');
    $itemId = (int) ($_POST['item_id'] ?? 0);

    if ($action === 'toggle_favorite' && $itemType !== '' && $itemId > 0) {
        toggleRequestViewFavorite($pdo, $userId, $itemType, $itemId);
    } elseif ($action === 'delete' && $itemType !== '' && $itemId > 0) {
        hideRequestViewItem($pdo, $userId, $itemType, $itemId);
    } elseif ($action === 'delete_completed') {
        foreach ($items as $item) {
            if ($item['is_hidden'] || $item['is_favorite'] || !$item['is_completed']) {
                continue;
            }
            if ($filter !== 'all' && $item['category'] !== $filter) {
                continue;
            }
            hideRequestViewItem($pdo, $userId, $item['item_type'], (int) $item['item_id']);
        }
    }

    header('Location: ' . $redirectUrl);
    exit;
}

$visibleItems = array_values(array_filter($items, function (array $item) use ($filter): bool {
    if ($item['is_hidden']) {
        return false;
    }
    return $filter === 'all' || $item['category'] === $filter;
}));

usort($visibleItems, function (array $a, array $b): int {
    return strcmp((string) $b['sort_at'], (string) $a['sort_at']);
});

$totalItems = count($visibleItems);
$totalPages = max(1, (int) ceil($totalItems / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$pageItems = array_slice($visibleItems, $offset, $perPage);

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    休み申請・代勤申請・休みキャンセル申請・代勤キャンセル申請の状況を確認できます。
</p>
<p class="page-description notification-auto-delete-note">
    完了済みの申請表示は、対象シフト日から90日後に自動で非表示になります。保存したい申請は、お気に入り登録してください。
    ここでの削除は画面上の非表示のみで、申請履歴そのものは削除されません。
</p>

<div class="notification-toolbar">
    <details class="notification-filter-menu">
        <summary class="notification-filter-button" aria-label="申請の表示条件">⇅</summary>
        <div class="notification-filter-panel">
            <?php foreach (['all', 'leave', 'substitute', 'leave_cancel', 'substitute_cancel'] as $filterOption): ?>
                <a
                    class="notification-filter-link <?php echo $filter === $filterOption ? 'is-active' : ''; ?>"
                    href="<?php echo htmlspecialchars(requestViewPageUrl($filterOption)); ?>"
                >
                    <?php echo htmlspecialchars(requestViewFilterLabel($filterOption)); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </details>
    <details class="notification-menu">
        <summary class="notification-menu-button" aria-label="申請確認画面の操作メニュー">…</summary>
        <div class="notification-menu-panel">
            <form method="post" action="<?php echo htmlspecialchars($redirectUrl); ?>" onsubmit="return confirm('表示中の完了済み申請をすべて非表示にします。よろしいですか？\n※お気に入り登録済みの申請は非表示になりません。');">
                <input type="hidden" name="action" value="delete_completed">
                <button type="submit" class="btn btn-secondary">完了済みをすべて非表示</button>
            </form>
        </div>
    </details>
</div>

<div class="section">
    <p class="notification-count">
        <?php echo htmlspecialchars(requestViewFilterLabel($filter)); ?>：<?php echo (int) $totalItems; ?>件
        <?php if ($totalItems > 0): ?>
            （<?php echo (int) $page; ?> / <?php echo (int) $totalPages; ?>ページ）
        <?php endif; ?>
    </p>

    <?php if (empty($pageItems)): ?>
        <p class="page-description">表示できる申請はありません。</p>
    <?php else: ?>
        <div class="notification-list">
            <div class="notification-table-header">
                <span>申請</span>
                <span>操作</span>
            </div>
            <?php foreach ($pageItems as $item): ?>
                <?php $detailId = 'request-detail-' . htmlspecialchars($item['key']); ?>
                <div class="notification-card">
                    <div class="notification-summary-row">
                        <button
                            type="button"
                            class="notification-summary-button"
                            aria-haspopup="dialog"
                            data-request-detail="<?php echo htmlspecialchars($detailId); ?>"
                            data-request-title="<?php echo htmlspecialchars($item['title']); ?>"
                        >
                            <span class="notification-title">
                                <?php echo htmlspecialchars($item['title']); ?>
                                <?php if ($item['phase_label'] !== '-'): ?>
                                    （<?php echo htmlspecialchars($item['phase_label']); ?>）
                                <?php endif; ?>
                            </span>
                            <span class="notification-meta">
                                <?php echo htmlspecialchars($item['shift_label']); ?>
                                ・<?php echo htmlspecialchars($item['category_label']); ?>
                                ・<?php echo htmlspecialchars($item['date_label']); ?>
                            </span>
                            <span class="badge notification-status-badge <?php echo htmlspecialchars($item['status_class']); ?>">
                                <?php echo htmlspecialchars($item['status_label']); ?>
                            </span>
                        </button>
                        <div class="notification-actions">
                            <form method="post" action="<?php echo htmlspecialchars($redirectUrl); ?>">
                                <input type="hidden" name="action" value="toggle_favorite">
                                <input type="hidden" name="item_type" value="<?php echo htmlspecialchars($item['item_type']); ?>">
                                <input type="hidden" name="item_id" value="<?php echo (int) $item['item_id']; ?>">
                                <button
                                    type="submit"
                                    class="btn-icon-favorite <?php echo $item['is_favorite'] ? 'is-favorite' : ''; ?>"
                                    aria-label="<?php echo $item['is_favorite'] ? 'お気に入りを解除する' : 'お気に入りに登録する'; ?>"
                                >
                                    <?php echo $item['is_favorite'] ? '★' : '☆'; ?>
                                </button>
                            </form>
                            <form method="post" action="<?php echo htmlspecialchars($redirectUrl); ?>" onsubmit="return confirm('この申請を画面上で非表示にします。よろしいですか？\n※申請履歴そのものは削除されません。');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="item_type" value="<?php echo htmlspecialchars($item['item_type']); ?>">
                                <input type="hidden" name="item_id" value="<?php echo (int) $item['item_id']; ?>">
                                <button type="submit" class="btn-icon-danger" aria-label="申請を非表示にする">🗑</button>
                            </form>
                        </div>
                    </div>
                    <div id="<?php echo htmlspecialchars($detailId); ?>" class="notification-detail-source" hidden>
                        <?php echo $item['detail_html']; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="calendar-modal" data-request-modal hidden>
            <div class="calendar-modal-backdrop" data-request-modal-close></div>
            <div class="calendar-modal-panel" role="dialog" aria-modal="true" aria-labelledby="request-modal-title">
                <button type="button" class="calendar-modal-close" data-request-modal-close aria-label="閉じる">×</button>
                <h3 id="request-modal-title" data-request-modal-title>申請の詳細</h3>
                <div class="calendar-modal-body" data-request-modal-body></div>
            </div>
        </div>

        <?php renderPagination('page', $page, $totalPages); ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('click', function (event) {
    const modal = document.querySelector('[data-request-modal]');
    if (!modal) {
        return;
    }

    if (event.target.closest('[data-request-modal-close]')) {
        modal.hidden = true;
        document.body.classList.remove('calendar-modal-open');
        return;
    }

    const button = event.target.closest('[data-request-detail]');
    if (!button) {
        return;
    }

    const detail = document.getElementById(button.dataset.requestDetail);
    const title = modal.querySelector('[data-request-modal-title]');
    const body = modal.querySelector('[data-request-modal-body]');

    title.textContent = button.dataset.requestTitle || '申請の詳細';
    body.innerHTML = detail ? detail.innerHTML : '';
    modal.hidden = false;
    document.body.classList.add('calendar-modal-open');
});

document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') {
        return;
    }

    const modal = document.querySelector('[data-request-modal]');
    if (!modal) {
        return;
    }

    modal.hidden = true;
    document.body.classList.remove('calendar-modal-open');
});
</script>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
