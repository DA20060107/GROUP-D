<?php
/**
 * DB接続設定（PDO）
 *
 * - DB名: シフト管理システム
 * - 文字コード: utf8mb4
 * - XAMPP標準のユーザー名(root) / パスワード(空)を想定
 *
 * 使い方:
 *   require_once __DIR__ . '/../config/database.php';
 *   $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username');
 *   $stmt->execute(['username' => $username]);
 *
 * SQLインジェクション対策のため、値の埋め込みには必ずプリペアドステートメントを使用すること。
 */

$dbHost    = 'localhost';
$dbName    = 'shift_matching_system';
$dbUser    = 'root';
$dbPass    = '';
$dbCharset = 'utf8mb4';

$dsn = "mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    // 開発用：接続失敗時に分かりやすいメッセージを表示する
    die('データベース接続に失敗しました。XAMPPのMySQLが起動しているか、'
        . 'database/schema.sql でDBが作成済みか確認してください。'
        . ' エラー詳細: ' . $e->getMessage());
}
