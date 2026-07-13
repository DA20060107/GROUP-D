<?php
/**
 * 承認後キャンセル申請確認画面（店長用）
 *
 * 現在は、休み申請者本人による requester_after_approval を承認・却下する。
 * 代勤者側キャンセルは将来拡張用で、今回は処理しない。
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/services/cancellation_request_service.php';
require_once __DIR__ . '/../../app/includes/status_labels.php';
require_once __DIR__ . '/../../app/includes/pagination.php';
require_once __DIR__ . '/../../app/includes/schema_helpers.php';
require_once __DIR__ . '/../../app/includes/request_view_helpers.php';

$pageTitle = 'キャンセル申請確認';
$basePath  = '../../public/';

$user = currentUser();
$managerId = (int) $user['id'];

$errorMessage   = '';
$successMessage = '';
$perPage = 10;
$processedPage = getPageNumber('processed_cancel_page');
$redirectUrl = 'cancellation_requests.php' . ($processedPage > 1 ? '?' . http_build_query(['processed_cancel_page' => $processedPage]) : '');

ensureManualSubstituteShiftSchema($pdo);
ensureRequestViewStatesTable($pdo);

function cleanupOldManagerCancellationRequests(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare(
        "SELECT cr.id
         FROM cancellation_requests cr
         JOIN leave_requests lr ON lr.id = cr.leave_request_id
         JOIN shifts s ON s.id = lr.shift_id
         LEFT JOIN request_view_states rvs
           ON rvs.user_id = :user_id_state
          AND rvs.item_type = 'manager_cancel'
          AND rvs.item_id = cr.id
         WHERE cr.status IN ('approved', 'rejected')
           AND s.shift_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
           AND COALESCE(rvs.is_favorite, 0) = 0"
    );
    $stmt->execute(['user_id_state' => $userId]);

    foreach ($stmt->fetchAll() as $row) {
        hideRequestViewItem($pdo, $userId, 'manager_cancel', (int) $row['id']);
    }
}

cleanupOldManagerCancellationRequests($pdo, $managerId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $cancellationRequestId = (int) ($_POST['cancellation_request_id'] ?? 0);

    if ($action === 'toggle_favorite' && $cancellationRequestId > 0) {
        toggleRequestViewFavorite($pdo, $managerId, 'manager_cancel', $cancellationRequestId);
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'delete_processed' && $cancellationRequestId > 0) {
        hideRequestViewItem($pdo, $managerId, 'manager_cancel', $cancellationRequestId);
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'hide_all_processed') {
        $stmt = $pdo->prepare(
            "INSERT INTO request_view_states
                (user_id, item_type, item_id, is_hidden, is_favorite, hidden_at)
             SELECT :insert_user_id, 'manager_cancel', cr.id, 1, 0, NOW()
             FROM cancellation_requests cr
             LEFT JOIN request_view_states rvs
               ON rvs.user_id = :join_user_id
              AND rvs.item_type = 'manager_cancel'
              AND rvs.item_id = cr.id
             WHERE cr.status IN ('approved', 'rejected')
               AND COALESCE(rvs.is_hidden, 0) = 0
               AND COALESCE(rvs.is_favorite, 0) = 0
             ON DUPLICATE KEY UPDATE
                is_hidden = IF(is_favorite = 1, is_hidden, 1),
                hidden_at = IF(is_favorite = 1, hidden_at, NOW())"
        );
        $stmt->execute([
            'insert_user_id' => $managerId,
            'join_user_id'   => $managerId,
        ]);
        header('Location: cancellation_requests.php?msg=bulk_hidden');
        exit;
    }

    if (!in_array($action, ['approve', 'reject'], true) || $cancellationRequestId <= 0) {
        $errorMessage = '処理対象のキャンセル申請を確認できませんでした。';
    } else {
        $decision = $action === 'approve' ? 'approved' : 'rejected';

        // 申請種別を確認し、休み申請者側 / 代勤者側で処理を振り分ける
        $typeStmt = $pdo->prepare('SELECT request_type FROM cancellation_requests WHERE id = :id');
        $typeStmt->execute(['id' => $cancellationRequestId]);
        $requestType = $typeStmt->fetchColumn();

        if ($requestType === CANCELLATION_TYPE_SUBSTITUTE_AFTER_APPROVAL) {
            $result = decideSubstituteAfterApprovalCancellationRequest(
                $pdo,
                $cancellationRequestId,
                $managerId,
                $decision
            );
            $approvedMsg = 'approved_substitute';
        } else {
            $result = decideAfterApprovalCancellationRequest(
                $pdo,
                $cancellationRequestId,
                $managerId,
                $decision
            );
            $approvedMsg = 'approved';
        }

        if ($result === 'approved') {
            header('Location: cancellation_requests.php?msg=' . $approvedMsg . '#cr-' . $cancellationRequestId);
            exit;
        }
        if ($result === 'rejected') {
            header('Location: cancellation_requests.php?msg=rejected#cr-' . $cancellationRequestId);
            exit;
        }
        if ($result === 'already_decided') {
            $errorMessage = 'このキャンセル申請は既に処理済みです。';
        } elseif ($result === 'invalid_type') {
            $errorMessage = 'この申請種別は現在の処理対象ではありません。';
        } elseif ($result === 'not_eligible') {
            $errorMessage = '元の休み申請またはシフトの状態が変わったため処理できません。';
        } else {
            $errorMessage = '指定されたキャンセル申請が見つかりません。';
        }
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'approved') {
        $successMessage = 'キャンセル申請を承認し、シフト担当者を元の休み申請者へ戻しました。';
    } elseif ($_GET['msg'] === 'approved_substitute') {
        $successMessage = 'キャンセル申請を承認しました。代勤者の再調整を行ってください。';
    } elseif ($_GET['msg'] === 'rejected') {
        $successMessage = 'キャンセル申請を却下しました。現在の代勤状態は維持されます。';
    } elseif ($_GET['msg'] === 'bulk_hidden') {
        $successMessage = 'お気に入り以外の処理済みキャンセル申請を一括非表示にしました。';
    }
}

$baseSelect = "
    SELECT cr.id AS cancellation_request_id, cr.request_type, cr.reason,
           cr.status AS cancellation_status, cr.created_at,
           cr.decided_at, lr.id AS leave_request_id, lr.status AS leave_status,
           requester.name AS requester_name,
           COALESCE(approved_sub.name, manual_sub.name) AS substitute_name,
           current_emp.name AS current_shift_employee_name,
           s.shift_date, s.start_time, s.end_time, s.position,
           decider.name AS decided_by_name,
           COALESCE(rvs.is_favorite, 0) AS is_favorite
    FROM cancellation_requests cr
    JOIN leave_requests lr ON lr.id = cr.leave_request_id
    JOIN shifts s ON s.id = lr.shift_id
    JOIN employees requester ON requester.id = lr.employee_id
    JOIN employees current_emp ON current_emp.id = s.employee_id
    LEFT JOIN approvals a
       ON a.leave_request_id = lr.id
      AND a.status = 'approved'
    LEFT JOIN substitute_candidates approved_sc
       ON approved_sc.id = a.substitute_candidate_id
    LEFT JOIN employees approved_sub
       ON approved_sub.id = approved_sc.candidate_employee_id
    LEFT JOIN shifts manual_s
       ON manual_s.id = (
           SELECT MAX(ms2.id)
           FROM shifts ms2
           WHERE ms2.related_leave_request_id = lr.id
             AND ms2.employee_id = cr.requested_by_employee_id
       )
    LEFT JOIN employees manual_sub
       ON manual_sub.id = manual_s.employee_id
    LEFT JOIN users decider ON decider.id = cr.decided_by_user_id
    LEFT JOIN request_view_states rvs
       ON rvs.user_id = :view_user_id
      AND rvs.item_type = 'manager_cancel'
      AND rvs.item_id = cr.id
";

$pendingStmt = $pdo->prepare(
    $baseSelect
    . " WHERE cr.status = 'pending'
        ORDER BY cr.created_at"
);
$pendingStmt->execute(['view_user_id' => $managerId]);
$pendingRequests = $pendingStmt->fetchAll();

$processedCountStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM cancellation_requests cr
     LEFT JOIN request_view_states rvs
       ON rvs.user_id = :view_user_id
      AND rvs.item_type = 'manager_cancel'
      AND rvs.item_id = cr.id
     WHERE cr.status IN ('approved', 'rejected')
       AND COALESCE(rvs.is_hidden, 0) = 0"
);
$processedCountStmt->execute(['view_user_id' => $managerId]);
$processedTotalCount = (int) $processedCountStmt->fetchColumn();
$processedTotalPages = getTotalPages($processedTotalCount, $perPage);
if ($processedPage > $processedTotalPages) {
    $processedPage = $processedTotalPages;
}
$processedOffset = ($processedPage - 1) * $perPage;

$processedStmt = $pdo->prepare(
    $baseSelect
    . " WHERE cr.status IN ('approved', 'rejected')
          AND COALESCE(rvs.is_hidden, 0) = 0
        ORDER BY cr.decided_at DESC, cr.id DESC
        LIMIT :limit OFFSET :offset"
);
$processedStmt->bindValue(':view_user_id', $managerId, PDO::PARAM_INT);
$processedStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$processedStmt->bindValue(':offset', $processedOffset, PDO::PARAM_INT);
$processedStmt->execute();
$processedRequests = $processedStmt->fetchAll();

// 通知確認画面から来た場合は「戻る」先を通知確認画面にする（それ以外はメニュー）
if (($_GET['from'] ?? '') === 'notifications') {
    $backUrl = 'notifications.php';
}

function managerCancellationShiftLabel(array $request): string
{
    $label = $request['shift_date'] . ' ' . substr($request['start_time'], 0, 5) . '-' . substr($request['end_time'], 0, 5);
    if (!empty($request['position'])) {
        $label .= '（' . $request['position'] . '）';
    }
    return $label;
}

function managerCancellationImpactLabel(string $requestType): string
{
    if ($requestType === CANCELLATION_TYPE_SUBSTITUTE_AFTER_APPROVAL) {
        return '承認すると、対象シフトは代勤者再調整中になります。シフト担当者はすぐには元に戻りません。';
    }

    return '承認すると、対象シフトの担当者が元の休み申請者に戻ります。';
}

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    承認済みの休み申請に対するキャンセル申請を確認し、承認または却下します。
</p>

<div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
<div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>

<div class="section">
    <h2>未処理のキャンセル申請</h2>
    <?php if (empty($pendingRequests)): ?>
        <p class="page-description">未処理のキャンセル申請はありません。</p>
    <?php else: ?>
        <div class="manager-card-list">
        <?php foreach ($pendingRequests as $request): ?>
        <article class="manager-work-card" id="cr-<?php echo (int) $request['cancellation_request_id']; ?>">
            <div class="manager-work-card-header">
                <div>
                    <h3><?php echo htmlspecialchars(cancellationRequestTypeLabel($request['request_type'])); ?></h3>
                    <p><?php echo htmlspecialchars(managerCancellationShiftLabel($request)); ?></p>
                </div>
                <?php echo renderStatusBadge(cancellationRequestStatusLabel($request['cancellation_status']), cancellationRequestStatusBadgeClass($request['cancellation_status'])); ?>
            </div>

            <div class="manager-detail-grid">
                <div><span>休み申請者</span><strong><?php echo htmlspecialchars($request['requester_name']); ?></strong></div>
                <div><span>代勤者</span><strong><?php echo htmlspecialchars($request['substitute_name'] ?? '-'); ?></strong></div>
                <div><span>現在の担当者</span><strong><?php echo htmlspecialchars($request['current_shift_employee_name']); ?></strong></div>
                <div><span>申請日時</span><strong><?php echo htmlspecialchars($request['created_at']); ?></strong></div>
            </div>

            <p class="manager-card-note"><?php echo htmlspecialchars(managerCancellationImpactLabel($request['request_type'])); ?></p>
            <p class="manager-card-reason">
                <span>キャンセル理由</span>
                <?php echo nl2br(htmlspecialchars($request['reason'] ?: '-')); ?>
            </p>

            <div class="manager-card-actions">
                <form method="post" action="cancellation_requests.php">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="cancellation_request_id" value="<?php echo (int) $request['cancellation_request_id']; ?>">
                    <button type="submit" class="btn">キャンセルを承認</button>
                </form>
                <form method="post" action="cancellation_requests.php">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="cancellation_request_id" value="<?php echo (int) $request['cancellation_request_id']; ?>">
                    <button type="submit" class="btn btn-secondary">キャンセルを却下</button>
                </form>
            </div>
        </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="section">
    <h2>処理済みのキャンセル申請</h2>
    <?php if (!empty($processedRequests)): ?>
    <div class="notification-toolbar notification-toolbar-right">
        <details class="notification-menu">
            <summary class="notification-menu-button" aria-label="処理済みキャンセル申請の操作メニュー">…</summary>
            <div class="notification-menu-panel">
                <form method="post" action="<?php echo htmlspecialchars($redirectUrl); ?>" onsubmit="return confirm('お気に入り以外の処理済みキャンセル申請をすべて非表示にします。よろしいですか？\n※申請履歴そのものは削除されません。');">
                    <input type="hidden" name="action" value="hide_all_processed">
                    <button type="submit" class="btn btn-secondary">一括非表示</button>
                </form>
            </div>
        </details>
    </div>
    <?php endif; ?>
    <?php if (empty($processedRequests)): ?>
        <p class="page-description">処理済みのキャンセル申請はありません。</p>
    <?php else: ?>
        <div class="notification-list">
            <div class="notification-table-header">
                <span>キャンセル申請</span>
                <span>結果</span>
            </div>
            <?php foreach ($processedRequests as $request): ?>
                <?php $detailId = 'processed-cancel-' . (int) $request['cancellation_request_id']; ?>
                <div class="notification-card" id="cr-<?php echo (int) $request['cancellation_request_id']; ?>">
                    <div class="notification-summary-row">
                        <button
                            type="button"
                            class="notification-summary-button"
                            data-manager-detail="<?php echo htmlspecialchars($detailId); ?>"
                            data-manager-title="処理済みのキャンセル申請"
                        >
                            <span class="notification-title"><?php echo htmlspecialchars(cancellationRequestTypeLabel($request['request_type'])); ?></span>
                            <span class="notification-meta">
                                <?php echo htmlspecialchars($request['requester_name']); ?>
                                ・<?php echo htmlspecialchars(managerCancellationShiftLabel($request)); ?>
                                ・<?php echo htmlspecialchars($request['decided_at'] ?? ''); ?>
                            </span>
                            <span class="badge notification-status-badge <?php echo htmlspecialchars(cancellationRequestStatusBadgeClass($request['cancellation_status'])); ?>">
                                <?php echo htmlspecialchars(cancellationRequestStatusLabel($request['cancellation_status'])); ?>
                            </span>
                        </button>
                        <div class="notification-actions">
                            <form method="post" action="<?php echo htmlspecialchars($redirectUrl); ?>">
                                <input type="hidden" name="action" value="toggle_favorite">
                                <input type="hidden" name="cancellation_request_id" value="<?php echo (int) $request['cancellation_request_id']; ?>">
                                <button
                                    type="submit"
                                    class="btn-icon-favorite <?php echo $request['is_favorite'] ? 'is-favorite' : ''; ?>"
                                    aria-label="<?php echo $request['is_favorite'] ? 'お気に入りを解除する' : 'お気に入りに登録する'; ?>"
                                >
                                    <?php echo $request['is_favorite'] ? '★' : '☆'; ?>
                                </button>
                            </form>
                            <form method="post" action="<?php echo htmlspecialchars($redirectUrl); ?>" onsubmit="return confirm('この処理済みキャンセル申請を画面上で非表示にします。よろしいですか？\n※申請履歴そのものは削除されません。');">
                                <input type="hidden" name="action" value="delete_processed">
                                <input type="hidden" name="cancellation_request_id" value="<?php echo (int) $request['cancellation_request_id']; ?>">
                                <button type="submit" class="btn-icon-danger" aria-label="処理済みキャンセル申請を非表示にする">🗑</button>
                            </form>
                        </div>
                    </div>
                    <div id="<?php echo htmlspecialchars($detailId); ?>" class="notification-detail-source" hidden>
                        <table>
                            <tbody>
                                <tr><th>処理日時</th><td><?php echo htmlspecialchars($request['decided_at'] ?? '-'); ?></td></tr>
                                <tr><th>申請種別</th><td><?php echo htmlspecialchars(cancellationRequestTypeLabel($request['request_type'])); ?></td></tr>
                                <tr><th>休み申請者</th><td><?php echo htmlspecialchars($request['requester_name']); ?></td></tr>
                                <tr><th>代勤者</th><td><?php echo htmlspecialchars($request['substitute_name'] ?? '-'); ?></td></tr>
                                <tr><th>対象シフト</th><td><?php echo htmlspecialchars(managerCancellationShiftLabel($request)); ?></td></tr>
                                <tr><th>結果</th><td><?php echo renderStatusBadge(cancellationRequestStatusLabel($request['cancellation_status']), cancellationRequestStatusBadgeClass($request['cancellation_status'])); ?></td></tr>
                                <tr><th>処理した店長</th><td><?php echo htmlspecialchars($request['decided_by_name'] ?? '-'); ?></td></tr>
                                <tr><th>キャンセル理由</th><td><?php echo nl2br(htmlspecialchars($request['reason'] ?? '-')); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php renderPagination('processed_cancel_page', $processedPage, $processedTotalPages); ?>
</div>

<div class="calendar-modal" data-manager-modal hidden>
    <div class="calendar-modal-backdrop" data-manager-modal-close></div>
    <div class="calendar-modal-panel" role="dialog" aria-modal="true" aria-labelledby="manager-modal-title">
        <button type="button" class="calendar-modal-close" data-manager-modal-close aria-label="閉じる">×</button>
        <h3 id="manager-modal-title" data-manager-modal-title>詳細</h3>
        <div class="calendar-modal-body" data-manager-modal-body></div>
    </div>
</div>

<script>
document.addEventListener('click', function (event) {
    const modal = document.querySelector('[data-manager-modal]');
    if (!modal) {
        return;
    }

    if (event.target.closest('[data-manager-modal-close]')) {
        modal.hidden = true;
        document.body.classList.remove('calendar-modal-open');
        return;
    }

    const button = event.target.closest('[data-manager-detail]');
    if (!button) {
        return;
    }

    const detail = document.getElementById(button.dataset.managerDetail);
    modal.querySelector('[data-manager-modal-title]').textContent = button.dataset.managerTitle || '詳細';
    modal.querySelector('[data-manager-modal-body]').innerHTML = detail ? detail.innerHTML : '';
    modal.hidden = false;
    document.body.classList.add('calendar-modal-open');
});
</script>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
