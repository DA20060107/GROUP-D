<?php
require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');
require_once dirname(__DIR__, 2) . '/app/config/database.php';

$id = $_POST['id'];

$stmt = $pdo->prepare("
    UPDATE users SET
        name = :name,
        username = :username,
        email = :email,
        phone = :phone,
        note = :note
    WHERE id = :id AND role = 1
");

$stmt->execute([
    'name'     => $_POST['name'],
    'username' => $_POST['username'],
    'email'    => $_POST['email'],
    'phone'    => $_POST['phone'],
    'note'     => $_POST['note'],
    'id'       => $id
]);

header("Location: manager_list.php?msg=updated");
exit;
