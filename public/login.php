<?php
/**
 * ログイン画面
 *
 * GET時はログインフォームを表示し、POST時はユーザーIDとパスワードを検証する。
 * 検証成功時は $_SESSION['user'] にユーザー情報を保存し、roleに応じて
 * 店長メニュー / 従業員メニューへ遷移する。
 */

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/includes/auth.php';

// 既にログイン済みの場合は、自分のメニューへ遷移する
$loggedInUser = currentUser();
if ($loggedInUser !== null) {
    if ($loggedInUser['role'] === 'manager') {
        header('Location: ../pages/manager/menu.php');
    } else {
        header('Location: ../pages/employee/menu.php');
    }
    exit;
}

$errorMessage = '';
$username     = '';
$showTimeoutDialog = isset($_GET['timeout']) || wasSessionTimeoutDetected();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $errorMessage = 'ユーザーIDとパスワードを入力してください。';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if ($user !== false && password_verify($password, $user['password'])) {
            $_SESSION['user'] = [
                'id'          => $user['id'],
                'username'    => $user['username'],
                'role'        => $user['role'],
                'employee_id' => $user['employee_id'],
                'name'        => $user['name'],
            ];
            $_SESSION['last_activity'] = time();

            if ($user['role'] === 'manager') {
                header('Location: ../pages/manager/menu.php');
            } else {
                header('Location: ../pages/employee/menu.php');
            }
            exit;
        }

        $errorMessage = 'ユーザーIDまたはパスワードが正しくありません。';
    }
}

$pageTitle = 'ログイン';
$basePath  = '';
$showBack  = false;
$showHome  = true;
$homeLabel = 'トップ';

require_once __DIR__ . '/../app/includes/header.php';
?>

<p class="page-description">
    ユーザーIDとパスワードを入力してログインしてください。
</p>

<?php if ($showTimeoutDialog): ?>
<div class="timeout-dialog" id="timeoutDialog" role="alert">
    <span>無操作のためログアウトしました。</span>
    <button type="button" class="timeout-dialog-close" aria-label="閉じる" onclick="document.getElementById('timeoutDialog').remove();">×</button>
</div>
<script>
// タイムアウト通知は一度だけ表示し、ページ移動時や戻る操作で残らないようにする
(function () {
    const dialog = document.getElementById('timeoutDialog');
    if (!dialog) {
        return;
    }

    const removeDialog = function () {
        const currentDialog = document.getElementById('timeoutDialog');
        if (currentDialog) {
            currentDialog.remove();
        }
    };

    const url = new URL(window.location.href);
    if (url.searchParams.has('timeout')) {
        url.searchParams.delete('timeout');
        window.history.replaceState(null, document.title, url.pathname + url.search + url.hash);
    }

    window.addEventListener('pagehide', removeDialog);
    document.addEventListener('submit', removeDialog);
    document.addEventListener('click', function (event) {
        const link = event.target.closest('a');
        if (link && link.href) {
            removeDialog();
        }
    });
}());
</script>
<?php endif; ?>

<div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
<form class="section" action="login.php" method="post">
    <div class="form-group">
        <label for="username">ユーザーID</label>
        <input type="text" id="username" name="username" placeholder="例: manager01" value="<?php echo htmlspecialchars($username); ?>">
    </div>
    <div class="form-group">
        <label for="password">パスワード</label>
        <input type="password" id="password" name="password" placeholder="パスワード">
    </div>
    <button type="submit" class="btn">ログイン</button>
</form>

