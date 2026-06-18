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
// POST処理（既読にする）
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
    $id = (int) ($_POST['id'] ?? 0);

    // user_id を条件に含めることで、他人の通知を操作できないようにする
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id')
        ->execute(['id' => $id, 'user_id' => $userId]);

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
    'SELECT n.*, sc.id AS candidate_id, sc.status AS candidate_status
     FROM notifications n
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
                        <?php if ($n['type'] === 'substitute_request' && $n['candidate_id'] !== null): ?>
                            <br>
                            <?php if ($n['candidate_status'] === 'proposed'): ?>
                                <?php echo renderStatusBadge('未回答', 'badge-active'); ?>
                                <a class="btn btn-secondary" href="candidate_response.php?candidate_id=<?php echo (int) $n['candidate_id']; ?>">回答する</a>
                            <?php else: ?>
                                <?php echo renderStatusBadge(candidateStatusLabel($n['candidate_status']), candidateStatusBadgeClass($n['candidate_status'])); ?>
                                <a class="btn btn-secondary" href="candidate_response.php?candidate_id=<?php echo (int) $n['candidate_id']; ?>">詳細を見る</a>
                            <?php endif; ?>
                        <?php elseif ($n['type'] === 'approval_result'): ?>
                            <br>
                            <a class="btn btn-secondary" href="result.php">承認結果を確認する</a>
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

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
