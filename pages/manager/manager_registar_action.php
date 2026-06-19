<?php
require_once '../app/db_connect.php'; // ← あなたのプロジェクトのDB接続ファイルに合わせて変更

$name = $_POST['name'];
$email = $_POST['email'];
$password = password_hash($_POST['password'], PASSWORD_DEFAULT);

// 店長は role = 1 とする（あなたのDB仕様に合わせて変更）
$role = 1;

$sql = "INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)";
$stmt = $pdo->prepare($sql);
$stmt->execute([$name, $email, $password, $role]);

header("Location: login.php");
exit;
