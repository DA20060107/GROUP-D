<?php
require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');

// DB接続
require_once dirname(__DIR__, 2) . '/app/config/database.php';

$pageTitle = '店長アカウント管理';
$basePath  = '../../public/';
$showBack  = true;
$showHome  = true;

require_once __DIR__ . '/../../app/includes/header.php';

// 店長一覧取得
$stmt = $pdo->prepare("SELECT * FROM users WHERE role = 1");
$stmt->execute();
$managers = $stmt->fetchAll();
?>

<style>
    /* 全体のフォントと背景 */
    body {
        font-family: sans-serif;
        background-color: #f5f5f5;
        padding-bottom: 40px;
    }

    /* 新規登録フォームの改善 */
    form.manager-form {
        max-width: 650px;
        margin: 30px auto;
        background: white;
        padding: 30px;
        border-radius: 12px;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }

    form.manager-form label {
        font-size: 18px;
        margin-bottom: 6px;
        display: block;
    }

    form.manager-form input,
    form.manager-form textarea {
        width: 100%;
        padding: 14px 16px;
        font-size: 18px;
        border-radius: 8px;
        border: 1px solid #ccc;
        margin-bottom: 22px;
    }

    form.manager-form button {
        padding: 14px 28px;
        font-size: 18px;
        border-radius: 8px;
    }

    /* テーブルの改善 */
    table.manager-table {
        max-width: 900px;
        margin: 40px auto;
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }

    table.manager-table th,
    table.manager-table td {
        padding: 14px;
        font-size: 17px;
        border: 1px solid #ddd;
    }

    table.manager-table th {
        background-color: #f0f0f0;
    }

    h1, h2 {
        text-align: center;
    }

    .page-description {
        text-align: center;
        font-size: 18px;
        margin-bottom: 20px;
    }
</style>

<h1>店長アカウント管理</h1>

<p class="page-description">
    店長の基本情報・ログインアカウントの管理を行います。
</p>

<h2>店長の新規登録</h2>

<form action="manager_registar_action.php" method="POST" class="manager-form">

    <label>氏名</label>
    <input type="text" name="name" placeholder="例: 店長 太郎" required>

    <label>ログインID</label>
    <input type="text" name="username" placeholder="例: manager06" required>

    <label>初期パスワード</label>
    <input type="password" name="password" placeholder="例: password123" required>

    <label>メールアドレス</label>
    <input type="email" name="email" placeholder="例: manager@example.com" required>

    <label>電話番号</label>
    <input type="text" name="phone" placeholder="例: 090-1234-5678">

    <label>備考</label>
    <textarea name="note" placeholder="例: 本店担当"></textarea>

    <button type="submit" class="btn btn-primary">登録する</button>
</form>

<h2>店長一覧</h2>

<table class="table table-bordered table-hover manager-table">
    <thead>
        <tr>
            <th>ID</th>
            <th>氏名</th>
            <th>ログインID</th>
            <th>メールアドレス</th>
            <th>電話番号</th>
            <th>備考</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($managers as $m): ?>
        <tr>
            <td><?= htmlspecialchars($m['id']) ?></td>
            <td><?= htmlspecialchars($m['name']) ?></td>
            <td><?= htmlspecialchars($m['username']) ?></td>
            <td><?= htmlspecialchars($m['email']) ?></td>
            <td><?= htmlspecialchars($m['phone']) ?></td>
            <td><?= htmlspecialchars($m['note']) ?></td>
            <td>
                <a href="manager_edit.php?id=<?= $m['id'] ?>">編集</a> |
                <a href="manager_delete.php?id=<?= $m['id'] ?>" onclick="return confirm('削除しますか？');">削除</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
