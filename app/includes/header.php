<?php
/**
 * 共通ヘッダー
 *
 * 呼び出し側で以下の変数を設定してから include すること
 *   $pageTitle : 画面タイトル（必須）
 *   $basePath  : public フォルダまでの相対パス（例: '' or '../../public/'）
 *   $showBack  : 戻るボタンを表示するか（省略時 true）
 *   $showHome  : ホームボタンを表示するか（省略時 true）
 */

if (!isset($basePath)) {
    $basePath = '';
}
if (!isset($pageTitle)) {
    $pageTitle = 'シフト代勤マッチング支援システム';
}
if (!isset($showBack)) {
    $showBack = true;
}
if (!isset($showHome)) {
    $showHome = true;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle); ?> | シフト代勤マッチング支援システム</title>
<link rel="stylesheet" href="<?php echo $basePath; ?>assets/css/style.css">
</head>
<body>
<header class="site-header">
    <div class="header-left">
        <?php if ($showBack): ?>
        <button type="button" class="btn-header" onclick="history.back()">← 戻る</button>
        <?php endif; ?>
    </div>
    <div class="header-title"><?php echo htmlspecialchars($pageTitle); ?></div>
    <div class="header-right">
        <?php if ($showHome): ?>
        <a class="btn-header" href="<?php echo $basePath; ?>index.php">ホーム</a>
        <?php endif; ?>
    </div>
</header>
<main class="container">
