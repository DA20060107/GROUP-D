<?php
/**
 * 代勤候補抽出・通知作成サービス
 *
 * 【現在の暫定抽出条件】
 *   休み申請されたシフトに対して、以下をすべて満たす従業員を代勤候補とする。
 *     1. 休み申請を出した本人ではない
 *     2. 従業員が有効状態（is_active = 1）である
 *     3. 休み申請対象のシフト日と勤務可能日（available_date）が一致している
 *     4. 勤務可能時間（start_time〜end_time）が対象シフトの勤務時間を覆っている
 *     5. 同じ日に時間帯が重複する別シフトが入っていない
 *     6. 既に同じ休み申請の候補者として登録済みでない
 *
 * 【将来の拡張予定】
 *   スキル・担当可能ポジション・勤続年数・過去の代勤回数・店長側の優先度などを
 *   組み合わせたスコアリング方式に変更する予定。
 *   そのため、候補者抽出処理（findSubstituteCandidates）を独立した関数として
 *   分離しており、将来的にはこの関数の内部実装のみを変更すれば対応できる
 *   構造にしている。
 */

/**
 * 休み申請に紐づくシフト情報を取得する
 *
 * @return array|null leave_request_id, requester_employee_id, shift_id,
 *                     shift_date, start_time, end_time, position を含む連想配列。
 *                     対象の休み申請が存在しない場合は null。
 */
function getLeaveRequestShift(PDO $pdo, int $leaveRequestId)
{
    $stmt = $pdo->prepare(
        'SELECT lr.id AS leave_request_id, lr.employee_id AS requester_employee_id,
                s.id AS shift_id, s.shift_date, s.start_time, s.end_time, s.position
         FROM leave_requests lr
         JOIN shifts s ON s.id = lr.shift_id
         WHERE lr.id = :leave_request_id'
    );
    $stmt->execute(['leave_request_id' => $leaveRequestId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * 休み申請に対する代勤候補（従業員）を抽出する
 *
 * @return array 候補従業員の配列（各要素は employee_id, name を含む連想配列）
 */
function findSubstituteCandidates(PDO $pdo, int $leaveRequestId): array
{
    $target = getLeaveRequestShift($pdo, $leaveRequestId);
    if ($target === null) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT DISTINCT e.id AS employee_id, e.name
         FROM employees e
         JOIN availability a ON a.employee_id = e.id
         WHERE e.is_active = 1
           AND e.id <> :requester_employee_id
           AND a.available_date = :shift_date
           AND a.start_time <= :start_time
           AND a.end_time >= :end_time
           AND NOT EXISTS (
               SELECT 1 FROM shifts s2
               WHERE s2.employee_id = e.id
                 AND s2.shift_date = :shift_date2
                 AND s2.status <> "cancelled"
                 AND NOT (s2.end_time <= :start_time2 OR s2.start_time >= :end_time2)
           )
           AND NOT EXISTS (
               SELECT 1 FROM substitute_candidates sc
               WHERE sc.leave_request_id = :leave_request_id
                 AND sc.candidate_employee_id = e.id
           )
         ORDER BY e.id'
    );
    $stmt->execute([
        'requester_employee_id' => $target['requester_employee_id'],
        'shift_date'            => $target['shift_date'],
        'start_time'            => $target['start_time'],
        'end_time'              => $target['end_time'],
        'shift_date2'           => $target['shift_date'],
        'start_time2'           => $target['start_time'],
        'end_time2'             => $target['end_time'],
        'leave_request_id'      => $leaveRequestId,
    ]);

    return $stmt->fetchAll();
}

/**
 * 代勤候補を substitute_candidates テーブルに登録する
 *
 * 既に登録済みの候補者は findSubstituteCandidates() の時点で除外されるため、
 * 重複登録は発生しない。
 *
 * @return array 新たに登録した候補従業員の配列（employee_id, name を含む）
 */
function createSubstituteCandidates(PDO $pdo, int $leaveRequestId): array
{
    $candidates = findSubstituteCandidates($pdo, $leaveRequestId);

    if (empty($candidates)) {
        return [];
    }

    // match_score / match_reason は暫定の固定値。
    // 将来的にはスキル・ポジション一致度などから算出したスコアに置き換える。
    $stmt = $pdo->prepare(
        "INSERT INTO substitute_candidates
            (leave_request_id, candidate_employee_id, status, match_score, match_reason, matched_at)
         VALUES (:leave_request_id, :candidate_employee_id, 'proposed', :match_score, :match_reason, NOW())"
    );

    foreach ($candidates as $candidate) {
        $stmt->execute([
            'leave_request_id'      => $leaveRequestId,
            'candidate_employee_id' => $candidate['employee_id'],
            'match_score'           => 100,
            'match_reason'          => '勤務可能日・時間が一致',
        ]);
    }

    return $candidates;
}

/**
 * 代勤候補（従業員）へ「代勤依頼」通知を作成する
 *
 * 同一の休み申請・候補者に対して既に通知が作成済みの場合は、
 * 重複して作成しない。
 */
function createCandidateNotifications(PDO $pdo, int $leaveRequestId, array $candidates): void
{
    if (empty($candidates)) {
        return;
    }

    $target = getLeaveRequestShift($pdo, $leaveRequestId);
    if ($target === null) {
        return;
    }

    $message = sprintf(
        '%sの%s〜%sのシフトについて、代勤可能か回答してください。',
        $target['shift_date'],
        substr($target['start_time'], 0, 5),
        substr($target['end_time'], 0, 5)
    );

    $stmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, type, title, message, is_read, related_leave_request_id)
         SELECT u.id, 'substitute_request', '代勤依頼が届いています', :message, 0, :leave_request_id
         FROM users u
         WHERE u.role = 'employee' AND u.employee_id = :employee_id
           AND NOT EXISTS (
               SELECT 1 FROM notifications n
               WHERE n.user_id = u.id
                 AND n.type = 'substitute_request'
                 AND n.related_leave_request_id = :leave_request_id2
           )"
    );

    foreach ($candidates as $candidate) {
        $stmt->execute([
            'message'           => $message,
            'leave_request_id'  => $leaveRequestId,
            'employee_id'       => $candidate['employee_id'],
            'leave_request_id2' => $leaveRequestId,
        ]);
    }
}

/**
 * 代勤候補が見つからなかった場合に、店長へ「候補者なし」通知を作成する
 *
 * 同一の休み申請に対して既に通知が作成済みの場合は、重複して作成しない。
 */
function createNoCandidateNotification(PDO $pdo, int $leaveRequestId): void
{
    $target = getLeaveRequestShift($pdo, $leaveRequestId);
    if ($target === null) {
        return;
    }

    $message = sprintf(
        '%sの%s〜%sのシフトに対する休み申請について、条件に合う代勤候補が見つかりませんでした。手動で調整してください。',
        $target['shift_date'],
        substr($target['start_time'], 0, 5),
        substr($target['end_time'], 0, 5)
    );

    $stmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, type, title, message, is_read, related_leave_request_id)
         SELECT u.id, 'no_candidate', '代勤候補が見つかりません', :message, 0, :leave_request_id
         FROM users u
         WHERE u.role = 'manager'
           AND NOT EXISTS (
               SELECT 1 FROM notifications n
               WHERE n.user_id = u.id
                 AND n.type = 'no_candidate'
                 AND n.related_leave_request_id = :leave_request_id2
           )"
    );
    $stmt->execute([
        'message'           => $message,
        'leave_request_id'  => $leaveRequestId,
        'leave_request_id2' => $leaveRequestId,
    ]);
}

/**
 * 代勤候補が「代勤可能」と回答した場合に、店長へ確認用通知を作成する
 *
 * 同一の代勤候補回答に対して既に通知が作成済みの場合は、重複して作成しない
 * （type・related_leave_request_id・message の組み合わせで判定）。
 */
function createCandidateAvailableNotification(PDO $pdo, int $candidateId): void
{
    $stmt = $pdo->prepare(
        'SELECT sc.leave_request_id, e.name AS candidate_name, s.shift_date
         FROM substitute_candidates sc
         JOIN leave_requests lr ON lr.id = sc.leave_request_id
         JOIN shifts s ON s.id = lr.shift_id
         JOIN employees e ON e.id = sc.candidate_employee_id
         WHERE sc.id = :candidate_id'
    );
    $stmt->execute(['candidate_id' => $candidateId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return;
    }

    $shiftDateLabel = date('n月j日', strtotime($row['shift_date']));

    $message = sprintf(
        '%sのシフトについて、%sさんが代勤可能と回答しました。承認画面で確認してください。',
        $shiftDateLabel,
        $row['candidate_name']
    );

    $stmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, type, title, message, is_read, related_leave_request_id)
         SELECT u.id, 'candidate_available', '代勤可能な候補者が回答しました', :message, 0, :leave_request_id
         FROM users u
         WHERE u.role = 'manager'
           AND NOT EXISTS (
               SELECT 1 FROM notifications n
               WHERE n.user_id = u.id
                 AND n.type = 'candidate_available'
                 AND n.related_leave_request_id = :leave_request_id2
                 AND n.message = :message2
           )"
    );
    $stmt->execute([
        'message'           => $message,
        'leave_request_id'  => $row['leave_request_id'],
        'leave_request_id2' => $row['leave_request_id'],
        'message2'          => $message,
    ]);
}

/**
 * 休み申請に対する代勤候補抽出・通知作成・状態更新をまとめて行う
 *
 * 呼び出し元（pages/employee/leave_request.php）で、休み申請の登録と
 * 合わせてトランザクション内から呼び出すことを想定している。
 *
 * @return string 更新後の leave_requests.status（'matching' または 'no_candidate'）
 */
function processSubstituteMatching(PDO $pdo, int $leaveRequestId): string
{
    $candidates = createSubstituteCandidates($pdo, $leaveRequestId);

    if (!empty($candidates)) {
        createCandidateNotifications($pdo, $leaveRequestId, $candidates);
        $newStatus = 'matching';
    } else {
        createNoCandidateNotification($pdo, $leaveRequestId);
        $newStatus = 'no_candidate';
    }

    $pdo->prepare('UPDATE leave_requests SET status = :status WHERE id = :id')
        ->execute(['status' => $newStatus, 'id' => $leaveRequestId]);

    return $newStatus;
}

/**
 * 指定した従業員（employee_id）に紐づく users 宛に通知を作成する
 */
function insertNotificationForEmployee(PDO $pdo, int $employeeId, string $type, string $title, string $message, int $leaveRequestId): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, type, title, message, is_read, related_leave_request_id)
         SELECT u.id, :type, :title, :message, 0, :leave_request_id
         FROM users u
         WHERE u.role = 'employee' AND u.employee_id = :employee_id"
    );
    $stmt->execute([
        'type'             => $type,
        'title'            => $title,
        'message'          => $message,
        'leave_request_id' => $leaveRequestId,
        'employee_id'      => $employeeId,
    ]);
}

/**
 * 店長の承認・却下結果に応じて、関係者へ「承認結果」通知を作成する
 *
 * - 承認時: 休み申請者本人 と、代勤対応が確定した従業員の両方に通知する
 * - 却下時: 休み申請者本人のみに通知する
 *
 * @param string   $result               'approved' または 'rejected'
 * @param int|null $approvedCandidateEmployeeId 承認時に代勤対応が確定した従業員のID（却下時は null）
 */
function createApprovalResultNotifications(PDO $pdo, int $leaveRequestId, string $result, ?int $approvedCandidateEmployeeId = null): void
{
    $target = getLeaveRequestShift($pdo, $leaveRequestId);
    if ($target === null) {
        return;
    }

    $shiftDateLabel = date('n月j日', strtotime($target['shift_date']));
    $startLabel     = substr($target['start_time'], 0, 5);
    $endLabel       = substr($target['end_time'], 0, 5);

    if ($result === 'approved') {
        $requesterMessage = sprintf(
            '%sの%s〜%sのシフトの休み申請が承認されました。代勤者が見つかりましたので、安心してお休みください。',
            $shiftDateLabel,
            $startLabel,
            $endLabel
        );
        insertNotificationForEmployee(
            $pdo,
            $target['requester_employee_id'],
            'approval_result',
            '休み申請が承認されました',
            $requesterMessage,
            $leaveRequestId
        );

        if ($approvedCandidateEmployeeId !== null) {
            $substituteMessage = sprintf(
                '%sの%s〜%sのシフトについて、代勤として対応することが確定しました。',
                $shiftDateLabel,
                $startLabel,
                $endLabel
            );
            insertNotificationForEmployee(
                $pdo,
                $approvedCandidateEmployeeId,
                'approval_result',
                '代勤対応が確定しました',
                $substituteMessage,
                $leaveRequestId
            );
        }
    } else {
        $requesterMessage = sprintf(
            '%sの%s〜%sのシフトの休み申請は却下されました。詳細は店長にご確認ください。',
            $shiftDateLabel,
            $startLabel,
            $endLabel
        );
        insertNotificationForEmployee(
            $pdo,
            $target['requester_employee_id'],
            'approval_result',
            '休み申請が却下されました',
            $requesterMessage,
            $leaveRequestId
        );
    }
}
