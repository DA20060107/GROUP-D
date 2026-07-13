<?php
require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');
require_once dirname(__DIR__, 2) . '/app/config/database.php';

$pageTitle = '店長情報編集';
$basePath  = '../../public/';
$showBack  = true;
$showHome  = true;
// 一つ前の画面は店長アカウント一覧
$backUrl   = 'manager_list.php';

require_once __DIR__ . '/../../app/includes/header.php';

// 編集対象のID取得
$id = $_GET['id'] ?? null;

if ($id === null) {
    die('IDが指定されていません');
}

// 店長情報取得
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND role = 1");
$stmt->execute(['id' => $id]);
$manager = $stmt->fetch();

if (!$manager) {
    die('店長が見つかりません');
}
?>

<style>
    form.manager-edit-form {
        max-width: 650px;
        margin: 30px auto;
        background: white;
        padding: 30px;
        border-radius: 12px;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }

    form.manager-edit-form label {
        font-size: 18px;
        margin-bottom: 6px;
        display: block;
    }

    form.manager-edit-form input,
    form.manager-edit-form textarea {
        width: 100%;
        padding: 14px 16px;
        font-size: 18px;
        border-radius: 8px;
        border: 1px solid #ccc;
        margin-bottom: 22px;
    }

    form.manager-edit-form button {
        padding: 14px 28px;
        font-size: 18px;
        border-radius: 8px;
    }
</style>

<h1 class="text-center">店長情報編集</h1>

<form action="manager_edit_action.php" method="POST" class="manager-edit-form">

    <input type="hidden" name="id" value="<?= htmlspecialchars((string) $manager['id']) ?>">

    <label>氏名</label>
    <input type="text" name="name" value="<?= htmlspecialchars($manager['name']) ?>" required>

    <label>ログインID</label>
    <input type="text" name="username" value="<?= htmlspecialchars($manager['username']) ?>" required>

    <label>メールアドレス</label>
    <input type="email" name="email" value="<?= htmlspecialchars($manager['email'] ?? '') ?>" required>

    <label>電話番号</label>
    <input type="text" name="phone" value="<?= htmlspecialchars($manager['phone'] ?? '') ?>">

    <label>備考</label>
    <textarea name="note"><?= htmlspecialchars($manager['note'] ?? '') ?></textarea>

    <button type="submit" class="btn btn-primary">更新する</button>
</form>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
