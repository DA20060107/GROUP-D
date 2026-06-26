<?php
require_once __DIR__ . '/../../app/config/database.php';

// POSTデータ受け取り
$name = $_POST['name'];
$username = $_POST['username'];
$password = password_hash($_POST['password'], PASSWORD_DEFAULT);
$email = $_POST['email'];

// 空チェック
if (empty($username)) {
    die('ログインIDが入力されていません。');
}

// 重複チェック（推奨）
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
$stmt->execute([$username]);
if ($stmt->fetchColumn() > 0) {
    die('このログインIDはすでに使われています。');
}

// 店長は role = 1
$role = 1;

// 正しい INSERT 文
$sql = "INSERT INTO users (name, username, email, password, role)
        VALUES (?, ?, ?, ?, ?)";
$stmt = $pdo->prepare($sql);
$stmt->execute([$name, $username, $email, $password, $role]);

header("Location: ../../public/login.php");
exit;
