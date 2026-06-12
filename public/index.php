<?php
/**
 * トップページ
 *
 * TODO: ログイン状態に応じて店長メニュー / 従業員メニューへ自動振り分けする予定
 */

$pageTitle = 'トップページ';
$basePath  = '';
$showBack  = false;
$showHome  = false;

require_once __DIR__ . '/../app/includes/header.php';
?>

<p class="page-description">
    シフト代勤マッチング支援システムへようこそ。<br>
    休みたい従業員と代わりに働ける従業員のマッチングを支援し、連絡負担とシフト調整負担を軽減します。
</p>

<div class="section">
    <h2>はじめに</h2>
    <a class="btn" href="login.php">ログイン画面へ</a>
</div>

<div class="section">
    <h2>画面確認用リンク（開発用）</h2>
    <p class="page-description">
        各メニュー画面への直接リンクです。未ログインの場合はログイン画面へ転送されます。
    </p>
    <ul class="menu-list">
        <li><a href="../pages/manager/menu.php">店長メニューを見る</a></li>
        <li><a href="../pages/employee/menu.php">従業員メニューを見る</a></li>
    </ul>
</div>

<?php require_once __DIR__ . '/../app/includes/footer.php'; ?>
