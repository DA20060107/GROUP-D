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
// POST処理（既読にする）
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
    $id = (int) ($_POST['id'] ?? 0);

    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id')
        ->execute(['id' => $id, 'user_id' => $userId]);

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

<div class="section">
    <table>
        <thead>
            <tr>
                <th>日時</th>
                <th>種別</th>
                <th>タイトル</th>
                <th>内容</th>
                <th>状態</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($notifications)): ?>
            <tr>
                <td colspan="6">通知はありません。</td>
            </tr>
            <?php else: ?>
                <?php foreach ($notifications as $n): ?>
                <tr>
                    <td><?php echo htmlspecialchars($n['created_at']); ?></td>
                    <td><?php echo htmlspecialchars(notificationTypeLabel($n['type'])); ?></td>
                    <td><?php echo htmlspecialchars($n['title']); ?></td>
                    <td>
                        <?php echo nl2br(htmlspecialchars($n['message'])); ?>
                        <?php if (
                            in_array($n['type'], ['candidate_available', 'no_candidate'], true)
                            && $n['leave_status'] === 'cancelled'
                        ): ?>
                            <br>
                            <?php echo renderStatusBadge('キャンセル済み・対応不要', 'badge-inactive'); ?>
                        <?php elseif ($n['type'] === 'candidate_available'): ?>
                            <br>
                            <?php if ($n['related_leave_request_id'] !== null): ?>
                                <a class="btn btn-secondary" href="approvals.php#lr-<?php echo (int) $n['related_leave_request_id']; ?>">承認画面で確認する</a>
                            <?php else: ?>
                                <a class="btn btn-secondary" href="approvals.php">承認画面で確認する</a>
                            <?php endif; ?>
                        <?php elseif ($n['type'] === 'no_candidate'): ?>
                            <br>
                            <?php echo renderStatusBadge('手動対応が必要', 'badge-warning'); ?>
                            <?php if ($n['related_leave_request_id'] !== null): ?>
                                <a class="btn btn-secondary" href="approvals.php#lr-<?php echo (int) $n['related_leave_request_id']; ?>">承認画面で確認する</a>
                            <?php else: ?>
                                <a class="btn btn-secondary" href="approvals.php">承認画面で確認する</a>
                            <?php endif; ?>
                        <?php elseif (in_array($n['type'], [
                            'after_approval_cancel_requested',
                            'substitute_cancel_requested',
                        ], true)): ?>
                            <br>
                            <a class="btn btn-secondary" href="cancellation_requests.php">キャンセル申請を確認する</a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?php echo $n['is_read'] ? 'badge-inactive' : 'badge-active'; ?>">
                            <?php echo $n['is_read'] ? '既読' : '未読'; ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!$n['is_read']): ?>
                        <form method="post" action="notifications.php">
                            <input type="hidden" name="action" value="mark_read">
                            <input type="hidden" name="id" value="<?php echo (int) $n['id']; ?>">
                            <button type="submit" class="btn btn-secondary">既読にする</button>
                        </form>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="section">
    <a class="btn" href="approvals.php">承認画面へ</a>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
