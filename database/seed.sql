-- ============================================================
-- シフト代勤マッチング支援システム サンプルデータ
--
-- 注意:
--   users.password には password_hash() でハッシュ化した文字列を格納しています。
--   下記の全ユーザーの平文パスワードは共通で "password123" です（開発用）。
--   本番運用ではユーザーごとに異なるパスワードを設定し、
--   登録・変更時に必ず password_hash() でハッシュ化してください。
-- ============================================================

USE `シフト管理システム`;

-- ------------------------------------------------------------
-- employees: 従業員情報（5名）
-- ------------------------------------------------------------
INSERT INTO employees (id, name, email, phone, hire_date, position, note, is_active, skill_level) VALUES
    (1, '山田 太郎', 'yamada@example.com', '090-1111-1111', '2023-04-01', 'キッチン', NULL, 1, 4),
    (2, '佐藤 花子', 'sato@example.com',   '090-2222-2222', '2023-06-01', 'ホール', NULL, 1, 3),
    (3, '鈴木 次郎', 'suzuki@example.com', '090-3333-3333', '2024-01-10', 'ホール・レジ', NULL, 1, 5),
    (4, '高橋 美咲', 'takahashi@example.com', '090-4444-4444', '2024-04-01', 'キッチン', NULL, 1, 2),
    (5, '田中 健太', 'tanaka@example.com', '090-5555-5555', '2025-02-15', 'ホール', NULL, 1, 3);

-- ------------------------------------------------------------
-- users: 店長1名 + 従業員5名
-- パスワードはすべて password_hash('password123', PASSWORD_DEFAULT) のハッシュ値
-- ------------------------------------------------------------
INSERT INTO users (id, username, password, role, employee_id, name) VALUES
    (1, 'manager01',  '$2y$10$WHTzV7JPzD0/VV1xplzKauPV4YfqElMHbN/6kpFq98glqBMNQFSYK', 'manager',  NULL, '店長 太郎'),
    (2, 'employee01', '$2y$10$WHTzV7JPzD0/VV1xplzKauPV4YfqElMHbN/6kpFq98glqBMNQFSYK', 'employee', 1, '山田 太郎'),
    (3, 'employee02', '$2y$10$WHTzV7JPzD0/VV1xplzKauPV4YfqElMHbN/6kpFq98glqBMNQFSYK', 'employee', 2, '佐藤 花子'),
    (4, 'employee03', '$2y$10$WHTzV7JPzD0/VV1xplzKauPV4YfqElMHbN/6kpFq98glqBMNQFSYK', 'employee', 3, '鈴木 次郎'),
    (5, 'employee04', '$2y$10$WHTzV7JPzD0/VV1xplzKauPV4YfqElMHbN/6kpFq98glqBMNQFSYK', 'employee', 4, '高橋 美咲'),
    (6, 'employee05', '$2y$10$WHTzV7JPzD0/VV1xplzKauPV4YfqElMHbN/6kpFq98glqBMNQFSYK', 'employee', 5, '田中 健太');

-- ------------------------------------------------------------
-- shifts: サンプルシフト
-- ------------------------------------------------------------
INSERT INTO shifts (employee_id, shift_date, start_time, end_time, position, note, status) VALUES
    (1, '2026-06-14', '17:00:00', '22:00:00', 'キッチン', NULL, 'scheduled'),
    (2, '2026-06-15', '17:00:00', '22:00:00', 'ホール', NULL, 'leave_requested'),
    (3, '2026-06-15', '18:00:00', '23:00:00', 'ホール', NULL, 'scheduled'),
    (4, '2026-06-16', '17:00:00', '22:00:00', 'キッチン', NULL, 'scheduled'),
    (5, '2026-06-18', '18:00:00', '23:00:00', 'ホール', NULL, 'scheduled');

-- ------------------------------------------------------------
-- availability: サンプル勤務可能日（従業員本人が登録した想定）
-- created_by には本人の users.id を設定する
-- ------------------------------------------------------------
INSERT INTO availability (employee_id, available_date, start_time, end_time, note, created_by) VALUES
    (1, '2026-06-14', '17:00:00', '22:00:00', NULL, 2),
    (2, '2026-06-15', '17:00:00', '22:00:00', NULL, 3),
    (3, '2026-06-15', '17:00:00', '23:00:00', NULL, 4),
    (3, '2026-06-14', '17:00:00', '22:00:00', NULL, 4),
    (4, '2026-06-16', '17:00:00', '22:00:00', NULL, 5),
    (5, '2026-06-18', '18:00:00', '23:00:00', NULL, 6);

-- ------------------------------------------------------------
-- leave_requests: サンプル休み申請（佐藤さんが2026-06-15のシフトを申請）
-- ------------------------------------------------------------
INSERT INTO leave_requests (id, shift_id, employee_id, reason, status, matching_mode) VALUES
    (1, 2, 2, '通院のため', 'matching', 'normal');

-- ------------------------------------------------------------
-- substitute_candidates: サンプル代勤候補（鈴木さんが候補）
-- ------------------------------------------------------------
INSERT INTO substitute_candidates (leave_request_id, candidate_employee_id, status, match_score, match_reason, matched_at, responded_at) VALUES
    (1, 3, 'accepted', 82, 'ポジション不一致、スキルレベル5、勤続1年以上、勤務可能時間が対象シフトをほぼカバー', '2026-06-12 09:00:00', '2026-06-12 10:30:00');

-- ------------------------------------------------------------
-- matching_settings: 代勤候補抽出モードの初期設定（通常モード）
-- schema.sql の移行処理で既に1行登録されている場合があるため、
-- ON DUPLICATE KEY UPDATE で安全に上書きする（空DB・既存DBどちらでもエラーにならない）
-- ------------------------------------------------------------
INSERT INTO matching_settings (setting_key, setting_value) VALUES
    ('current_matching_mode', 'normal')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ------------------------------------------------------------
-- notifications: サンプル通知
-- ------------------------------------------------------------
INSERT INTO notifications (user_id, type, title, message, is_read, related_leave_request_id) VALUES
    (1, 'leave_request',  '休み申請', '佐藤 花子さんから 2026-06-15 のシフトの休み申請がありました。', 0, 1),
    (1, 'candidate_offer', '代勤回答', '鈴木 次郎さんが代勤を「対応可能」で回答しました。', 1, 1),
    (4, 'candidate_offer', '代勤候補のお知らせ', '2026-06-15 17:00-22:00 のシフトの代勤候補に選ばれました。', 0, 1),
    (3, 'approval_result', '承認結果', '2026-06-10 のシフトの休み申請が承認されました。', 1, NULL);
