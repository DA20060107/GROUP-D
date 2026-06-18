<?php
/**
 * 承認結果確認画面（従業員用）
 *
 * - ログイン中の従業員本人宛の「承認結果」通知（type = 'approval_result'）を一覧表示する
 * - 休み申請に関する通知は、関連する休み申請・シフト情報も合わせて表示する
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('employee');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/includes/status_labels.php';

$pageTitle = '承認結果確認';
$basePath  = '../../public/';

$user   = currentUser();
$userId = (int) $user['id'];

// ------------------------------------------------------------
// 自分宛の承認結果通知一覧
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT n.*, lr.status AS leave_status,
            s.shift_date, s.start_time, s.end_time, s.position
     FROM notifications n
     LEFT JOIN leave_requests lr ON lr.id = n.related_leave_request_id
     LEFT JOIN shifts s ON s.id = lr.shift_id
     WHERE n.user_id = :user_id AND n.type = :type
     ORDER BY n.created_at DESC'
);
$stmt->execute(['user_id' => $userId, 'type' => 'approval_result']);
$results = $stmt->fetchAll();

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    休み申請や代勤に関する承認結果を確認できます。
</p>

<div class="section">
    <table>
        <thead>
            <tr>
                <th>日時</th>
                <th>勤務日</th>
                <th>開始時刻</th>
                <th>終了時刻</th>
                <th>担当業務・ポジション</th>
                <th>申請状態</th>
                <th>タイトル</th>
                <th>内容</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($results)): ?>
            <tr>
                <td colspan="8">承認結果はありません。</td>
            </tr>
            <?php else: ?>
                <?php foreach ($results as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                    <td><?php echo htmlspecialchars($r['shift_date'] ?? '-'); ?></td>
                    <td><?php echo $r['start_time'] !== null ? htmlspecialchars(substr($r['start_time'], 0, 5)) : '-'; ?></td>
                    <td><?php echo $r['end_time'] !== null ? htmlspecialchars(substr($r['end_time'], 0, 5)) : '-'; ?></td>
                    <td><?php echo htmlspecialchars($r['position'] ?? '-'); ?></td>
                    <td><?php echo $r['leave_status'] !== null ? renderStatusBadge(leaveRequestStatusLabel($r['leave_status']), leaveRequestStatusBadgeClass($r['leave_status'])) : '-'; ?></td>
                    <td><?php echo htmlspecialchars($r['title']); ?></td>
                    <td><?php echo nl2br(htmlspecialchars($r['message'])); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
