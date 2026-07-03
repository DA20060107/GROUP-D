<?php
/**
 * 店長メニュー画面
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');

$pageTitle = '店長メニュー';
$basePath  = '../../public/';
$showBack  = false;
$showHome  = true;

require_once __DIR__ . '/../../app/includes/header.php';

$user = currentUser();
?>

<p class="page-description">
    店長用のメニューです。従業員情報の管理、シフトの作成・確認、通知や代勤承認の対応を行います。
</p>

<p class="page-description">ログイン中：<?php echo htmlspecialchars($user['name']); ?></p>

<ul class="menu-list">
    <li><a href="employees.php">従業員情報管理</a></li>
    <li><a href="manager_list.php">店長情報管理</a></li>
    <li><a href="shifts.php">シフト作成・一覧確認</a></li>
    <li><a href="notifications.php">通知確認</a></li>
    <li><a href="approvals.php">承認</a></li>
    <li><a class="logout" href="<?php echo $basePath; ?>logout.php">ログアウト</a></li>
</ul>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>

