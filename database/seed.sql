-- ============================================================
-- シフト代勤マッチング支援システム 初期データ
--
-- 注意:
--   この seed.sql は既存データを初期化してから投入します。
--   users.password には password_hash() でハッシュ化した文字列を格納しています。
--   下記の全ユーザーの平文パスワードは共通で "a" です（開発用）。
--   本番運用ではユーザーごとに異なるパスワードを設定し、
--   登録・変更時に必ず password_hash() でハッシュ化してください。
-- ============================================================

USE `shift_matching_system`;

-- ------------------------------------------------------------
-- 既存データ初期化
-- ------------------------------------------------------------
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM request_view_states;
DELETE FROM cancellation_requests;
DELETE FROM approvals;
DELETE FROM notifications;
DELETE FROM substitute_candidates;
DELETE FROM leave_requests;
DELETE FROM availability;
DELETE FROM shifts;
DELETE FROM matching_settings;
DELETE FROM users;
DELETE FROM employees;

ALTER TABLE request_view_states AUTO_INCREMENT = 1;
ALTER TABLE cancellation_requests AUTO_INCREMENT = 1;
ALTER TABLE approvals AUTO_INCREMENT = 1;
ALTER TABLE notifications AUTO_INCREMENT = 1;
ALTER TABLE substitute_candidates AUTO_INCREMENT = 1;
ALTER TABLE leave_requests AUTO_INCREMENT = 1;
ALTER TABLE availability AUTO_INCREMENT = 1;
ALTER TABLE shifts AUTO_INCREMENT = 1;
ALTER TABLE matching_settings AUTO_INCREMENT = 1;
ALTER TABLE users AUTO_INCREMENT = 1;
ALTER TABLE employees AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- employees: 従業員13名
--
-- 2026-07-17 18:00〜22:00 のLOVOTのシフトを休み申請デモ対象にする。
-- 小室・渡辺・塚本は全モードで先に通知される3人。
-- 4人目は、通常=星野蒼 / 人員確保優先=坂本実南 / スキル重視=飯島凛 になるよう調整。
-- ------------------------------------------------------------
INSERT INTO employees (id, name, email, phone, hire_date, position, note, is_active, skill_level) VALUES
    (1,  'LOVOT',    'lovot@example.com',          '090-1000-0001', '2026-04-01', 'ホール',          NULL, 1, 3),
    (2,  '小室佑太', 'komuro@example.com',         '090-1000-0002', '2024-04-01', 'ホール',          NULL, 1, 5),
    (3,  '渡辺大智', 'watanabe@example.com',       '090-1000-0003', '2024-04-01', 'ホール',          NULL, 1, 5),
    (4,  '塚本恭平', 'tsukamoto@example.com',      '090-1000-0004', '2024-04-01', 'ホール',          NULL, 1, 5),
    (5,  '星野蒼',   'hoshino-ao@example.com',     '090-1000-0005', '2024-01-01', 'ホール・キッチン', NULL, 1, 3),
    (6,  '坂本実南', 'sakamoto@example.com',       '090-1000-0006', '2026-06-01', 'ホール',          NULL, 1, 3),
    (7,  '飯島凛',   'iijima@example.com',         '090-1000-0007', '2026-06-01', 'ホール',          NULL, 1, 5),
    (8,  '星野理一', 'hoshino-riichi@example.com', '090-1000-0008', '2025-04-01', 'ホール・ドリンク', NULL, 1, 3),
    (9,  '石井颯斗', 'ishii@example.com',          '090-1000-0009', '2025-05-01', 'ホール・ドリンク',   NULL, 1, 3),
    (10, '陶修斗',   'sue@example.com',            '090-1000-0010', '2025-06-01', 'キッチン・洗い場',   NULL, 1, 2),
    (11, '吉田隆人', 'yoshida@example.com',        '090-1000-0011', '2025-07-01', 'ホール・洗い場',     NULL, 1, 3),
    (12, '川口将英', 'kawaguchi@example.com',      '090-1000-0012', '2025-08-01', 'ホール・レジ・ドリンク', NULL, 1, 4),
    (13, '高木悠希', 'takagi@example.com',         '090-1000-0013', '2025-09-01', 'キッチン・洗い場',   NULL, 1, 3);

-- ------------------------------------------------------------
-- users: 店長1名 + 従業員13名
-- パスワードはすべて password_hash('a', PASSWORD_DEFAULT) のハッシュ値
-- ------------------------------------------------------------
INSERT INTO users (id, username, password, role, employee_id, name, email, phone, note) VALUES
    (1,  'manager01',  '$2y$10$CodCQT14oTcpbii157lnzeluXz3srq4Wyhvx8Tz68GO8r94y/kGJW', 'manager',  NULL, '小笠原秀人', 'ogasawara@example.com', '090-2000-0001', NULL),
    (2,  'employee01', '$2y$10$CodCQT14oTcpbii157lnzeluXz3srq4Wyhvx8Tz68GO8r94y/kGJW', 'employee', 1,  'LOVOT',    'lovot@example.com',          '090-1000-0001', NULL),
    (3,  'employee02', '$2y$10$CodCQT14oTcpbii157lnzeluXz3srq4Wyhvx8Tz68GO8r94y/kGJW', 'employee', 2,  '小室佑太', 'komuro@example.com',         '090-1000-0002', NULL),
    (4,  'employee03', '$2y$10$CodCQT14oTcpbii157lnzeluXz3srq4Wyhvx8Tz68GO8r94y/kGJW', 'employee', 3,  '渡辺大智', 'watanabe@example.com',       '090-1000-0003', NULL),
    (5,  'employee04', '$2y$10$CodCQT14oTcpbii157lnzeluXz3srq4Wyhvx8Tz68GO8r94y/kGJW', 'employee', 4,  '塚本恭平', 'tsukamoto@example.com',      '090-1000-0004', NULL),
    (6,  'employee05', '$2y$10$CodCQT14oTcpbii157lnzeluXz3srq4Wyhvx8Tz68GO8r94y/kGJW', 'employee', 5,  '星野蒼',   'hoshino-ao@example.com',     '090-1000-0005', NULL),
    (7,  'employee06', '$2y$10$CodCQT14oTcpbii157lnzeluXz3srq4Wyhvx8Tz68GO8r94y/kGJW', 'employee', 6,  '坂本実南', 'sakamoto@example.com',       '090-1000-0006', NULL),
    (8,  'employee07', '$2y$10$CodCQT14oTcpbii157lnzeluXz3srq4Wyhvx8Tz68GO8r94y/kGJW', 'employee', 7,  '飯島凛',   'iijima@example.com',         '090-1000-0007', NULL),
    (9,  'employee08', '$2y$10$CodCQT14oTcpbii157lnzeluXz3srq4Wyhvx8Tz68GO8r94y/kGJW', 'employee', 8,  '星野理一', 'hoshino-riichi@example.com', '090-1000-0008', NULL),
    (10, 'employee09', '$2y$10$CodCQT14oTcpbii157lnzeluXz3srq4Wyhvx8Tz68GO8r94y/kGJW', 'employee', 9,  '石井颯斗', 'ishii@example.com',          '090-1000-0009', NULL),
    (11, 'employee10', '$2y$10$CodCQT14oTcpbii157lnzeluXz3srq4Wyhvx8Tz68GO8r94y/kGJW', 'employee', 10, '陶修斗',   'sue@example.com',            '090-1000-0010', NULL),
    (12, 'employee11', '$2y$10$CodCQT14oTcpbii157lnzeluXz3srq4Wyhvx8Tz68GO8r94y/kGJW', 'employee', 11, '吉田隆人', 'yoshida@example.com',        '090-1000-0011', NULL),
    (13, 'employee12', '$2y$10$CodCQT14oTcpbii157lnzeluXz3srq4Wyhvx8Tz68GO8r94y/kGJW', 'employee', 12, '川口将英', 'kawaguchi@example.com',      '090-1000-0012', NULL),
    (14, 'employee13', '$2y$10$CodCQT14oTcpbii157lnzeluXz3srq4Wyhvx8Tz68GO8r94y/kGJW', 'employee', 13, '高木悠希', 'takagi@example.com',         '090-1000-0013', NULL);

-- ------------------------------------------------------------
-- shifts: シフト
-- ------------------------------------------------------------
INSERT INTO shifts (id, employee_id, manager_user_id, shift_date, start_time, end_time, position, note, status) VALUES
    (1,  1,  NULL, '2026-07-17', '18:00:00', '22:00:00', 'ホール',       NULL, 'scheduled'),
    (2,  NULL, 1,  '2026-07-17', '17:00:00', '23:00:00', '管理',         NULL, 'scheduled'),
    (3,  2,  NULL, '2026-07-16', '18:00:00', '22:00:00', 'ホール',       NULL, 'scheduled'),
    (4,  3,  NULL, '2026-07-18', '18:00:00', '22:00:00', 'ホール',       NULL, 'scheduled'),
    (5,  4,  NULL, '2026-07-18', '17:00:00', '21:00:00', 'ホール',       NULL, 'scheduled'),
    (6,  5,  NULL, '2026-07-19', '18:00:00', '23:00:00', 'ホール・キッチン', NULL, 'scheduled'),
    (7,  6,  NULL, '2026-07-20', '18:00:00', '22:00:00', 'ホール',       NULL, 'scheduled'),
    (8,  7,  NULL, '2026-07-21', '17:00:00', '22:00:00', 'ホール',       NULL, 'scheduled'),
    (9,  8,  NULL, '2026-07-18', '17:00:00', '21:00:00', 'ホール・ドリンク', NULL, 'scheduled'),
    (10, 9,  NULL, '2026-07-17', '19:00:00', '23:00:00', 'ホール',       NULL, 'scheduled'),
    (11, 10, NULL, '2026-07-18', '17:00:00', '22:00:00', 'キッチン',     NULL, 'scheduled'),
    (12, 11, NULL, '2026-07-19', '18:00:00', '22:00:00', 'ホール',       NULL, 'scheduled'),
    (13, 12, NULL, '2026-07-20', '17:00:00', '22:00:00', 'ホール・レジ', NULL, 'scheduled'),
    (14, 13, NULL, '2026-07-21', '18:00:00', '23:00:00', 'キッチン',     NULL, 'scheduled');

-- ------------------------------------------------------------
-- availability: LOVOTの代勤候補抽出用勤務可能日
--
-- 対象シフト: 2026-07-17 18:00〜22:00 / ホール
-- 先に通知される3人: 小室佑太・渡辺大智・塚本恭平
-- 次点候補:
--   通常             → 星野蒼
--   人員確保優先     → 坂本実南
--   スキル重視       → 飯島凛
--   星野理一は19:00〜21:00のみ勤務可能とし、人員確保優先モードでのみ部分カバー候補として出る。
-- ------------------------------------------------------------
INSERT INTO availability (employee_id, available_date, start_time, end_time, note, created_by) VALUES
    (1, '2026-07-18', '18:00:00', '22:00:00', NULL, 2),
    (2, '2026-07-17', '18:00:00', '22:00:00', NULL, 3),
    (3, '2026-07-17', '18:00:00', '22:00:00', NULL, 4),
    (4, '2026-07-17', '18:00:00', '22:00:00', NULL, 5),
    (5, '2026-07-17', '17:00:00', '23:00:00', NULL, 6),
    (6, '2026-07-17', '18:00:00', '22:00:00', NULL, 7),
    (7, '2026-07-17', '15:00:00', '23:00:00', NULL, 8),
    (8, '2026-07-17', '19:00:00', '21:00:00', NULL, 9),
    (9, '2026-07-18', '18:00:00', '23:00:00', NULL, 10),
    (10, '2026-07-19', '17:00:00', '22:00:00', NULL, 11),
    (11, '2026-07-20', '18:00:00', '22:00:00', NULL, 12),
    (12, '2026-07-21', '17:00:00', '22:00:00', NULL, 13),
    (13, '2026-07-22', '18:00:00', '23:00:00', NULL, 14);

-- ------------------------------------------------------------
-- matching_settings: 初期抽出モード
-- 必要に応じて通常 / 人員確保優先 / スキル重視に切り替えて挙動を確認する。
-- ------------------------------------------------------------
INSERT INTO matching_settings (setting_key, setting_value) VALUES
    ('current_matching_mode', 'normal')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
