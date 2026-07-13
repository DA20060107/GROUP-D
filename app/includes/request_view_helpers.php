<?php
/**
 * 申請確認画面用の表示状態ヘルパー。
 *
 * 申請・承認・キャンセルの実データは削除せず、ユーザーごとの
 * 「非表示」「お気に入り」だけを request_view_states に保存する。
 */

function ensureRequestViewStatesTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS request_view_states (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            item_type VARCHAR(50) NOT NULL,
            item_id INT NOT NULL,
            is_hidden TINYINT(1) NOT NULL DEFAULT 0,
            is_favorite TINYINT(1) NOT NULL DEFAULT 0,
            hidden_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_request_view_state (user_id, item_type, item_id),
            KEY idx_request_view_user_hidden (user_id, is_hidden, is_favorite),
            CONSTRAINT fk_request_view_states_user
                FOREIGN KEY (user_id) REFERENCES users (id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function normalizeRequestViewFilter(?string $filter): string
{
    return in_array($filter, ['all', 'leave', 'substitute', 'leave_cancel', 'substitute_cancel'], true)
        ? $filter
        : 'all';
}

function requestViewFilterLabel(string $filter): string
{
    $labels = [
        'all'               => 'すべて',
        'leave'             => '休み申請',
        'substitute'        => '代勤申請',
        'leave_cancel'      => '休みキャンセル申請',
        'substitute_cancel' => '代勤キャンセル申請',
    ];

    return $labels[$filter] ?? $labels['all'];
}

function requestViewPageUrl(string $filter, int $page = 1): string
{
    $params = ['filter' => normalizeRequestViewFilter($filter)];
    if ($page > 1) {
        $params['page'] = $page;
    }

    return 'result.php?' . http_build_query($params);
}

function requestViewStateKey(string $itemType, int $itemId): string
{
    return $itemType . ':' . $itemId;
}

function fetchRequestViewStates(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT item_type, item_id, is_hidden, is_favorite
         FROM request_view_states
         WHERE user_id = :user_id'
    );
    $stmt->execute(['user_id' => $userId]);

    $states = [];
    foreach ($stmt->fetchAll() as $row) {
        $states[requestViewStateKey($row['item_type'], (int) $row['item_id'])] = [
            'is_hidden'   => (int) $row['is_hidden'],
            'is_favorite' => (int) $row['is_favorite'],
        ];
    }

    return $states;
}

function hideRequestViewItem(PDO $pdo, int $userId, string $itemType, int $itemId): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO request_view_states
            (user_id, item_type, item_id, is_hidden, is_favorite, hidden_at)
         VALUES
            (:user_id, :item_type, :item_id, 1, 0, NOW())
         ON DUPLICATE KEY UPDATE
            is_hidden = 1,
            hidden_at = COALESCE(hidden_at, NOW()),
            updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        'user_id'   => $userId,
        'item_type' => $itemType,
        'item_id'   => $itemId,
    ]);
}

function toggleRequestViewFavorite(PDO $pdo, int $userId, string $itemType, int $itemId): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO request_view_states
            (user_id, item_type, item_id, is_hidden, is_favorite)
         VALUES
            (:user_id, :item_type, :item_id, 0, 1)
         ON DUPLICATE KEY UPDATE
            is_favorite = CASE WHEN is_favorite = 1 THEN 0 ELSE 1 END,
            updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        'user_id'   => $userId,
        'item_type' => $itemType,
        'item_id'   => $itemId,
    ]);
}

/**
 * 完了済みかつ対象シフト日から90日以上経過した申請表示を自動で非表示にする。
 * お気に入り済みの表示項目は非表示にしない。
 */
function cleanupOldRequestViewItems(PDO $pdo, int $userId, int $employeeId): void
{
    $autoHideSqlList = [
        [
            'type' => 'leave',
            'sql'  => "SELECT lr.id
                       FROM leave_requests lr
                       JOIN shifts s ON s.id = lr.shift_id
                       LEFT JOIN request_view_states rvs
                         ON rvs.user_id = :user_id_state
                        AND rvs.item_type = 'leave'
                        AND rvs.item_id = lr.id
                       WHERE lr.employee_id = :employee_id
                         AND lr.status IN ('approved', 'rejected', 'cancelled', 'cancelled_after_approval')
                         AND s.shift_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                         AND COALESCE(rvs.is_favorite, 0) = 0",
        ],
        [
            'type' => 'leave_cancel_before',
            'sql'  => "SELECT lr.id
                       FROM leave_requests lr
                       JOIN shifts s ON s.id = lr.shift_id
                       LEFT JOIN request_view_states rvs
                         ON rvs.user_id = :user_id_state
                        AND rvs.item_type = 'leave_cancel_before'
                        AND rvs.item_id = lr.id
                       WHERE lr.employee_id = :employee_id
                         AND lr.status = 'cancelled'
                         AND s.shift_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                         AND COALESCE(rvs.is_favorite, 0) = 0",
        ],
        [
            'type' => 'leave_cancel_after',
            'sql'  => "SELECT cr.id
                       FROM cancellation_requests cr
                       JOIN leave_requests lr ON lr.id = cr.leave_request_id
                       JOIN shifts s ON s.id = lr.shift_id
                       LEFT JOIN request_view_states rvs
                         ON rvs.user_id = :user_id_state
                        AND rvs.item_type = 'leave_cancel_after'
                        AND rvs.item_id = cr.id
                       WHERE cr.requested_by_employee_id = :employee_id
                         AND cr.request_type = 'requester_after_approval'
                         AND cr.status IN ('approved', 'rejected')
                         AND s.shift_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                         AND COALESCE(rvs.is_favorite, 0) = 0",
        ],
        [
            'type' => 'substitute',
            'sql'  => "SELECT sc.id
                       FROM substitute_candidates sc
                       JOIN leave_requests lr ON lr.id = sc.leave_request_id
                       JOIN shifts s ON s.id = lr.shift_id
                       LEFT JOIN request_view_states rvs
                         ON rvs.user_id = :user_id_state
                        AND rvs.item_type = 'substitute'
                        AND rvs.item_id = sc.id
                       WHERE sc.candidate_employee_id = :employee_id
                         AND sc.status IN ('accepted', 'expired')
                         AND sc.responded_at IS NOT NULL
                         AND s.shift_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                         AND COALESCE(rvs.is_favorite, 0) = 0",
        ],
        [
            'type' => 'substitute_cancel_before',
            'sql'  => "SELECT sc.id
                       FROM substitute_candidates sc
                       JOIN leave_requests lr ON lr.id = sc.leave_request_id
                       JOIN shifts s ON s.id = lr.shift_id
                       LEFT JOIN request_view_states rvs
                         ON rvs.user_id = :user_id_state
                        AND rvs.item_type = 'substitute_cancel_before'
                        AND rvs.item_id = sc.id
                       WHERE sc.candidate_employee_id = :employee_id
                         AND sc.status = 'declined'
                         AND s.shift_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                         AND COALESCE(rvs.is_favorite, 0) = 0",
        ],
        [
            'type' => 'substitute_cancel_after',
            'sql'  => "SELECT cr.id
                       FROM cancellation_requests cr
                       JOIN leave_requests lr ON lr.id = cr.leave_request_id
                       JOIN shifts s ON s.id = lr.shift_id
                       LEFT JOIN request_view_states rvs
                         ON rvs.user_id = :user_id_state
                        AND rvs.item_type = 'substitute_cancel_after'
                        AND rvs.item_id = cr.id
                       WHERE cr.requested_by_employee_id = :employee_id
                         AND cr.request_type = 'substitute_after_approval'
                         AND cr.status IN ('approved', 'rejected')
                         AND s.shift_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                         AND COALESCE(rvs.is_favorite, 0) = 0",
        ],
    ];

    foreach ($autoHideSqlList as $entry) {
        $stmt = $pdo->prepare($entry['sql']);
        $stmt->execute([
            'user_id_state' => $userId,
            'employee_id'   => $employeeId,
        ]);

        foreach ($stmt->fetchAll() as $row) {
            hideRequestViewItem($pdo, $userId, $entry['type'], (int) $row['id']);
        }
    }
}
