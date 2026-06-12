-- ============================================================
-- シフト代勤マッチング支援システム DBスキーマ
--
-- DB名は仮で「シフト管理システム」としています。
-- 必要に応じて英数字のDB名（例: shift_management）へ変更可能です。
-- ============================================================

CREATE DATABASE IF NOT EXISTS `シフト管理システム`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `シフト管理システム`;

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
    employee_id INT NOT NULL COMMENT '担当従業員',
    shift_date  DATE NOT NULL COMMENT '勤務日',
    start_time  TIME NOT NULL COMMENT '開始時刻',
    end_time    TIME NOT NULL COMMENT '終了時刻',
    position    VARCHAR(50) NULL COMMENT '担当業務・ポジション',
    note        VARCHAR(255) NULL COMMENT '備考',
    status      ENUM('scheduled', 'leave_requested', 'substituted', 'cancelled')
                NOT NULL DEFAULT 'scheduled' COMMENT 'シフト状態',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_shifts_employee
        FOREIGN KEY (employee_id) REFERENCES employees (id)
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
    status      ENUM('pending', 'matching', 'approved', 'rejected')
                NOT NULL DEFAULT 'pending' COMMENT '申請状態',
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
                         NOT NULL DEFAULT 'proposed' COMMENT '回答状況',
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
    type        VARCHAR(50) NOT NULL COMMENT '通知種別（leave_request, candidate_offer, approval_result など）',
    title       VARCHAR(100) NOT NULL COMMENT '通知タイトル',
    message     TEXT NOT NULL COMMENT '通知内容',
    is_read     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '既読フラグ',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user
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
-- 既存DBへのカラム追加（マイグレーション）
--
-- 上記の CREATE TABLE IF NOT EXISTS は、テーブルが既に存在する環境では
-- 何も行わないため、既存DBに対しては以下の ALTER TABLE で
-- 不足しているカラムを追加する。schema.sql を再実行しても安全。
-- ------------------------------------------------------------
ALTER TABLE employees
    ADD COLUMN IF NOT EXISTS position VARCHAR(50) NULL COMMENT '担当可能業務・ポジション' AFTER hire_date,
    ADD COLUMN IF NOT EXISTS note VARCHAR(255) NULL COMMENT '備考' AFTER position,
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '有効フラグ（0=無効化された従業員）' AFTER note;

ALTER TABLE availability
    ADD COLUMN IF NOT EXISTS note VARCHAR(255) NULL COMMENT '備考' AFTER end_time;

ALTER TABLE shifts
    ADD COLUMN IF NOT EXISTS position VARCHAR(50) NULL COMMENT '担当業務・ポジション' AFTER end_time,
    ADD COLUMN IF NOT EXISTS note VARCHAR(255) NULL COMMENT '備考' AFTER position;
