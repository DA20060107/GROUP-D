<?php
/**
 * 店長メニュー画面
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');
require_once __DIR__ . '/../../app/config/database.php';

$pageTitle = '店長メニュー';
$basePath  = '../../public/';
$showBack  = false;
$showHome  = false;

require_once __DIR__ . '/../../app/includes/header.php';

$user = currentUser();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = 0');
$stmt->execute(['user_id' => (int) $user['id']]);
$unreadNotificationCount = (int) $stmt->fetchColumn();

$pendingApprovalCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM leave_requests WHERE status IN ('matching', 'no_candidate', 'replacement_pending')"
)->fetchColumn();

$pendingCancellationCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM cancellation_requests WHERE status = 'pending'"
)->fetchColumn();

function menuBadgeText(int $count): string
{
    return $count > 99 ? '99+' : (string) $count;
}
?>

<p class="page-description">
    店長用のメニューです。従業員・シフト・通知・代勤候補抽出設定を管理します。
</p>

<p class="page-description">ログイン中：<?php echo htmlspecialchars($user['name']); ?></p>

<ul class="manager-menu-list">
    <li>
        <a class="manager-menu-card" href="employees.php">
            <span class="manager-menu-title">従業員管理</span>
            <span class="manager-menu-description">従業員情報とログインアカウントを管理します。</span>
        </a>
    </li>
    <li>
        <a class="manager-menu-card" href="manager_list.php">
            <span class="manager-menu-title">店長管理</span>
            <span class="manager-menu-description">店長アカウントの登録・一覧確認を行います。</span>
        </a>
    </li>
    <li>
        <a class="manager-menu-card" href="shifts.php">
            <span class="manager-menu-title">シフト管理</span>
            <span class="manager-menu-description">シフトの作成・確認・無効化を行います。</span>
        </a>
    </li>
    <li>
        <a class="manager-menu-card" href="notifications.php">
            <?php if ($unreadNotificationCount > 0): ?>
                <span class="menu-card-badge"><?php echo htmlspecialchars(menuBadgeText($unreadNotificationCount)); ?></span>
            <?php endif; ?>
            <span class="manager-menu-title">通知確認</span>
            <span class="manager-menu-description">申請・候補者回答・キャンセル申請などの通知を確認します。</span>
        </a>
    </li>
    <li>
        <a class="manager-menu-card" href="approvals.php">
            <?php if ($pendingApprovalCount > 0): ?>
                <span class="menu-card-badge"><?php echo htmlspecialchars(menuBadgeText($pendingApprovalCount)); ?></span>
            <?php endif; ?>
            <span class="manager-menu-title">承認画面</span>
            <span class="manager-menu-description">未処理の休み申請を確認し、承認・却下・代勤候補の再抽出を行います。</span>
        </a>
    </li>
    <li>
        <a class="manager-menu-card" href="cancellation_requests.php">
            <?php if ($pendingCancellationCount > 0): ?>
                <span class="menu-card-badge"><?php echo htmlspecialchars(menuBadgeText($pendingCancellationCount)); ?></span>
            <?php endif; ?>
            <span class="manager-menu-title">キャンセル承認画面</span>
            <span class="manager-menu-description">承認後キャンセル申請を確認し、承認または却下を行います。</span>
        </a>
    </li>
    <li>
        <a class="manager-menu-card" href="matching_settings.php">
            <span class="manager-menu-title">設定</span>
            <span class="manager-menu-description">抽出モードの設定や記録削除を行います。</span>
        </a>
    </li>
    <li>
        <a class="manager-menu-card manager-menu-card-logout" href="<?php echo $basePath; ?>logout.php">
            <span class="manager-menu-title">ログアウト</span>
            <span class="manager-menu-description">現在の店長アカウントからログアウトします。</span>
        </a>
    </li>
</ul>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>

