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

require_once __DIR__ . '/../app/includes/header.php';
?>

<p class="page-description">
    ユーザーIDとパスワードを入力してログインしてください。
</p>

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
<form class="section" action="login.php" method="post">
    ...
    <button type="submit" class="btn">ログイン</button>
</form>
<div class="section" style="margin-top: 20px;">
        <p>まだ登録していない方はこちら：</p>
        <a href="../pages/manager/register_manager.php">店長アカウントを新規作成する</a><br>
        <a href="../pages/employee/register_employee.php">従業員アカウントを新規作成する</a>
</div>




<?php require_once __DIR__ . '/../app/includes/footer.php'; ?>
