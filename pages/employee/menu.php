<?php
/**
 * 従業員メニュー画面
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('employee');

$pageTitle = '従業員メニュー';
$basePath  = '../../public/';
$showBack  = false;
// このページ自体がホーム（メニュー）なので、ホームボタンは表示しない
$showHome  = false;

require_once __DIR__ . '/../../app/includes/header.php';

$user = currentUser();
?>

<p class="page-description">
    従業員用のメニューです。シフトの確認、休み申請、通知・承認結果の確認を行います。
</p>

<p class="page-description">ログイン中：<?php echo htmlspecialchars($user['name']); ?></p>

<ul class="menu-list">
    <li><a href="shifts.php">シフト確認</a></li>
    <li><a href="availability.php">勤務可能日登録</a></li>
    <li><a href="leave_request.php">休み申請</a></li>
    <li><a href="notifications.php">通知確認</a></li>
    <li><a href="result.php">承認結果確認</a></li>
    <li><a class="logout" href="<?php echo $basePath; ?>logout.php">ログアウト</a></li>
</ul>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
