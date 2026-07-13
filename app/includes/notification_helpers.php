<?php
/**
 * 通知画面用の共通処理
 */

/**
 * お気に入り通知用カラムを既存DBへ安全に追加する。
 */
function ensureNotificationFavoriteColumn(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'notifications'
           AND COLUMN_NAME = 'is_favorite'"
    );
    $stmt->execute();

    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE notifications
             ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'お気に入り通知フラグ（1: 自動削除しない）' AFTER is_read"
        );
    }
}

/**
 * 既読かつ90日以上経過した通知を削除する。
 * お気に入り登録された通知は削除しない。
 */
function cleanupOldReadNotifications(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare(
        'DELETE FROM notifications
         WHERE user_id = :user_id
           AND is_read = 1
           AND is_favorite = 0
           AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)'
    );
    $stmt->execute(['user_id' => $userId]);
}

/**
 * 通知表示条件を正規化する。
 */
function normalizeNotificationFilter(?string $filter): string
{
    return in_array($filter, ['all', 'read', 'unread'], true) ? $filter : 'all';
}

/**
 * 通知表示条件のラベルを返す。
 */
function notificationFilterLabel(string $filter): string
{
    $labels = [
        'all'    => '全て',
        'read'   => '既読のみ',
        'unread' => '未読のみ',
    ];

    return $labels[$filter] ?? $labels['all'];
}

/**
 * 通知ページのURLを生成する。
 */
function notificationPageUrl(string $filter, int $page = 1): string
{
    $params = ['filter' => normalizeNotificationFilter($filter)];
    if ($page > 1) {
        $params['page'] = $page;
    }

    return 'notifications.php?' . http_build_query($params);
}
