<?php
/**
 * 既存DB向けの軽量スキーマ補助。
 *
 * schema.sql を再実行していないローカル環境でも、画面操作で必要なカラムと
 * ENUM 値が不足しないようにする。
 */

function ensureManualSubstituteShiftSchema(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME, IS_NULLABLE
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'shifts'
           AND COLUMN_NAME IN ('employee_id', 'manager_user_id', 'related_leave_request_id')"
    );
    $stmt->execute();

    $columns = [];
    foreach ($stmt->fetchAll() as $column) {
        $columns[$column['COLUMN_NAME']] = $column['IS_NULLABLE'];
    }

    if (!isset($columns['manager_user_id'])) {
        $pdo->exec(
            "ALTER TABLE shifts
             ADD COLUMN manager_user_id INT NULL COMMENT '担当店長（users.id、従業員シフトの場合はNULL）' AFTER employee_id"
        );
    }

    if (!isset($columns['related_leave_request_id'])) {
        $pdo->exec(
            "ALTER TABLE shifts
             ADD COLUMN related_leave_request_id INT NULL COMMENT '手動登録された代勤シフトの関連休み申請ID' AFTER manager_user_id"
        );
    }

    if (($columns['employee_id'] ?? 'NO') !== 'YES') {
        $pdo->exec(
            "ALTER TABLE shifts
             MODIFY COLUMN employee_id INT NULL COMMENT '担当従業員（店長シフトの場合はNULL）'"
        );
    }

    $pdo->exec(
        "ALTER TABLE shifts
         MODIFY COLUMN status ENUM('scheduled', 'leave_requested', 'leave_approved', 'substituted', 'cancelled', 'replacement_pending')
         NOT NULL DEFAULT 'scheduled'
         COMMENT 'シフト状態（leave_approved: 休み承認済み、replacement_pending: 代勤者キャンセル承認後の再調整待ち）'"
    );
}
