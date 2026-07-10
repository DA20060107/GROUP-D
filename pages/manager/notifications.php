<?php
/**
 * 通知確認画面（店長用）
 *
 * - ログイン中の店長ユーザー宛の通知のみを表示する
 * - 代勤候補が見つからなかった場合の「候補者なし」通知もここで確認できる
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/includes/status_labels.php';

$pageTitle = '通知確認';
$basePath  = '../../public/';

$user   = currentUser();
$userId = (int) $user['id'];

// ------------------------------------------------------------
// POST処理（既読・削除）
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'mark_read' && $id > 0) {
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
// 自分（店長）宛の通知一覧
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT n.*, lr.status AS leave_status
     FROM notifications n
     LEFT JOIN leave_requests lr ON lr.id = n.related_leave_request_id
     WHERE n.user_id = :user_id
     ORDER BY n.created_at DESC'
);
$stmt->execute(['user_id' => $userId]);
$notifications = $stmt->fetchAll();

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    休み申請や代勤候補の抽出結果など、店長宛の通知を確認します。
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
                            <?php if (
                                in_array($n['type'], ['candidate_available', 'no_candidate'], true)
                                && $n['leave_status'] === 'cancelled'
                            ): ?>
                                <?php echo renderStatusBadge('キャンセル済み・対応不要', 'badge-inactive'); ?>
                            <?php elseif ($n['type'] === 'candidate_available'): ?>
                                <?php if ($n['related_leave_request_id'] !== null): ?>
                                    <a class="btn btn-secondary" href="approvals.php?from=notifications#lr-<?php echo (int) $n['related_leave_request_id']; ?>">承認画面で確認する</a>
                                <?php else: ?>
                                    <a class="btn btn-secondary" href="approvals.php?from=notifications">承認画面で確認する</a>
                                <?php endif; ?>
                            <?php elseif (in_array($n['type'], ['no_candidate', 'rematch_no_candidate'], true)): ?>
                                <?php echo renderStatusBadge('手動対応が必要', 'badge-warning'); ?>
                                <?php if ($n['related_leave_request_id'] !== null): ?>
                                    <a class="btn btn-secondary" href="approvals.php?from=notifications#lr-<?php echo (int) $n['related_leave_request_id']; ?>">承認画面で確認・再抽出する</a>
                                <?php else: ?>
                                    <a class="btn btn-secondary" href="approvals.php?from=notifications">承認画面で確認・再抽出する</a>
                                <?php endif; ?>
                            <?php elseif (in_array($n['type'], [
                                'after_approval_cancel_requested',
                                'substitute_cancel_requested',
                            ], true)): ?>
                                <a class="btn btn-secondary" href="cancellation_requests.php?from=notifications">キャンセル申請を確認する</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="section">
    <a class="btn" href="approvals.php?from=notifications">承認画面へ</a>
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
