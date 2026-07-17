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
 * 【スコア計算の範囲】
 *   スコア計算・抽出理由の保存・表示に加え、スコア上位3人への段階通知を実装している。
 *   通知済み候補者が辞退した場合は、空いた通知枠に次点候補を追加通知する。
 *
 * 【代勤候補の再抽出】
 *   一度抽出した候補が確保できなくなった場合（代勤者キャンセル承認時の自動再抽出、
 *   店長による no_candidate / replacement_pending の手動再抽出）の処理は、
 *   retrySubstituteMatching() 以下の関数群（本ファイル後半）で実装している。
 *   状態名・除外条件の正式な一覧は README.md の「状態一覧」セクションを参照。
 */

/**
 * 休み申請に紐づくシフト情報を取得する
 *
 * @return array|null leave_request_id, requester_employee_id, shift_id,
 *                     shift_date, start_time, end_time, position を含む連想配列。
 *                     対象の休み申請が存在しない場合は null。
 */
require_once __DIR__ . '/../includes/position_helpers.php';

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

/** 代勤依頼を同時に通知する上限人数 */
function getCandidateNotificationLimit(): int
{
    return 3;
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
        'normal'            => '勤務可能であることに加え、ポジション・スキル・勤続年数・時間一致度をバランスよく評価します。',
        'staffing_priority' => '一部の時間だけ勤務可能な従業員も候補に含め、人員確保の可能性を広げます。',
        'skill_priority'    => '対象業務に対応できるスキルや経験を持つ従業員を優先します。',
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
        'normal'            => ['position' => 40, 'skill' => 35, 'tenure' => 25, 'time' => 0],
        'staffing_priority' => ['position' => 25, 'skill' => 15, 'tenure' => 10, 'time' => 50],
        'skill_priority'    => ['position' => 35, 'skill' => 50, 'tenure' => 15, 'time' => 0],
    ];
    return $weights[$mode] ?? $weights['normal'];
}

// ------------------------------------------------------------
// スコア条件（必須条件を満たした候補者に対してのみ計算する）
// ------------------------------------------------------------

/** ポジション一致度のスコア（0〜100）とラベルを算出する */
function scorePositionMatch(?string $candidatePosition, ?string $shiftPosition): array
{
    $candidateItems = parsePositionItems($candidatePosition);
    $requiredItems  = parsePositionItems($shiftPosition);

    if (empty($candidateItems) || empty($requiredItems)) {
        return ['score' => 0, 'label' => 'ポジション情報なし'];
    }
    if (positionCandidateCoversAll($candidateItems, $requiredItems)) {
        return ['score' => 100, 'label' => '必要ポジション対応可'];
    }
    // 候補者側が必要業務をすべて含む場合は満点、一部だけ対応できる場合は中間評価とする
    if (positionHasPartialOverlap($candidateItems, $requiredItems)) {
        return ['score' => 70, 'label' => 'ポジション一部対応'];
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
        return ['score' => 0, 'label' => '勤続情報なし'];
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

/** 時刻（HH:MM[:SS]）を秒へ変換する */
function timeToSecondsValue(?string $time): int
{
    $parts = array_map('intval', explode(':', (string) $time));
    $hour = $parts[0] ?? 0;
    $minute = $parts[1] ?? 0;
    $second = $parts[2] ?? 0;

    return $hour * 3600 + $minute * 60 + $second;
}

/** 勤務可能時間が対象シフトをどれだけカバーしているかを算出する */
function getBestAvailabilityCoverage(PDO $pdo, int $employeeId, array $shift, bool $allowPartialCoverage): ?array
{
    $timeCondition = $allowPartialCoverage
        ? 'start_time < :end_time AND end_time > :start_time'
        : 'start_time <= :start_time AND end_time >= :end_time';

    $stmt = $pdo->prepare(
        "SELECT start_time, end_time
         FROM availability
         WHERE employee_id = :employee_id
           AND available_date = :shift_date
           AND {$timeCondition}"
    );
    $stmt->execute([
        'employee_id' => $employeeId,
        'shift_date'  => $shift['shift_date'],
        'start_time'  => $shift['start_time'],
        'end_time'    => $shift['end_time'],
    ]);

    $shiftStart = timeToSecondsValue($shift['start_time'] ?? null);
    $shiftEnd   = timeToSecondsValue($shift['end_time'] ?? null);
    $shiftSeconds = max(0, $shiftEnd - $shiftStart);
    if ($shiftSeconds <= 0) {
        return null;
    }

    $bestCoverage = null;
    foreach ($stmt->fetchAll() as $availability) {
        $availableStart = timeToSecondsValue($availability['start_time'] ?? null);
        $availableEnd   = timeToSecondsValue($availability['end_time'] ?? null);
        $coveredSeconds = max(0, min($availableEnd, $shiftEnd) - max($availableStart, $shiftStart));
        $extraSeconds = max(0, ($availableEnd - $availableStart) - $shiftSeconds);

        $coverage = [
            'covered_seconds' => $coveredSeconds,
            'shift_seconds'   => $shiftSeconds,
            'extra_seconds'   => $extraSeconds,
            'fully_covers'    => $availableStart <= $shiftStart && $availableEnd >= $shiftEnd,
        ];

        if (
            $bestCoverage === null
            || $coverage['covered_seconds'] > $bestCoverage['covered_seconds']
            || (
                $coverage['covered_seconds'] === $bestCoverage['covered_seconds']
                && $coverage['extra_seconds'] < $bestCoverage['extra_seconds']
            )
        ) {
            $bestCoverage = $coverage;
        }
    }

    return $bestCoverage;
}

/** 時間条件のスコアとラベルを算出する */
function scoreTimeMatch(?array $coverage, string $mode): array
{
    if ($coverage === null || ($coverage['shift_seconds'] ?? 0) <= 0) {
        return ['score' => 0, 'label' => '勤務可能時間情報なし'];
    }

    if (!empty($coverage['fully_covers'])) {
        return ['score' => 100, 'label' => '勤務可能時間が対象シフトを全てカバー'];
    }

    $ratio = max(0, min(1, $coverage['covered_seconds'] / $coverage['shift_seconds']));
    $score = (int) round($ratio * 100);
    $percent = (int) round($ratio * 100);

    if ($mode === 'staffing_priority') {
        return ['score' => $score, 'label' => "勤務可能時間が対象シフトの{$percent}%をカバー（一部時間のみ）"];
    }

    return ['score' => 0, 'label' => '勤務可能時間が対象シフトを全てカバーしていない'];
}

/**
 * 候補者・対象シフト・抽出モードから、スコア（0〜100）と抽出理由を算出する
 *
 * @param array $candidate employee_id, position, skill_level, hire_date を含む連想配列
 * @param array $shift     shift_date, start_time, end_time, position を含む連想配列
 *
 * @return array score(int), reason(string), details(array) を含む連想配列
 */
function calculateCandidateScore(array $candidate, array $shift, ?array $availabilityCoverage, string $mode): array
{
    $weights = getMatchingWeights($mode);

    $details = [
        'position' => scorePositionMatch($candidate['position'] ?? null, $shift['position'] ?? null),
        'skill'    => scoreSkillLevel((int) ($candidate['skill_level'] ?? 3)),
        'tenure'   => scoreTenure($candidate['hire_date'] ?? null, $shift['shift_date']),
        'time'     => scoreTimeMatch($availabilityCoverage, $mode),
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
function findSubstituteCandidates(PDO $pdo, int $leaveRequestId, string $mode = 'normal'): array
{
    $target = getLeaveRequestShift($pdo, $leaveRequestId);
    if ($target === null) {
        return [];
    }

    $timeCondition = ($mode === 'staffing_priority')
        ? 'AND a.start_time < :end_time AND a.end_time > :start_time'
        : 'AND a.start_time <= :start_time AND a.end_time >= :end_time';

    $stmt = $pdo->prepare(
        "SELECT DISTINCT e.id AS employee_id, e.name, e.position, e.skill_level, e.hire_date
         FROM employees e
         JOIN availability a ON a.employee_id = e.id
         WHERE e.is_active = 1
           AND e.id <> :requester_employee_id
           AND a.available_date = :shift_date
           {$timeCondition}
           AND NOT EXISTS (
               SELECT 1 FROM shifts s2
               WHERE s2.employee_id = e.id
                 AND s2.shift_date = :shift_date2
                  AND s2.status <> 'cancelled'
                 AND NOT (s2.end_time <= :start_time2 OR s2.start_time >= :end_time2)
           )
           AND NOT EXISTS (
               SELECT 1 FROM substitute_candidates sc
               WHERE sc.leave_request_id = :leave_request_id
                 AND sc.candidate_employee_id = e.id
           )
         ORDER BY e.id"
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
    $candidates = findSubstituteCandidates($pdo, $leaveRequestId, $mode);

    if (empty($candidates)) {
        return [];
    }

    $target = getLeaveRequestShift($pdo, $leaveRequestId);

    foreach ($candidates as &$candidate) {
        $coverage = getBestAvailabilityCoverage(
            $pdo,
            (int) $candidate['employee_id'],
            $target,
            $mode === 'staffing_priority'
        );
        $result = calculateCandidateScore($candidate, $target, $coverage, $mode);

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
function createCandidateNotifications(
    PDO $pdo,
    int $leaveRequestId,
    array $candidates,
    bool $allowDuplicate = false,
    ?string $customTitle = null,
    ?string $customMessage = null
): int
{
    if (empty($candidates)) {
        return 0;
    }

    $target = getLeaveRequestShift($pdo, $leaveRequestId);
    if ($target === null) {
        return 0;
    }

    $title = $customTitle ?? '代勤依頼が届いています';
    $message = $customMessage ?? sprintf(
        '%sの%s〜%sのシフトについて、代勤可能か回答してください。',
        $target['shift_date'],
        substr($target['start_time'], 0, 5),
        substr($target['end_time'], 0, 5)
    );

    if ($allowDuplicate) {
        $stmt = $pdo->prepare(
            "INSERT INTO notifications (user_id, type, title, message, is_read, related_leave_request_id)
             SELECT u.id, 'substitute_request', :title, :message, 0, :leave_request_id
             FROM users u
             WHERE u.role = 'employee' AND u.employee_id = :employee_id"
        );
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO notifications (user_id, type, title, message, is_read, related_leave_request_id)
             SELECT u.id, 'substitute_request', :title, :message, 0, :leave_request_id
             FROM users u
             WHERE u.role = 'employee' AND u.employee_id = :employee_id
               AND NOT EXISTS (
                   SELECT 1 FROM notifications n
                   WHERE n.user_id = u.id
                     AND n.type = 'substitute_request'
                     AND n.related_leave_request_id = :leave_request_id2
               )"
        );
    }

    $createdCount = 0;

    foreach ($candidates as $candidate) {
        $params = [
            'title'             => $title,
            'message'           => $message,
            'leave_request_id'  => $leaveRequestId,
            'employee_id'       => $candidate['employee_id'],
        ];
        if (!$allowDuplicate) {
            $params['leave_request_id2'] = $leaveRequestId;
        }

        $stmt->execute($params);
        $createdCount += (int) $stmt->rowCount();

        // 通知そのものはユーザー操作で削除される可能性があるため、
        // 実際に通知レコードが存在する候補だけ、候補側にも「通知済み」として扱う日時を残す。
        $pdo->prepare(
            "UPDATE substitute_candidates sc
             JOIN users u
               ON u.role = 'employee'
              AND u.employee_id = sc.candidate_employee_id
             JOIN notifications n
               ON n.user_id = u.id
              AND n.type = 'substitute_request'
              AND n.related_leave_request_id = sc.leave_request_id
             SET sc.notified_at = COALESCE(sc.notified_at, NOW())
             WHERE sc.leave_request_id = :leave_request_id
               AND sc.candidate_employee_id = :employee_id"
        )->execute([
            'leave_request_id' => $leaveRequestId,
            'employee_id'      => $candidate['employee_id'],
        ]);
    }

    return $createdCount;
}

/**
 * スコア順に、未通知の代勤候補へ上限人数まで段階的に通知する
 *
 * 例：上限3人で、通知済みの未回答候補が2人いる場合は、次点候補1人だけへ通知する。
 */
function notifyNextSubstituteCandidates(PDO $pdo, int $leaveRequestId, ?int $limit = null): int
{
    $limit = $limit ?? getCandidateNotificationLimit();
    if ($limit <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM substitute_candidates
         WHERE leave_request_id = :leave_request_id
           AND status IN ('proposed', 'accepted')
           AND notified_at IS NOT NULL"
    );
    $stmt->execute(['leave_request_id' => $leaveRequestId]);
    $activeNotifiedCount = (int) $stmt->fetchColumn();
    $availableSlots = max(0, $limit - $activeNotifiedCount);

    if ($availableSlots === 0) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "SELECT candidate_employee_id AS employee_id
         FROM substitute_candidates
         WHERE leave_request_id = :leave_request_id
           AND status = 'proposed'
           AND notified_at IS NULL
         ORDER BY match_score DESC, id ASC
         LIMIT :limit"
    );
    $stmt->bindValue(':leave_request_id', $leaveRequestId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $availableSlots, PDO::PARAM_INT);
    $stmt->execute();

    return createCandidateNotifications($pdo, $leaveRequestId, $stmt->fetchAll());
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
 * 呼び出し元（pages/employee/shifts.php の休み申請登録処理）で、休み申請の登録と
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
        notifyNextSubstituteCandidates($pdo, $leaveRequestId);
        $newStatus = 'matching';
    } else {
        createNoCandidateNotification($pdo, $leaveRequestId);
        $newStatus = 'no_candidate';
    }

    $pdo->prepare('UPDATE leave_requests SET status = :status WHERE id = :id')
        ->execute(['status' => $newStatus, 'id' => $leaveRequestId]);

    return $newStatus;
}

// ------------------------------------------------------------
// 代勤候補の再抽出（Step1）
//
// 初回抽出（processSubstituteMatching / createSubstituteCandidates）とは別に、
// 以下のタイミングで「既存の抽出条件・スコア計算を再利用した再抽出」を行う。
//   - 代勤者の承認後キャンセルが店長承認されたとき（自動再抽出）
//   - 店長が no_candidate / replacement_pending の休み申請を手動再抽出するとき
//
// 再抽出では、必須条件に加えて「休み申請者本人・キャンセルした代勤者・
// 過去に declined と回答した従業員」を必ず除外する。既存候補レコードは、
// expired のものを proposed へ再活性化し、proposed/accepted のものはそのまま
// 維持する（同じ候補者への重複通知は作成しない）。
//
// 注意: これらの関数は内部でトランザクションを開始しない。呼び出し側が
// 必要に応じてトランザクションで囲むこと（キャンセル承認処理など、既に
// トランザクション内から呼ばれる場合があるため）。
// ------------------------------------------------------------

/** 同じ休み申請で過去に「代勤不可（declined）」と回答した従業員IDの配列を返す */
function getDeclinedEmployeeIdsForLeaveRequest(PDO $pdo, int $leaveRequestId): array
{
    $stmt = $pdo->prepare(
        "SELECT candidate_employee_id
         FROM substitute_candidates
         WHERE leave_request_id = :leave_request_id AND status = 'declined'"
    );
    $stmt->execute(['leave_request_id' => $leaveRequestId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * 同じ休み申請で、承認後代勤キャンセル申請（substitute_after_approval）が
 * 店長に承認された従業員IDの配列を返す
 *
 * 一度代勤を引き受けて承認後にキャンセルした従業員は、後続の再抽出で
 * 再び候補にしないために使う（現在キャンセルした本人だけでなく、過去の
 * キャンセル者も対象）。
 */
function getApprovedSubstituteCancellationEmployeeIdsForLeaveRequest(PDO $pdo, int $leaveRequestId): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT requested_by_employee_id
         FROM cancellation_requests
         WHERE leave_request_id = :leave_request_id
           AND request_type = 'substitute_after_approval'
           AND status = 'approved'"
    );
    $stmt->execute(['leave_request_id' => $leaveRequestId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * 指定した休み申請・従業員の既存の代勤候補レコード（最新1件）を返す
 *
 * @return array|null id, status, notified_at を含む連想配列。存在しない場合は null。
 */
function getExistingCandidateStatus(PDO $pdo, int $leaveRequestId, int $employeeId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, status, notified_at
         FROM substitute_candidates
         WHERE leave_request_id = :leave_request_id AND candidate_employee_id = :employee_id
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([
        'leave_request_id' => $leaveRequestId,
        'employee_id'      => $employeeId,
    ]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * 再抽出用の候補抽出（必須条件 ＋ 除外条件）
 *
 * findSubstituteCandidates() との違い:
 *   - 「既に候補登録済みか」では除外しない（expired を再提案できるようにするため）
 *   - 代わりに declined の従業員と、$excludeEmployeeIds の従業員を必ず除外する
 *
 * @return array employee_id, name, position, skill_level, hire_date を含む配列
 */
function findSubstituteCandidatesForRetry(PDO $pdo, int $leaveRequestId, array $excludeEmployeeIds = [], string $mode = 'normal'): array
{
    $target = getLeaveRequestShift($pdo, $leaveRequestId);
    if ($target === null) {
        return [];
    }

    $params = [
        'requester_employee_id'     => $target['requester_employee_id'],
        'shift_date'                => $target['shift_date'],
        'start_time'                => $target['start_time'],
        'end_time'                  => $target['end_time'],
        'shift_date2'               => $target['shift_date'],
        'start_time2'               => $target['start_time'],
        'end_time2'                 => $target['end_time'],
        'leave_request_id_declined' => $leaveRequestId,
    ];

    $excludeSql = '';
    $excludeEmployeeIds = array_values(array_unique(array_map('intval', $excludeEmployeeIds)));
    if (!empty($excludeEmployeeIds)) {
        $placeholders = [];
        foreach ($excludeEmployeeIds as $i => $eid) {
            $key = 'ex' . $i;
            $placeholders[] = ':' . $key;
            $params[$key]   = $eid;
        }
        $excludeSql = ' AND e.id NOT IN (' . implode(', ', $placeholders) . ')';
    }

    $timeCondition = ($mode === 'staffing_priority')
        ? 'AND a.start_time < :end_time AND a.end_time > :start_time'
        : 'AND a.start_time <= :start_time AND a.end_time >= :end_time';

    $sql =
        'SELECT DISTINCT e.id AS employee_id, e.name, e.position, e.skill_level, e.hire_date
         FROM employees e
         JOIN availability a ON a.employee_id = e.id
         WHERE e.is_active = 1
           AND e.id <> :requester_employee_id
           AND a.available_date = :shift_date
           ' . $timeCondition . '
           AND NOT EXISTS (
               SELECT 1 FROM shifts s2
               WHERE s2.employee_id = e.id
                 AND s2.shift_date = :shift_date2
                 AND s2.status <> "cancelled"
                 AND NOT (s2.end_time <= :start_time2 OR s2.start_time >= :end_time2)
           )
           AND NOT EXISTS (
               SELECT 1 FROM substitute_candidates scd
               WHERE scd.leave_request_id = :leave_request_id_declined
                 AND scd.candidate_employee_id = e.id
                 AND scd.status = "declined"
           )'
        . $excludeSql .
        ' ORDER BY e.id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * 代勤候補レコードを新規作成、または expired を proposed へ再活性化する
 *
 * @return string created / reactivated / proposed / accepted / declined
 *                 （proposed/accepted/declined は既存状態を維持した場合）
 */
function createOrReactivateCandidate(PDO $pdo, int $leaveRequestId, int $employeeId, int $score, string $reason): string
{
    $existing = getExistingCandidateStatus($pdo, $leaveRequestId, $employeeId);

    if ($existing === null) {
        $pdo->prepare(
            "INSERT INTO substitute_candidates
                (leave_request_id, candidate_employee_id, status, match_score, match_reason, matched_at)
             VALUES (:leave_request_id, :employee_id, 'proposed', :score, :reason, NOW())"
        )->execute([
            'leave_request_id' => $leaveRequestId,
            'employee_id'      => $employeeId,
            'score'            => $score,
            'reason'           => $reason,
        ]);
        return 'created';
    }

    if ($existing['status'] === 'expired') {
        $pdo->prepare(
            "UPDATE substitute_candidates
             SET status = 'proposed', responded_at = NULL,
                 match_score = :score, match_reason = :reason, matched_at = NOW()
             WHERE id = :id"
        )->execute([
            'score'  => $score,
            'reason' => $reason,
            'id'     => $existing['id'],
        ]);
        return 'reactivated';
    }

    // proposed / accepted / declined はそのまま維持する
    return (string) $existing['status'];
}

/**
 * 再抽出で候補者が見つからなかった場合に、店長へ「再抽出候補者なし」通知を作成する
 */
function createRematchNoCandidateNotification(PDO $pdo, int $leaveRequestId): void
{
    $target = getLeaveRequestShift($pdo, $leaveRequestId);
    if ($target === null) {
        return;
    }

    $message = sprintf(
        '%sの%s〜%sのシフトについて代勤候補を再抽出しましたが、条件に合う候補者が見つかりませんでした。手動で対応してください。',
        $target['shift_date'],
        substr($target['start_time'], 0, 5),
        substr($target['end_time'], 0, 5)
    );

    $stmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, type, title, message, is_read, related_leave_request_id)
         SELECT u.id, 'rematch_no_candidate', '代勤候補が見つかりませんでした', :message, 0, :leave_request_id
         FROM users u
         WHERE u.role = 'manager'"
    );
    $stmt->execute([
        'message'          => $message,
        'leave_request_id' => $leaveRequestId,
    ]);
}

/**
 * 代勤者キャンセルにより再調整になった際、過去に通知済み・未回答だった候補者へ再通知する。
 *
 * 通常の代勤依頼通知は重複作成しないが、承認後キャンセルで空きシフトが復活した場合は、
 * 既存通知だけでは従業員が気づけないため、このケースに限って同じ type の通知を再作成する。
 */
function createReopenedSubstituteRequestNotifications(PDO $pdo, int $leaveRequestId, array $candidateEmployeeIds): int
{
    $candidateEmployeeIds = array_values(array_unique(array_map('intval', $candidateEmployeeIds)));
    if (empty($candidateEmployeeIds)) {
        return 0;
    }

    $target = getLeaveRequestShift($pdo, $leaveRequestId);
    if ($target === null) {
        return 0;
    }

    $message = sprintf(
        '%sの%s〜%sのシフトについて、承認済みだった代勤者がキャンセルされたため、代勤依頼を再送しました。代勤可能か回答してください。',
        $target['shift_date'],
        substr($target['start_time'], 0, 5),
        substr($target['end_time'], 0, 5)
    );

    $candidates = array_map(
        static fn (int $employeeId): array => ['employee_id' => $employeeId],
        $candidateEmployeeIds
    );

    return createCandidateNotifications(
        $pdo,
        $leaveRequestId,
        $candidates,
        true,
        '代勤依頼が再度届いています',
        $message
    );
}

/**
 * 代勤候補を再抽出する（既存の抽出条件・スコア計算を再利用）
 *
 * - 休み申請の matching_mode を使ってスコア・抽出理由を計算する
 * - 必須条件 ＋ 除外条件（休み申請者本人・declined・$excludeEmployeeIds）で抽出する
 * - 既存 expired レコードは proposed へ再活性化、未登録は新規作成する
 * - proposed/accepted の既存候補はそのまま維持する
 * - 新たに proposed になった（created/reactivated）候補者へ代勤依頼通知を作成する
 * - 代勤者キャンセルによる再調整時は、過去に通知済み・未回答だった候補者にも再通知する
 * - 有効な候補が1人もいなければ、店長へ再抽出候補者なし通知を作成する
 *
 * この関数は内部でトランザクションを開始しない（呼び出し側で囲むこと）。
 *
 * @return array matched_count, notified_count, excluded_employee_ids, no_candidate
 */
function retrySubstituteMatching(PDO $pdo, int $leaveRequestId, array $excludeEmployeeIds = [], string $trigger = 'manual'): array
{
    $target = getLeaveRequestShift($pdo, $leaveRequestId);
    if ($target === null) {
        return [
            'matched_count'         => 0,
            'notified_count'        => 0,
            'excluded_employee_ids' => array_values(array_map('intval', $excludeEmployeeIds)),
            'no_candidate'          => true,
        ];
    }

    // 休み申請に記録された抽出モードを使う（未設定・不正な場合は normal）
    $modeStmt = $pdo->prepare('SELECT matching_mode FROM leave_requests WHERE id = :id');
    $modeStmt->execute(['id' => $leaveRequestId]);
    $mode = (string) $modeStmt->fetchColumn();
    if (!in_array($mode, getMatchingModes(), true)) {
        $mode = 'normal';
    }

    $declinedIds = getDeclinedEmployeeIdsForLeaveRequest($pdo, $leaveRequestId);
    // 同じ休み申請で承認後代勤キャンセルが店長に承認された従業員（過去のキャンセル者を含む）も除外する
    $approvedCancelIds = getApprovedSubstituteCancellationEmployeeIdsForLeaveRequest($pdo, $leaveRequestId);
    $excludeIds  = array_values(array_unique(array_merge(
        array_map('intval', $excludeEmployeeIds),
        $declinedIds,
        $approvedCancelIds
    )));

    $candidates = findSubstituteCandidatesForRetry($pdo, $leaveRequestId, $excludeIds, $mode);

    $matchedCount = 0;
    $reopenedNotificationEmployeeIds = [];

    foreach ($candidates as $candidate) {
        $employeeId   = (int) $candidate['employee_id'];
        $coverage = getBestAvailabilityCoverage(
            $pdo,
            $employeeId,
            $target,
            $mode === 'staffing_priority'
        );
        $scoreResult = calculateCandidateScore($candidate, $target, $coverage, $mode);
        $existingBefore = getExistingCandidateStatus($pdo, $leaveRequestId, $employeeId);

        $outcome = createOrReactivateCandidate(
            $pdo,
            $leaveRequestId,
            $employeeId,
            $scoreResult['score'],
            $scoreResult['reason']
        );

        if (
            $trigger === 'substitute_cancel'
            && $outcome === 'reactivated'
            && $existingBefore !== null
            && $existingBefore['notified_at'] !== null
        ) {
            $reopenedNotificationEmployeeIds[] = $employeeId;
        }

        if (in_array($outcome, ['created', 'reactivated', 'proposed', 'accepted'], true)) {
            // 既に依頼中・回答済みの候補者も「有効な候補がいる」状態として数える
            $matchedCount++;
        }
    }

    $notifiedCount = 0;
    if ($trigger === 'substitute_cancel') {
        $notifiedCount += createReopenedSubstituteRequestNotifications(
            $pdo,
            $leaveRequestId,
            $reopenedNotificationEmployeeIds
        );
    }
    $notifiedCount += notifyNextSubstituteCandidates($pdo, $leaveRequestId);

    $noCandidate = ($matchedCount === 0);
    if ($noCandidate) {
        createRematchNoCandidateNotification($pdo, $leaveRequestId);
    }

    return [
        'matched_count'         => $matchedCount,
        'notified_count'        => $notifiedCount,
        'excluded_employee_ids' => $excludeIds,
        'no_candidate'          => $noCandidate,
    ];
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
