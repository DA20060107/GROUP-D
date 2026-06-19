<?php
/**
 * 代勤候補抽出・通知作成サービス
 *
 * 【必須条件（どの抽出モードでも必ず満たす）】
 *   休み申請されたシフトに対して、以下をすべて満たす従業員のみを代勤候補とする。
 *     1. 休み申請を出した本人ではない
 *     2. 従業員が有効状態（is_active = 1）である
 *     3. 休み申請対象のシフト日と勤務可能日（available_date）が一致している
 *     4. 勤務可能時間（start_time〜end_time）が対象シフトの勤務時間を覆っている
 *     5. 同じ日に時間帯が重複する別シフトが入っていない
 *     6. 既に同じ休み申請の候補者として登録済みでない
 *
 * 【スコア条件（必須条件を満たした候補者に対してのみ計算）】
 *   店長が設定した抽出モード（matching_settings.current_matching_mode）に応じて、
 *   ポジション一致度・スキルレベル・勤続年数・時間一致度の4項目を重み付けして
 *   100点満点のスコアを計算する（calculateCandidateScore()）。
 *   このスコアは絶対評価ではなく、候補者の表示順・店長の判断材料にするための
 *   相対的な指標である。
 *
 * 【今回（Step1）の範囲】
 *   スコア計算・抽出理由の保存・表示までを実装する。
 *   スコア上位3人だけへの通知、全員拒否時の次グループ通知などは未実装。
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
                req.name AS requester_name,
                s.id AS shift_id, s.shift_date, s.start_time, s.end_time, s.position
         FROM leave_requests lr
         JOIN shifts s ON s.id = lr.shift_id
         JOIN employees req ON req.id = lr.employee_id
         WHERE lr.id = :leave_request_id'
    );
    $stmt->execute(['leave_request_id' => $leaveRequestId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

// ------------------------------------------------------------
// 代勤候補抽出モード（matching_settings.current_matching_mode）
// ------------------------------------------------------------

/** 抽出モードとして許可する値 */
function getMatchingModes(): array
{
    return ['normal', 'staffing_priority', 'skill_priority'];
}

/**
 * 現在の代勤候補抽出モードを取得する
 * 未設定・不正な値の場合は 'normal'（通常）を返す
 */
function getCurrentMatchingMode(PDO $pdo): string
{
    $stmt = $pdo->query("SELECT setting_value FROM matching_settings WHERE setting_key = 'current_matching_mode'");
    $value = $stmt->fetchColumn();

    return ($value !== false && in_array($value, getMatchingModes(), true)) ? $value : 'normal';
}

/**
 * 現在の代勤候補抽出モードを設定する
 *
 * @throws InvalidArgumentException 不正なモードが指定された場合
 */
function setCurrentMatchingMode(PDO $pdo, string $mode): void
{
    if (!in_array($mode, getMatchingModes(), true)) {
        throw new InvalidArgumentException('不正な抽出モードです。');
    }

    $stmt = $pdo->prepare(
        "INSERT INTO matching_settings (setting_key, setting_value)
         VALUES ('current_matching_mode', :mode)
         ON DUPLICATE KEY UPDATE setting_value = :mode2"
    );
    $stmt->execute(['mode' => $mode, 'mode2' => $mode]);
}

/** 抽出モードの日本語表示名 */
function getMatchingModeLabel(string $mode): string
{
    $labels = [
        'normal'            => '通常',
        'staffing_priority' => '人員確保優先',
        'skill_priority'    => 'スキル重視',
    ];
    return $labels[$mode] ?? $mode;
}

/** 抽出モードの説明文 */
function getMatchingModeDescription(string $mode): string
{
    $descriptions = [
        'normal'            => '通常時は、勤務可能であることに加え、ポジション・スキル・勤続年数・時間一致度をバランスよく評価します。',
        'staffing_priority' => '人員不足時は、ポジションやスキルよりも、対象時間に出勤できることを重視します。',
        'skill_priority'    => '業務品質を保ちたい場合は、対象業務に対応できるスキルや経験を持つ従業員を優先します。',
    ];
    return $descriptions[$mode] ?? '';
}

/**
 * 抽出モードごとのスコア重み（内部初期値、合計100）
 * 店長に細かい配分を設定させず、運用目的に応じたプリセットとして扱う。
 */
function getMatchingWeights(string $mode): array
{
    $weights = [
        'normal'            => ['position' => 30, 'skill' => 30, 'tenure' => 20, 'time' => 20],
        'staffing_priority' => ['position' => 10, 'skill' => 10, 'tenure' => 10, 'time' => 70],
        'skill_priority'    => ['position' => 30, 'skill' => 50, 'tenure' => 15, 'time' => 5],
    ];
    return $weights[$mode] ?? $weights['normal'];
}

// ------------------------------------------------------------
// スコア条件（必須条件を満たした候補者に対してのみ計算する）
// ------------------------------------------------------------

/** ポジション一致度のスコア（0〜100）とラベルを算出する */
function scorePositionMatch(?string $candidatePosition, ?string $shiftPosition): array
{
    $candidatePosition = trim((string) $candidatePosition);
    $shiftPosition      = trim((string) $shiftPosition);

    if ($candidatePosition === '' || $shiftPosition === '') {
        return ['score' => 50, 'label' => 'ポジション情報なし'];
    }
    if ($candidatePosition === $shiftPosition) {
        return ['score' => 100, 'label' => 'ポジション一致'];
    }
    // 「ホール・レジ」のように複数業務をまとめて持つデータがあるため、部分一致は中間評価とする
    if (mb_strpos($candidatePosition, $shiftPosition) !== false || mb_strpos($shiftPosition, $candidatePosition) !== false) {
        return ['score' => 70, 'label' => 'ポジション部分一致'];
    }
    return ['score' => 0, 'label' => 'ポジション不一致'];
}

/** スキルレベル（1〜5）のスコアとラベルを算出する */
function scoreSkillLevel(int $skillLevel): array
{
    $skillLevel = max(1, min(5, $skillLevel));
    $map = [5 => 100, 4 => 80, 3 => 60, 2 => 40, 1 => 20];
    return ['score' => $map[$skillLevel], 'label' => 'スキルレベル' . $skillLevel];
}

/** 勤続年数のスコアとラベルを算出する（$referenceDate: 対象シフト日 Y-m-d） */
function scoreTenure(?string $hireDate, string $referenceDate): array
{
    if ($hireDate === null || $hireDate === '') {
        return ['score' => 50, 'label' => '勤続情報なし'];
    }

    $days = (strtotime($referenceDate) - strtotime($hireDate)) / 86400;

    if ($days >= 365) {
        return ['score' => 100, 'label' => '勤続1年以上'];
    }
    if ($days >= 180) {
        return ['score' => 70, 'label' => '勤続6か月以上'];
    }
    if ($days >= 90) {
        return ['score' => 40, 'label' => '勤続3か月以上'];
    }
    return ['score' => 20, 'label' => '勤続3か月未満'];
}

/** 時間一致度のスコアとラベルを算出する（$extraSeconds: 勤務可能時間がシフト時間を上回る秒数。不明な場合は null） */
function scoreTimeMatch(?int $extraSeconds): array
{
    if ($extraSeconds === null) {
        return ['score' => 70, 'label' => '勤務可能時間が対象シフトをカバー'];
    }
    if ($extraSeconds <= 0) {
        return ['score' => 100, 'label' => '勤務可能時間が対象シフトとほぼ一致'];
    }
    if ($extraSeconds <= 3600) {
        return ['score' => 85, 'label' => '勤務可能時間が対象シフトに近い'];
    }
    if ($extraSeconds <= 10800) {
        return ['score' => 60, 'label' => '勤務可能時間が対象シフトより広め'];
    }
    return ['score' => 40, 'label' => '勤務可能時間が対象シフトより大幅に広い'];
}

/**
 * 対象シフトをカバーする勤務可能時間のうち、最も近い（無駄が少ない）ものとの差（秒）を取得する
 * 該当する勤務可能日登録が見つからない場合は null を返す
 */
function getTightestAvailabilityExtraSeconds(PDO $pdo, int $employeeId, array $shift): ?int
{
    $stmt = $pdo->prepare(
        'SELECT MIN(
                (TIME_TO_SEC(end_time) - TIME_TO_SEC(start_time))
                - (TIME_TO_SEC(:shift_end) - TIME_TO_SEC(:shift_start))
            ) AS extra_seconds
         FROM availability
         WHERE employee_id = :employee_id
           AND available_date = :shift_date
           AND start_time <= :start_time
           AND end_time >= :end_time'
    );
    $stmt->execute([
        'shift_end'   => $shift['end_time'],
        'shift_start' => $shift['start_time'],
        'employee_id' => $employeeId,
        'shift_date'  => $shift['shift_date'],
        'start_time'  => $shift['start_time'],
        'end_time'    => $shift['end_time'],
    ]);
    $value = $stmt->fetchColumn();

    return ($value !== false && $value !== null) ? (int) $value : null;
}

/**
 * 候補者・対象シフト・抽出モードから、スコア（0〜100）と抽出理由を算出する
 *
 * @param array $candidate employee_id, position, skill_level, hire_date を含む連想配列
 * @param array $shift     shift_date, start_time, end_time, position を含む連想配列
 *
 * @return array score(int), reason(string), details(array) を含む連想配列
 */
function calculateCandidateScore(array $candidate, array $shift, ?int $availabilityExtraSeconds, string $mode): array
{
    $weights = getMatchingWeights($mode);

    $details = [
        'position' => scorePositionMatch($candidate['position'] ?? null, $shift['position'] ?? null),
        'skill'    => scoreSkillLevel((int) ($candidate['skill_level'] ?? 3)),
        'tenure'   => scoreTenure($candidate['hire_date'] ?? null, $shift['shift_date']),
        'time'     => scoreTimeMatch($availabilityExtraSeconds),
    ];

    $total = (
        $details['position']['score'] * $weights['position']
        + $details['skill']['score'] * $weights['skill']
        + $details['tenure']['score'] * $weights['tenure']
        + $details['time']['score'] * $weights['time']
    ) / 100;

    return [
        'score'   => (int) round($total),
        'reason'  => buildMatchReason($details, $mode),
        'details' => $details,
    ];
}

/** スコア内訳のラベルを連結し、抽出理由の文言を組み立てる */
function buildMatchReason(array $scoreDetails, string $mode): string
{
    $labels = [
        $scoreDetails['position']['label'] ?? '',
        $scoreDetails['skill']['label'] ?? '',
        $scoreDetails['tenure']['label'] ?? '',
        $scoreDetails['time']['label'] ?? '',
    ];
    $labels = array_filter($labels, static function ($label) {
        return $label !== '';
    });

    return implode('、', $labels);
}

/**
 * 休み申請に対する代勤候補（従業員）を抽出する（必須条件のみで絞り込む）
 *
 * @return array 候補従業員の配列（employee_id, name, position, skill_level, hire_date を含む連想配列）
 */
function findSubstituteCandidates(PDO $pdo, int $leaveRequestId): array
{
    $target = getLeaveRequestShift($pdo, $leaveRequestId);
    if ($target === null) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT DISTINCT e.id AS employee_id, e.name, e.position, e.skill_level, e.hire_date
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
 * 必須条件を満たした候補者（findSubstituteCandidates()）に対して、
 * 指定された抽出モードでスコア・抽出理由を計算し、スコアの高い順に登録する。
 * 既に登録済みの候補者は findSubstituteCandidates() の時点で除外されるため、
 * 重複登録は発生しない。
 *
 * @return array スコア降順に並んだ候補従業員の配列（employee_id, name, match_score, match_reason を含む）
 */
function createSubstituteCandidates(PDO $pdo, int $leaveRequestId, string $mode): array
{
    $candidates = findSubstituteCandidates($pdo, $leaveRequestId);

    if (empty($candidates)) {
        return [];
    }

    $target = getLeaveRequestShift($pdo, $leaveRequestId);

    foreach ($candidates as &$candidate) {
        $extraSeconds = getTightestAvailabilityExtraSeconds($pdo, (int) $candidate['employee_id'], $target);
        $result       = calculateCandidateScore($candidate, $target, $extraSeconds, $mode);

        $candidate['match_score']  = $result['score'];
        $candidate['match_reason'] = $result['reason'];
    }
    unset($candidate);

    usort($candidates, static function (array $a, array $b): int {
        if ($a['match_score'] === $b['match_score']) {
            return $a['employee_id'] <=> $b['employee_id'];
        }
        return $b['match_score'] <=> $a['match_score'];
    });

    $stmt = $pdo->prepare(
        "INSERT INTO substitute_candidates
            (leave_request_id, candidate_employee_id, status, match_score, match_reason, matched_at)
         VALUES (:leave_request_id, :candidate_employee_id, 'proposed', :match_score, :match_reason, NOW())"
    );

    foreach ($candidates as $candidate) {
        $stmt->execute([
            'leave_request_id'      => $leaveRequestId,
            'candidate_employee_id' => $candidate['employee_id'],
            'match_score'           => $candidate['match_score'],
            'match_reason'          => $candidate['match_reason'],
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
 * 現在の抽出モード（matching_settings）を取得し、休み申請にその時点の
 * モードを記録した上で、候補抽出・スコア計算を行う。
 *
 * @return string 更新後の leave_requests.status（'matching' または 'no_candidate'）
 */
function processSubstituteMatching(PDO $pdo, int $leaveRequestId): string
{
    $mode = getCurrentMatchingMode($pdo);

    $pdo->prepare('UPDATE leave_requests SET matching_mode = :mode WHERE id = :id')
        ->execute(['mode' => $mode, 'id' => $leaveRequestId]);

    $candidates = createSubstituteCandidates($pdo, $leaveRequestId, $mode);

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
 * 休み申請キャンセル時に、関連する代勤候補者と店長へ通知を作成する
 *
 * 同じ休み申請・通知種別の通知が既に存在する場合は重複作成しない。
 */
function createLeaveRequestCancellationNotifications(PDO $pdo, int $leaveRequestId): void
{
    $target = getLeaveRequestShift($pdo, $leaveRequestId);
    if ($target === null) {
        return;
    }

    $shiftDateLabel = date('n月j日', strtotime($target['shift_date']));
    $startLabel     = substr($target['start_time'], 0, 5);
    $endLabel       = substr($target['end_time'], 0, 5);

    $candidateMessage = sprintf(
        '%sの%s〜%sのシフトに関する代勤依頼は、休み申請者によりキャンセルされました。',
        $shiftDateLabel,
        $startLabel,
        $endLabel
    );

    $stmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, type, title, message, is_read, related_leave_request_id)
         SELECT DISTINCT u.id, 'leave_request_cancelled', '代勤依頼がキャンセルされました',
                :message, 0, :leave_request_id_notification
         FROM substitute_candidates sc
         JOIN users u
           ON u.role = 'employee'
          AND u.employee_id = sc.candidate_employee_id
         WHERE sc.leave_request_id = :leave_request_id_candidate
           AND NOT EXISTS (
               SELECT 1 FROM notifications n
               WHERE n.user_id = u.id
                 AND n.type = 'leave_request_cancelled'
                 AND n.related_leave_request_id = :leave_request_id_check
           )"
    );
    $stmt->execute([
        'message'                       => $candidateMessage,
        'leave_request_id_notification' => $leaveRequestId,
        'leave_request_id_candidate'    => $leaveRequestId,
        'leave_request_id_check'        => $leaveRequestId,
    ]);

    $managerMessage = sprintf(
        '%sさんの%sの%s〜%sの休み申請は、本人によりキャンセルされました。',
        $target['requester_name'],
        $shiftDateLabel,
        $startLabel,
        $endLabel
    );

    $stmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, type, title, message, is_read, related_leave_request_id)
         SELECT u.id, 'leave_request_cancelled', '休み申請がキャンセルされました',
                :message, 0, :leave_request_id_notification
         FROM users u
         WHERE u.role = 'manager'
           AND NOT EXISTS (
               SELECT 1 FROM notifications n
               WHERE n.user_id = u.id
                 AND n.type = 'leave_request_cancelled'
                 AND n.related_leave_request_id = :leave_request_id_check
           )"
    );
    $stmt->execute([
        'message'                       => $managerMessage,
        'leave_request_id_notification' => $leaveRequestId,
        'leave_request_id_check'        => $leaveRequestId,
    ]);
}

/**
 * 従業員本人の休み申請を、店長処理前にキャンセルする
 *
 * キャンセル可能な状態は matching / no_candidate のみ。
 * 同時に関連候補者をすべて expired にし、対象シフトを scheduled に戻す。
 *
 * @return string cancelled / not_found / not_cancellable
 */
function cancelLeaveRequest(PDO $pdo, int $leaveRequestId, int $employeeId): string
{
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT id, shift_id, status
             FROM leave_requests
             WHERE id = :leave_request_id AND employee_id = :employee_id
             FOR UPDATE'
        );
        $stmt->execute([
            'leave_request_id' => $leaveRequestId,
            'employee_id'      => $employeeId,
        ]);
        $leaveRequest = $stmt->fetch();

        if ($leaveRequest === false) {
            $pdo->rollBack();
            return 'not_found';
        }

        if (!in_array($leaveRequest['status'], ['matching', 'no_candidate'], true)) {
            $pdo->rollBack();
            return 'not_cancellable';
        }

        $pdo->prepare("UPDATE leave_requests SET status = 'cancelled' WHERE id = :id")
            ->execute(['id' => $leaveRequestId]);

        // キャンセル理由にかかわらず全候補者を無効化し、古い回答リンクを使えなくする。
        $pdo->prepare(
            "UPDATE substitute_candidates
             SET status = 'expired'
             WHERE leave_request_id = :leave_request_id"
        )->execute(['leave_request_id' => $leaveRequestId]);

        // 店長承認前のキャンセルなので、元の担当者のシフトを再び申請可能な予定状態へ戻す。
        $pdo->prepare(
            "UPDATE shifts
             SET status = 'scheduled'
             WHERE id = :shift_id AND status = 'leave_requested'"
        )->execute(['shift_id' => $leaveRequest['shift_id']]);

        createLeaveRequestCancellationNotifications($pdo, $leaveRequestId);

        $pdo->commit();
        return 'cancelled';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
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
