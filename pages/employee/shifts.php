<?php
/**
 * シフト確認画面（従業員用）
 *
 * - ログイン中の従業員本人に割り当てられているシフトのみを表示する
 * - 代勤承認により担当者が自分に変更されたシフト（status = 'substituted'）も表示する
 *   （元の担当者からは employee_id が変わるため一覧に表示されなくなる。結果は承認結果確認画面で確認できる）
 * - 無効化（cancelled）されたシフトは表示しない
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('employee');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/includes/status_labels.php';

$pageTitle = 'シフト確認';
$basePath  = '../../public/';

$user       = currentUser();
$employeeId = (int) $user['employee_id'];

// ------------------------------------------------------------
// 自分のシフト一覧（無効化済みを除く）
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT * FROM shifts
     WHERE employee_id = :employee_id AND status <> 'cancelled'
     ORDER BY shift_date, start_time"
);
$stmt->execute(['employee_id' => $employeeId]);
$shifts = $stmt->fetchAll();

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    自分に割り当てられているシフトを確認できます。代勤として対応が確定したシフトもここに表示されます。
</p>

<div class="section">
    <table>
        <thead>
            <tr>
                <th>勤務日</th>
                <th>開始時刻</th>
                <th>終了時刻</th>
                <th>担当業務・ポジション</th>
                <th>備考</th>
                <th>状態</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($shifts)): ?>
            <tr>
                <td colspan="6">割り当てられているシフトはありません。</td>
            </tr>
            <?php else: ?>
                <?php foreach ($shifts as $shift): ?>
                <tr>
                    <td><?php echo htmlspecialchars($shift['shift_date']); ?></td>
                    <td><?php echo htmlspecialchars(substr($shift['start_time'], 0, 5)); ?></td>
                    <td><?php echo htmlspecialchars(substr($shift['end_time'], 0, 5)); ?></td>
                    <td><?php echo htmlspecialchars($shift['position'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($shift['note'] ?? ''); ?></td>
                    <td><?php echo renderStatusBadge(shiftStatusLabel($shift['status']), shiftStatusBadgeClass($shift['status'])); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
