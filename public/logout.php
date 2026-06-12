<?php
/**
 * ログアウト処理
 *
 * セッションを破棄してログイン画面へ戻る。
 */

require_once __DIR__ . '/../app/includes/auth.php';

$_SESSION = [];
session_destroy();

header('Location: login.php');
exit;
