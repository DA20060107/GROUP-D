<?php
/**
 * 共通ヘッダー
 *
 * 呼び出し側で以下の変数を設定してから include すること
 *   $pageTitle : 画面タイトル（必須）
 *   $basePath  : public フォルダまでの相対パス（例: '' or '../../public/'）
 *   $showBack  : 戻るボタンを表示するか（省略時 true）
 *   $showHome  : ホームボタンを表示するか（省略時 true）
 *   $homeLabel : ホームボタンの表示名（省略時 ホーム）
 *   $mainClass : mainタグへ付与するCSSクラス（省略時 container）
 */

// ログイン状態を判定できるようにする（ホームボタンのリンク先決定に使用）
require_once __DIR__ . '/auth.php';

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
if (!isset($homeLabel)) {
    $homeLabel = 'ホーム';
}
if (!isset($mainClass)) {
    $mainClass = 'container';
}

// ホームボタンのリンク先を決める。
// ログイン中は自分のロール用メニュー（menu.php）へ、
// 未ログイン時のみトップページ（index.php）へ遷移させる。
// これにより、ログイン後はログアウトしない限りトップページへは戻れない。
$headerUser = currentUser();
if ($headerUser !== null && ($headerUser['role'] ?? '') === 'manager') {
    $homeUrl = $basePath . '../pages/manager/menu.php';
} elseif ($headerUser !== null && ($headerUser['role'] ?? '') === 'employee') {
    $homeUrl = $basePath . '../pages/employee/menu.php';
} else {
    $homeUrl = $basePath . 'index.php';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($pageTitle); ?> | シフト代勤マッチング支援システム</title>
<link rel="stylesheet" href="<?php echo $basePath; ?>assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../public/assets/css/style.css'); ?>">
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
        <a class="btn-header" href="<?php echo htmlspecialchars($homeUrl); ?>"><?php echo htmlspecialchars($homeLabel); ?></a>
        <?php endif; ?>
    </div>
</header>
<main class="<?php echo htmlspecialchars($mainClass); ?>">
