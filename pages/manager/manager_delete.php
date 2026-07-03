<<<<<<< HEAD
<?php
require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');
require_once dirname(__DIR__, 2) . '/app/config/database.php';

if (!isset($_GET['id'])) {
    die("IDが指定されていません");
}

$id = $_GET['id'];

// 店長削除
$stmt = $pdo->prepare("DELETE FROM users WHERE id = :id AND role = 1");
$stmt->execute(['id' => $id]);

header("Location: manager_list.php?msg=deleted");
exit;
=======
<?php
require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');
require_once dirname(__DIR__, 2) . '/app/config/database.php';

if (!isset($_GET['id'])) {
    die("IDが指定されていません");
}

$id = $_GET['id'];

// 店長削除
$stmt = $pdo->prepare("DELETE FROM users WHERE id = :id AND role = 1");
$stmt->execute(['id' => $id]);

header("Location: manager_list.php?msg=deleted");
exit;
>>>>>>> 7a1f4da (Add manager account fields and hide home on manager menu)
