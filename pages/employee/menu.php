<?php
/**
 * 従業員メニュー画面
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('employee');
require_once __DIR__ . '/../../app/config/database.php';

$pageTitle = '従業員メニュー';
$basePath  = '../../public/';
$showBack  = false;
// このページ自体がホーム（メニュー）なので、ホームボタンは表示しない
$showHome  = false;

require_once __DIR__ . '/../../app/includes/header.php';

$user = currentUser();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0');
$stmt->execute(['user_id' => (int) $user['id']]);
$unreadNotificationCount = (int) $stmt->fetchColumn();

function menuBadgeText(int $count): string
{
    return $count > 99 ? '99+' : (string) $count;
}
?>

<p class="page-description">
    従業員用のメニューです。勤務可能日・シフト・通知・申請状況を確認します。
</p>

<p class="page-description">ログイン中：<?php echo htmlspecialchars($user['name']); ?></p>

<ul class="employee-menu-list">
    <li>
        <a class="employee-menu-card" href="shifts.php">
            <span class="employee-menu-title">勤務可能日・シフト確認</span>
            <span class="employee-menu-description">勤務可能日の登録、自分のシフト確認、休み申請を行います。</span>
        </a>
    </li>
    <li>
        <a class="employee-menu-card" href="notifications.php">
            <?php if ($unreadNotificationCount > 0): ?>
                <span class="menu-card-badge"><?php echo htmlspecialchars(menuBadgeText($unreadNotificationCount)); ?></span>
            <?php endif; ?>
            <span class="employee-menu-title">通知確認</span>
            <span class="employee-menu-description">代勤依頼や申請結果など、自分宛の通知を確認します。</span>
        </a>
    </li>
    <li>
        <a class="employee-menu-card" href="result.php">
            <span class="employee-menu-title">申請確認</span>
            <span class="employee-menu-description">休み申請・代勤申請・キャンセル申請の状況を確認します。</span>
        </a>
    </li>
    <li>
        <a class="employee-menu-card employee-menu-card-logout" href="<?php echo $basePath; ?>logout.php">
            <span class="employee-menu-title">ログアウト</span>
            <span class="employee-menu-description">現在の従業員アカウントからログアウトします。</span>
        </a>
    </li>
</ul>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
