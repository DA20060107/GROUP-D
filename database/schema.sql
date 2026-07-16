-- ============================================================
-- シフト代勤マッチング支援システム DBスキーマ
--
-- ローカル開発用のDB名は shift_matching_system です（古い日本語名
-- 「シフト管理システム」は使いません）。
-- 他環境（例: さくらインターネット移行時）へ移行する場合は、移行先の
-- DB名に合わせて、下記 CREATE DATABASE / USE 文を変更または削除してください。
-- ============================================================

CREATE DATABASE IF NOT EXISTS `shift_matching_system`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `shift_matching_system`;

-- ------------------------------------------------------------
-- employees: 従業員情報
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS employees (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(50)  NOT NULL COMMENT '氏名',
    email       VARCHAR(100) NULL COMMENT 'メールアドレス',
    phone       VARCHAR(20)  NULL COMMENT '電話番号',
    hire_date   DATE         NULL COMMENT '入社日',
    position    VARCHAR(50)  NULL COMMENT '担当可能業務・ポジション',
    note        VARCHAR(255) NULL COMMENT '備考',
    is_active   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '有効フラグ（0=無効化された従業員）',
    skill_level TINYINT(1)   NOT NULL DEFAULT 3 COMMENT 'スキルレベル（1:未経験に近い〜5:熟練）',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- users: ログイン用ユーザー（店長 / 従業員）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(50)  NOT NULL UNIQUE COMMENT 'ログインID',
    password    VARCHAR(255) NOT NULL COMMENT 'パスワード（password_hash()でハッシュ化して保存）',
    role        ENUM('manager', 'employee') NOT NULL COMMENT '権限区分',
    employee_id INT NULL COMMENT '従業員ID（店長の場合はNULL）',
    name        VARCHAR(50) NOT NULL COMMENT '表示名（メニュー画面の「ログイン中：〇〇」表示用）',
    email       VARCHAR(100) NULL COMMENT 'メールアドレス（店長情報管理用）',
    phone       VARCHAR(50) NULL COMMENT '電話番号（店長情報管理用）',
    note        TEXT NULL COMMENT '備考（店長情報管理用）',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_employee
        FOREIGN KEY (employee_id) REFERENCES employees (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- shifts: シフト情報
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shifts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NULL COMMENT '担当従業員（店長シフトの場合はNULL）',
    manager_user_id INT NULL COMMENT '担当店長（users.id、従業員シフトの場合はNULL）',
    related_leave_request_id INT NULL COMMENT '手動登録された代勤シフトの関連休み申請ID',
    shift_date  DATE NOT NULL COMMENT '勤務日',
    start_time  TIME NOT NULL COMMENT '開始時刻',
    end_time    TIME NOT NULL COMMENT '終了時刻',
    position    VARCHAR(50) NULL COMMENT '担当業務・ポジション',
    note        VARCHAR(255) NULL COMMENT '備考',
    status      ENUM('scheduled', 'leave_requested', 'leave_approved', 'substituted', 'cancelled', 'replacement_pending')
                NOT NULL DEFAULT 'scheduled' COMMENT 'シフト状態（leave_approved: 休み承認済み、replacement_pending: 代勤者キャンセル承認後の再調整待ち）',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_shifts_employee
        FOREIGN KEY (employee_id) REFERENCES employees (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_shifts_manager_user
        FOREIGN KEY (manager_user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- availability: 勤務可能日・勤務可能時間
-- （勤務可能日は従業員本人が登録する。店長は確認のみ行う）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS availability (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    employee_id     INT NOT NULL COMMENT '対象従業員',
    available_date  DATE NOT NULL COMMENT '勤務可能日',
    start_time      TIME NOT NULL COMMENT '勤務可能開始時刻',
    end_time        TIME NOT NULL COMMENT '勤務可能終了時刻',
    note            VARCHAR(255) NULL COMMENT '備考',
    created_by      INT NOT NULL COMMENT '登録したユーザー（users.id、通常は従業員本人）',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_availability_employee
        FOREIGN KEY (employee_id) REFERENCES employees (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_availability_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- leave_requests: 休み申請
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leave_requests (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    shift_id    INT NOT NULL COMMENT '休みたいシフト',
    employee_id INT NOT NULL COMMENT '申請した従業員',
    reason      VARCHAR(255) NULL COMMENT '申請理由',
    status      ENUM('pending', 'matching', 'approved', 'rejected', 'no_candidate', 'cancelled', 'cancelled_after_approval', 'replacement_pending')
                NOT NULL DEFAULT 'pending'
                COMMENT '申請状態（cancelled: 承認前キャンセル, cancelled_after_approval: 店長承認後のキャンセル完了, replacement_pending: 代勤者キャンセル承認後の再調整待ち）',
    matching_mode VARCHAR(30) NOT NULL DEFAULT 'normal'
                COMMENT '候補抽出時点の抽出モード（normal/staffing_priority/skill_priority）',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_leave_requests_shift
        FOREIGN KEY (shift_id) REFERENCES shifts (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_leave_requests_employee
        FOREIGN KEY (employee_id) REFERENCES employees (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- substitute_candidates: 代勤候補と回答状況
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS substitute_candidates (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    leave_request_id     INT NOT NULL COMMENT '対象の休み申請',
    candidate_employee_id INT NOT NULL COMMENT '代勤候補の従業員',
    status               ENUM('proposed', 'accepted', 'declined', 'expired')
                         NOT NULL DEFAULT 'proposed' COMMENT '回答状況（proposed=未回答）',
    match_score          INT NULL COMMENT '候補者の適合度スコア（抽出モードごとの重み付けで計算。0〜100の相対的な指標）',
    match_reason         VARCHAR(255) NULL COMMENT '候補者として抽出された理由',
    matched_at           DATETIME NULL COMMENT '候補者として抽出された日時',
    notified_at          DATETIME NULL COMMENT '代勤依頼通知を送信した日時',
    responded_at         DATETIME NULL COMMENT '回答日時',
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_substitute_leave_request
        FOREIGN KEY (leave_request_id) REFERENCES leave_requests (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_substitute_candidate_employee
        FOREIGN KEY (candidate_employee_id) REFERENCES employees (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- notifications: 通知情報
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL COMMENT '通知先ユーザー',
    type        VARCHAR(50) NOT NULL COMMENT '通知種別（approval_result, leave_request_cancelled, after_approval_cancel_requested など）',
    title       VARCHAR(100) NOT NULL COMMENT '通知タイトル',
    message     TEXT NOT NULL COMMENT '通知内容',
    is_read     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '既読フラグ',
    is_favorite TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'お気に入り通知フラグ（1: 自動削除しない）',
    related_leave_request_id INT NULL COMMENT '関連する休み申請（任意、leave_requests.id）',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- request_view_states: 申請確認画面のユーザー別表示状態
--
-- 申請・承認・キャンセルの実データは削除せず、画面上の
-- 非表示・お気に入りだけを管理する。
-- item_type + item_id は leave_requests / cancellation_requests /
-- substitute_candidates などを指す多態的な参照のため、item_id には
-- 外部キーを付けない。
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS request_view_states (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL COMMENT '表示状態を持つユーザー',
    item_type   VARCHAR(50) NOT NULL COMMENT '表示項目種別',
    item_id     INT NOT NULL COMMENT '表示項目ID',
    is_hidden   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '非表示フラグ',
    is_favorite TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'お気に入りフラグ',
    hidden_at   DATETIME NULL COMMENT '非表示にした日時',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_request_view_state (user_id, item_type, item_id),
    KEY idx_request_view_user_hidden (user_id, is_hidden, is_favorite),
    CONSTRAINT fk_request_view_states_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- approvals: 店長承認結果
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS approvals (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    leave_request_id         INT NOT NULL COMMENT '対象の休み申請',
    substitute_candidate_id  INT NULL COMMENT '承認された代勤候補（未承認時はNULL）',
    manager_id               INT NOT NULL COMMENT '承認した店長（users.id）',
    status                   ENUM('approved', 'rejected') NOT NULL COMMENT '承認結果',
    comment                  VARCHAR(255) NULL COMMENT '店長コメント',
    approved_at              DATETIME NULL COMMENT '承認日時',
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_approvals_leave_request
        FOREIGN KEY (leave_request_id) REFERENCES leave_requests (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_approvals_substitute_candidate
        FOREIGN KEY (substitute_candidate_id) REFERENCES substitute_candidates (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_approvals_manager
        FOREIGN KEY (manager_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- cancellation_requests: 承認済みの休み申請・代勤に対するキャンセル申請
--
-- request_type で申請種別を区別する。
--   requester_after_approval  : 休み申請者本人による承認後キャンセル（承認時、シフト担当者を元の休み申請者へ戻す）
--   substitute_after_approval : 代勤者本人による承認後キャンセル（承認時、シフト担当者は戻さず replacement_pending にする）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cancellation_requests (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    leave_request_id         INT NOT NULL COMMENT '対象の休み申請',
    request_type             VARCHAR(50) NOT NULL COMMENT '申請種別（requester_after_approval / substitute_after_approval）',
    requested_by_employee_id INT NOT NULL COMMENT 'キャンセル申請を行った従業員',
    reason                   TEXT NULL COMMENT 'キャンセル申請理由',
    status                   ENUM('pending', 'approved', 'rejected')
                             NOT NULL DEFAULT 'pending' COMMENT 'キャンセル申請状態',
    decided_by_user_id       INT NULL COMMENT '承認・却下した店長（users.id）',
    decided_at               DATETIME NULL COMMENT '承認・却下日時',
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cancellation_leave_request (leave_request_id),
    KEY idx_cancellation_status (status),
    CONSTRAINT fk_cancellation_leave_request
        FOREIGN KEY (leave_request_id) REFERENCES leave_requests (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cancellation_requested_employee
        FOREIGN KEY (requested_by_employee_id) REFERENCES employees (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cancellation_decided_user
        FOREIGN KEY (decided_by_user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- matching_settings: 代勤候補抽出モードなどの店長設定（key-value）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS matching_settings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(50) NOT NULL COMMENT '設定キー（例: current_matching_mode）',
    setting_value VARCHAR(50) NOT NULL COMMENT '設定値',
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_matching_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 既存DBへのカラム追加（マイグレーション）
--
-- さくらインターネット側のMySQLでは ADD COLUMN IF NOT EXISTS が
-- 使えない場合があるため、INFORMATION_SCHEMA でカラム有無を確認し、
-- 不足している場合だけ ALTER TABLE を実行する。
-- 空DBでは上記 CREATE TABLE で既に作成済みのため、以下は SELECT 1 だけになる。
-- ------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email') = 0,
    'ALTER TABLE users ADD COLUMN email VARCHAR(100) NULL COMMENT ''メールアドレス（店長情報管理用）'' AFTER name',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'phone') = 0,
    'ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL COMMENT ''電話番号（店長情報管理用）'' AFTER email',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'note') = 0,
    'ALTER TABLE users ADD COLUMN note TEXT NULL COMMENT ''備考（店長情報管理用）'' AFTER phone',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'position') = 0,
    'ALTER TABLE employees ADD COLUMN position VARCHAR(50) NULL COMMENT ''担当可能業務・ポジション'' AFTER hire_date',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'note') = 0,
    'ALTER TABLE employees ADD COLUMN note VARCHAR(255) NULL COMMENT ''備考'' AFTER position',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'is_active') = 0,
    'ALTER TABLE employees ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''有効フラグ（0=無効化された従業員）'' AFTER note',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'availability' AND COLUMN_NAME = 'note') = 0,
    'ALTER TABLE availability ADD COLUMN note VARCHAR(255) NULL COMMENT ''備考'' AFTER end_time',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE shifts
    MODIFY COLUMN employee_id INT NULL COMMENT '担当従業員（店長シフトの場合はNULL）';

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shifts' AND COLUMN_NAME = 'manager_user_id') = 0,
    'ALTER TABLE shifts ADD COLUMN manager_user_id INT NULL COMMENT ''担当店長（users.id、従業員シフトの場合はNULL）'' AFTER employee_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shifts' AND COLUMN_NAME = 'position') = 0,
    'ALTER TABLE shifts ADD COLUMN position VARCHAR(50) NULL COMMENT ''担当業務・ポジション'' AFTER end_time',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shifts' AND COLUMN_NAME = 'note') = 0,
    'ALTER TABLE shifts ADD COLUMN note VARCHAR(255) NULL COMMENT ''備考'' AFTER position',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 代勤候補抽出・通知作成機能のためのカラム追加（マイグレーション）
-- ------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'substitute_candidates' AND COLUMN_NAME = 'match_score') = 0,
    'ALTER TABLE substitute_candidates ADD COLUMN match_score INT NULL COMMENT ''候補者の適合度スコア（抽出モードごとの重み付けで計算。0〜100の相対的な指標）'' AFTER status',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'substitute_candidates' AND COLUMN_NAME = 'match_reason') = 0,
    'ALTER TABLE substitute_candidates ADD COLUMN match_reason VARCHAR(255) NULL COMMENT ''候補者として抽出された理由'' AFTER match_score',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'substitute_candidates' AND COLUMN_NAME = 'matched_at') = 0,
    'ALTER TABLE substitute_candidates ADD COLUMN matched_at DATETIME NULL COMMENT ''候補者として抽出された日時'' AFTER match_reason',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'substitute_candidates' AND COLUMN_NAME = 'notified_at') = 0,
    'ALTER TABLE substitute_candidates ADD COLUMN notified_at DATETIME NULL COMMENT ''代勤依頼通知を送信した日時'' AFTER matched_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'related_leave_request_id') = 0,
    'ALTER TABLE notifications ADD COLUMN related_leave_request_id INT NULL COMMENT ''関連する休み申請（任意、leave_requests.id）'' AFTER is_read',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'is_favorite') = 0,
    'ALTER TABLE notifications ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''お気に入り通知フラグ（1: 自動削除しない）'' AFTER is_read',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE leave_requests
    MODIFY COLUMN status ENUM('pending', 'matching', 'approved', 'rejected', 'no_candidate', 'cancelled', 'cancelled_after_approval', 'replacement_pending')
        NOT NULL DEFAULT 'pending'
        COMMENT '申請状態（cancelled: 承認前キャンセル, cancelled_after_approval: 店長承認後のキャンセル完了, replacement_pending: 代勤者キャンセル承認後の再調整待ち）';

-- ------------------------------------------------------------
-- 代勤者による承認後キャンセル機能のための状態追加（マイグレーション）
-- ------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shifts' AND COLUMN_NAME = 'related_leave_request_id') = 0,
    'ALTER TABLE shifts ADD COLUMN related_leave_request_id INT NULL COMMENT ''手動登録された代勤シフトの関連休み申請ID'' AFTER manager_user_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE shifts
    MODIFY COLUMN status ENUM('scheduled', 'leave_requested', 'leave_approved', 'substituted', 'cancelled', 'replacement_pending')
        NOT NULL DEFAULT 'scheduled'
        COMMENT 'シフト状態（leave_approved: 休み承認済み、replacement_pending: 代勤者キャンセル承認後の再調整待ち）';

-- ------------------------------------------------------------
-- 代勤候補抽出モード機能のためのカラム追加（マイグレーション）
-- ------------------------------------------------------------
SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'skill_level') = 0,
    'ALTER TABLE employees ADD COLUMN skill_level TINYINT(1) NOT NULL DEFAULT 3 COMMENT ''スキルレベル（1:未経験に近い〜5:熟練）'' AFTER is_active',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_requests' AND COLUMN_NAME = 'matching_mode') = 0,
    'ALTER TABLE leave_requests ADD COLUMN matching_mode VARCHAR(30) NOT NULL DEFAULT ''normal'' COMMENT ''候補抽出時点の抽出モード（normal/staffing_priority/skill_priority）'' AFTER status',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 抽出モードの初期設定（既存DBで未設定の場合のみ追加）
INSERT INTO matching_settings (setting_key, setting_value)
SELECT 'current_matching_mode', 'normal'
WHERE NOT EXISTS (SELECT 1 FROM matching_settings WHERE setting_key = 'current_matching_mode');
