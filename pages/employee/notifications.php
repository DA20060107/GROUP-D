<?php
/**
 * 通知確認画面（従業員用）
 *
 * - ログイン中の従業員本人宛の通知のみを表示する
 * - 代勤依頼（substitute_request）通知には、本人の代勤提案への応答画面
 *   （candidate_response.php）へのリンクを表示する
 * - 回答済みの代勤提案は、リンクの代わりに回答状況（代勤可能／代勤不可）を表示する
 * - 承認結果（approval_result）通知には、承認結果確認画面（result.php）へのリンクを表示する
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('employee');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/includes/status_labels.php';

$pageTitle = '通知確認';
$basePath  = '../../public/';

$user       = currentUser();
$userId     = (int) $user['id'];
$employeeId = (int) $user['employee_id'];

// ------------------------------------------------------------
// POST処理（既読・削除）
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'mark_read' && $id > 0) {
        // user_id を条件に含めることで、他人の通知を操作できないようにする
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id')
            ->execute(['id' => $id, 'user_id' => $userId]);
    } elseif ($action === 'delete' && $id > 0) {
        $pdo->prepare('DELETE FROM notifications WHERE id = :id AND user_id = :user_id')
            ->execute(['id' => $id, 'user_id' => $userId]);
    } elseif ($action === 'mark_all_read') {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id')
            ->execute(['user_id' => $userId]);
    } elseif ($action === 'delete_read') {
        $pdo->prepare('DELETE FROM notifications WHERE user_id = :user_id AND is_read = 1')
            ->execute(['user_id' => $userId]);
    }

    header('Location: notifications.php');
    exit;
}

// ------------------------------------------------------------
// 自分宛の通知一覧
//
// substitute_candidates を LEFT JOIN し、代勤依頼通知に対する
// 自分自身の代勤提案（candidate_id・回答状況）を取得する。
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT n.*, sc.id AS candidate_id, sc.status AS candidate_status,
            lr.status AS leave_status
     FROM notifications n
     LEFT JOIN leave_requests lr
        ON lr.id = n.related_leave_request_id
     LEFT JOIN substitute_candidates sc
         ON sc.leave_request_id = n.related_leave_request_id
        AND sc.candidate_employee_id = :employee_id
     WHERE n.user_id = :user_id
     ORDER BY n.created_at DESC'
);
$stmt->execute(['user_id' => $userId, 'employee_id' => $employeeId]);
$notifications = $stmt->fetchAll();

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    代勤の依頼や申請結果など、自分宛の通知を確認します。
</p>

<div class="notification-toolbar">
    <details class="notification-menu">
        <summary class="notification-menu-button" aria-label="通知操作メニュー">…</summary>
        <div class="notification-menu-panel">
            <form method="post" action="notifications.php" onsubmit="return confirm('すべての通知を既読にします。よろしいですか？');">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn btn-secondary">すべて既読にする</button>
            </form>
            <form method="post" action="notifications.php" onsubmit="return confirm('既読の通知をすべて削除します。よろしいですか？');">
                <input type="hidden" name="action" value="delete_read">
                <button type="submit" class="btn btn-secondary">既読をすべて削除</button>
            </form>
        </div>
    </details>
</div>

<div class="section">
    <?php if (empty($notifications)): ?>
        <p class="page-description">通知はありません。</p>
    <?php else: ?>
        <div class="notification-list">
            <?php foreach ($notifications as $n): ?>
                <?php $detailId = 'notification-detail-' . (int) $n['id']; ?>
                <div class="notification-card <?php echo $n['is_read'] ? '' : 'is-unread'; ?>">
                    <div class="notification-summary-row">
                        <button
                            type="button"
                            class="notification-summary-button"
                            aria-expanded="false"
                            aria-controls="<?php echo htmlspecialchars($detailId); ?>"
                            onclick="toggleNotificationDetail(this, '<?php echo htmlspecialchars($detailId); ?>')"
                        >
                            <span class="notification-title"><?php echo htmlspecialchars($n['title']); ?></span>
                            <span class="notification-meta">
                                <?php echo htmlspecialchars($n['created_at']); ?>
                                ・<?php echo htmlspecialchars(notificationTypeLabel($n['type'])); ?>
                            </span>
                            <span class="badge <?php echo $n['is_read'] ? 'badge-inactive' : 'badge-active'; ?>">
                                <?php echo $n['is_read'] ? '既読' : '未読'; ?>
                            </span>
                        </button>
                        <div class="notification-actions">
                            <?php if (!$n['is_read']): ?>
                            <form method="post" action="notifications.php">
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="id" value="<?php echo (int) $n['id']; ?>">
                                <button type="submit" class="btn btn-secondary btn-small">既読</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" action="notifications.php" onsubmit="return confirm('この通知を削除します。よろしいですか？');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $n['id']; ?>">
                                <button type="submit" class="btn-icon-danger" aria-label="通知を削除する">🗑</button>
                            </form>
                        </div>
                    </div>
                    <div id="<?php echo htmlspecialchars($detailId); ?>" class="notification-detail" hidden>
                        <p><?php echo nl2br(htmlspecialchars($n['message'])); ?></p>
                        <div class="notification-detail-actions">
                            <?php if ($n['type'] === 'substitute_request' && $n['candidate_id'] !== null): ?>
                                <?php if ($n['leave_status'] === 'cancelled'): ?>
                                    <?php echo renderStatusBadge('キャンセル済み・回答不要', 'badge-inactive'); ?>
                                <?php elseif ($n['candidate_status'] === 'proposed'): ?>
                                    <?php echo renderStatusBadge('未回答', 'badge-active'); ?>
                                    <a class="btn btn-secondary" href="candidate_response.php?candidate_id=<?php echo (int) $n['candidate_id']; ?>">回答する</a>
                                <?php else: ?>
                                    <?php echo renderStatusBadge(candidateStatusLabel($n['candidate_status']), candidateStatusBadgeClass($n['candidate_status'])); ?>
                                    <a class="btn btn-secondary" href="candidate_response.php?candidate_id=<?php echo (int) $n['candidate_id']; ?>">詳細を見る</a>
                                <?php endif; ?>
                            <?php elseif (in_array($n['type'], [
                                'approval_result',
                                'after_approval_cancel_approved',
                                'after_approval_cancel_rejected',
                                'replacement_pending',
                            ], true)): ?>
                                <a class="btn btn-secondary" href="result.php?from=notifications">承認結果を確認する</a>
                            <?php elseif (in_array($n['type'], [
                                'substitute_cancel_approved',
                                'substitute_cancel_rejected',
                            ], true)): ?>
                                <a class="btn btn-secondary" href="shifts.php">シフトを確認する</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleNotificationDetail(button, detailId) {
    const detail = document.getElementById(detailId);
    if (!detail) {
        return;
    }

    const isOpen = !detail.hidden;
    detail.hidden = isOpen;
    button.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
}
</script>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
