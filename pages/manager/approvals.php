<?php
/**
 * 承認画面（店長用）
 *
 * - 「候補者回答待ち」「候補者なし」の休み申請を一覧表示し、承認・却下を行う
 * - 承認時: 「代勤可能」と回答した候補者の中から1名を選び、代勤を確定する
 *     - leave_requests.status を 'approved' に更新する
 *     - 対象シフト（shifts）の担当者を選んだ候補者に変更し、status を 'substituted' に更新する
 *     - 未回答の候補者（proposed）は 'expired' にする
 *     - 選ばれなかった accepted 候補者は、承認後キャンセル時のバックアップ候補として残す
 *     - approvals テーブルに承認結果を記録する
 *     - 休み申請者・代勤対応者へ承認結果通知を作成する
 * - 却下時: 休み申請を却下し、対象シフトは元の担当者のまま「予定」に戻す
 *     - leave_requests.status を 'rejected' に更新する
 *     - 対象シフト（shifts）の status を 'scheduled' に戻す
 *     - 候補者（proposed/accepted）は 'expired' にする
 *     - approvals テーブルに却下結果を記録する
 *     - 休み申請者へ却下通知を作成する
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/services/substitute_matcher.php';
require_once __DIR__ . '/../../app/includes/status_labels.php';
require_once __DIR__ . '/../../app/includes/pagination.php';
require_once __DIR__ . '/../../app/includes/request_view_helpers.php';
require_once __DIR__ . '/../../app/includes/schema_helpers.php';

$pageTitle = '承認';
$basePath  = '../../public/';

$user      = currentUser();
$managerId = (int) $user['id'];

$errorMessage   = '';
$successMessage = '';
$perPage = 10;

ensureRequestViewStatesTable($pdo);
ensureManualSubstituteShiftSchema($pdo);

function cleanupOldManagerLeaveRequests(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare(
        "SELECT lr.id
         FROM leave_requests lr
         JOIN shifts s ON s.id = lr.shift_id
         LEFT JOIN request_view_states rvs
           ON rvs.user_id = :user_id_state
          AND rvs.item_type = 'manager_leave'
          AND rvs.item_id = lr.id
         WHERE lr.status IN ('approved', 'rejected', 'cancelled', 'cancelled_after_approval')
           AND s.shift_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
           AND COALESCE(rvs.is_favorite, 0) = 0"
    );
    $stmt->execute(['user_id_state' => $userId]);

    foreach ($stmt->fetchAll() as $row) {
        hideRequestViewItem($pdo, $userId, 'manager_leave', (int) $row['id']);
    }
}

cleanupOldManagerLeaveRequests($pdo, $managerId);

function managerApprovalPageUrl(int $processedPage = 1): string
{
    $params = [];
    if ($processedPage > 1) {
        $params['processed_page'] = $processedPage;
    }

    return 'approvals.php' . ($params !== [] ? '?' . http_build_query($params) : '');
}

$processedPage = getPageNumber('processed_page');
$redirectUrl = managerApprovalPageUrl($processedPage);

// ------------------------------------------------------------
// POST処理（承認・却下）
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action         = $_POST['action'] ?? '';
    $leaveRequestId = (int) ($_POST['leave_request_id'] ?? 0);

    if ($action === 'toggle_favorite' && $leaveRequestId > 0) {
        toggleRequestViewFavorite($pdo, $managerId, 'manager_leave', $leaveRequestId);
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'delete_processed' && $leaveRequestId > 0) {
        hideRequestViewItem($pdo, $managerId, 'manager_leave', $leaveRequestId);
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'hide_all_processed') {
        $stmt = $pdo->prepare(
            "INSERT INTO request_view_states
                (user_id, item_type, item_id, is_hidden, is_favorite, hidden_at)
             SELECT :insert_user_id, 'manager_leave', lr.id, 1, 0, NOW()
             FROM leave_requests lr
             LEFT JOIN request_view_states rvs
               ON rvs.user_id = :join_user_id
              AND rvs.item_type = 'manager_leave'
              AND rvs.item_id = lr.id
             WHERE lr.status IN ('approved', 'rejected', 'cancelled', 'cancelled_after_approval')
               AND COALESCE(rvs.is_hidden, 0) = 0
               AND COALESCE(rvs.is_favorite, 0) = 0
             ON DUPLICATE KEY UPDATE
                is_hidden = IF(is_favorite = 1, is_hidden, 1),
                hidden_at = IF(is_favorite = 1, hidden_at, NOW())"
        );
        $stmt->execute([
            'insert_user_id' => $managerId,
            'join_user_id'   => $managerId,
        ]);
        header('Location: approvals.php?msg=bulk_hidden');
        exit;
    }

    if ($action === 'approve') {
        $candidateId = (int) ($_POST['candidate_id'] ?? 0);

        $pdo->beginTransaction();
        try {
            // 未処理（matching / no_candidate / replacement_pending）の休み申請であることを確認する
            // replacement_pending は代勤者キャンセル後の再調整中で、新しい代勤者を承認できる
            $stmt = $pdo->prepare(
                "SELECT lr.id, lr.shift_id
                 FROM leave_requests lr
                 WHERE lr.id = :id AND lr.status IN ('matching', 'no_candidate', 'replacement_pending')
                 FOR UPDATE"
            );
            $stmt->execute(['id' => $leaveRequestId]);
            $leaveRequest = $stmt->fetch();

            if ($leaveRequest === false) {
                $pdo->rollBack();
                $errorMessage = '指定された休み申請が見つからないか、既に処理済みです。';
            } else {
                // 選択された候補者が「代勤可能」と回答済みであることを確認する
                $stmt = $pdo->prepare(
                    "SELECT sc.id, sc.candidate_employee_id
                     FROM substitute_candidates sc
                     WHERE sc.id = :candidate_id AND sc.leave_request_id = :leave_request_id AND sc.status = 'accepted'
                     FOR UPDATE"
                );
                $stmt->execute(['candidate_id' => $candidateId, 'leave_request_id' => $leaveRequestId]);
                $candidate = $stmt->fetch();

                if ($candidate === false) {
                    $pdo->rollBack();
                    $errorMessage = '選択された候補者は「代勤可能」と回答していないか、存在しません。';
                } else {
                    $substituteEmployeeId = (int) $candidate['candidate_employee_id'];

                    $pdo->prepare(
                        "INSERT INTO approvals (leave_request_id, substitute_candidate_id, manager_id, status, approved_at)
                         VALUES (:leave_request_id, :candidate_id, :manager_id, 'approved', NOW())"
                    )->execute([
                        'leave_request_id' => $leaveRequestId,
                        'candidate_id'     => $candidateId,
                        'manager_id'       => $managerId,
                    ]);

                    $pdo->prepare("UPDATE leave_requests SET status = 'approved' WHERE id = :id")
                        ->execute(['id' => $leaveRequestId]);

                    $pdo->prepare("UPDATE shifts SET employee_id = :employee_id, status = 'substituted' WHERE id = :id")
                        ->execute(['employee_id' => $substituteEmployeeId, 'id' => $leaveRequest['shift_id']]);

                    $pdo->prepare(
                        "UPDATE shifts
                         SET status = 'cancelled'
                         WHERE related_leave_request_id = :leave_request_id
                           AND status IN ('substituted', 'replacement_pending')"
                    )->execute(['leave_request_id' => $leaveRequestId]);

                    // 未回答の候補者だけ期限切れにする。
                    // 「代勤可能」と回答済みの候補者は、承認後キャンセル時に再選択できるバックアップとして残す。
                    $pdo->prepare(
                        "UPDATE substitute_candidates
                         SET status = 'expired'
                         WHERE leave_request_id = :leave_request_id
                           AND id <> :candidate_id
                           AND status = 'proposed'"
                    )->execute(['leave_request_id' => $leaveRequestId, 'candidate_id' => $candidateId]);

                    createApprovalResultNotifications($pdo, $leaveRequestId, 'approved', $substituteEmployeeId);

                    $pdo->commit();

                    header('Location: approvals.php?msg=approved#lr-' . $leaveRequestId);
                    exit;
                }
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    } elseif ($action === 'manual_approve') {
        header('Location: shifts.php?manual_substitute_for=' . $leaveRequestId);
        exit;
    } elseif ($action === 'reject') {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT lr.id, lr.shift_id
                 FROM leave_requests lr
                 WHERE lr.id = :id AND lr.status IN ('matching', 'no_candidate')
                 FOR UPDATE"
            );
            $stmt->execute(['id' => $leaveRequestId]);
            $leaveRequest = $stmt->fetch();

            if ($leaveRequest === false) {
                $pdo->rollBack();
                $errorMessage = '指定された休み申請が見つからないか、既に処理済みです。';
            } else {
                $pdo->prepare(
                    "INSERT INTO approvals (leave_request_id, substitute_candidate_id, manager_id, status, approved_at)
                     VALUES (:leave_request_id, NULL, :manager_id, 'rejected', NOW())"
                )->execute([
                    'leave_request_id' => $leaveRequestId,
                    'manager_id'       => $managerId,
                ]);

                $pdo->prepare("UPDATE leave_requests SET status = 'rejected' WHERE id = :id")
                    ->execute(['id' => $leaveRequestId]);

                // 却下時はシフトを元の担当者のまま「予定」に戻す
                $pdo->prepare("UPDATE shifts SET status = 'scheduled' WHERE id = :id")
                    ->execute(['id' => $leaveRequest['shift_id']]);

                $pdo->prepare(
                    "UPDATE substitute_candidates
                     SET status = 'expired'
                     WHERE leave_request_id = :leave_request_id
                       AND status IN ('proposed', 'accepted')"
                )->execute(['leave_request_id' => $leaveRequestId]);

                createApprovalResultNotifications($pdo, $leaveRequestId, 'rejected');

                $pdo->commit();

                header('Location: approvals.php?msg=rejected#lr-' . $leaveRequestId);
                exit;
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

// ------------------------------------------------------------
// 完了メッセージ（リダイレクト後）
// ------------------------------------------------------------
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'approved':
            $successMessage = '休み申請を承認し、代勤を確定しました。';
            break;
        case 'rejected':
            $successMessage = '休み申請を却下しました。';
            break;
        case 'rematch_found':
            $successMessage = '代勤候補を再抽出しました。候補者が見つかり、代勤依頼を作成しました。';
            break;
        case 'rematch_none':
            $successMessage = '代勤候補を再抽出しましたが、条件に合う候補者が見つかりませんでした。';
            break;
        case 'rematch_invalid':
            $errorMessage = 'この休み申請は再抽出の対象ではありません（状態が変わった可能性があります）。';
            break;
        case 'rematch_error':
            $errorMessage = '再抽出の対象を確認できませんでした。';
            break;
        case 'bulk_hidden':
            $successMessage = 'お気に入り以外の処理済み休み申請を一括非表示にしました。';
            break;
    }
}

// ------------------------------------------------------------
// 未処理の休み申請一覧（候補者回答待ち・候補者なし）
// ------------------------------------------------------------
$pendingLeaveRequests = $pdo->query(
    "SELECT lr.id AS leave_request_id, lr.reason, lr.status AS leave_status, lr.matching_mode, lr.created_at,
            s.shift_date, s.start_time, s.end_time, s.position,
            req.name AS requester_name
     FROM leave_requests lr
     JOIN shifts s ON s.id = lr.shift_id
     JOIN employees req ON req.id = lr.employee_id
     WHERE lr.status IN ('matching', 'no_candidate', 'replacement_pending')
     ORDER BY lr.created_at"
)->fetchAll();

$candidateStmt = $pdo->prepare(
    "SELECT sc.id, sc.status, sc.match_score, sc.match_reason, sc.notified_at, sc.responded_at,
            e.name AS candidate_name, e.skill_level, e.hire_date
     FROM substitute_candidates sc
     JOIN employees e ON e.id = sc.candidate_employee_id
     WHERE sc.leave_request_id = :leave_request_id
     ORDER BY
        CASE
            WHEN sc.status = 'accepted' THEN 1
            WHEN sc.status = 'declined' THEN 2
            WHEN sc.status = 'proposed' THEN 3
            ELSE 4
        END,
        sc.match_score DESC,
        sc.id"
);

foreach ($pendingLeaveRequests as &$lr) {
    $candidateStmt->execute(['leave_request_id' => $lr['leave_request_id']]);
    $lr['candidates'] = $candidateStmt->fetchAll();
}
unset($lr);

// ------------------------------------------------------------
// 処理済みの休み申請一覧（承認済み・却下・各キャンセル済み）
// ------------------------------------------------------------
$processedCountStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM leave_requests lr
     LEFT JOIN request_view_states rvs
       ON rvs.user_id = :user_id
      AND rvs.item_type = 'manager_leave'
      AND rvs.item_id = lr.id
     WHERE lr.status IN ('approved', 'rejected', 'cancelled', 'cancelled_after_approval')
       AND COALESCE(rvs.is_hidden, 0) = 0"
);
$processedCountStmt->execute(['user_id' => $managerId]);
$processedTotalCount = (int) $processedCountStmt->fetchColumn();
$processedTotalPages = getTotalPages($processedTotalCount, $perPage);
if ($processedPage > $processedTotalPages) {
    $processedPage = $processedTotalPages;
}
$processedOffset = ($processedPage - 1) * $perPage;

$processedStmt = $pdo->prepare(
    "SELECT lr.id AS leave_request_id, lr.reason, lr.status AS leave_status,
            s.shift_date, s.start_time, s.end_time, s.position,
            req.name AS requester_name,
            a.status AS approval_status,
            CASE
                WHEN lr.status = 'cancelled_after_approval' THEN (
                    SELECT MAX(cr.decided_at)
                    FROM cancellation_requests cr
                    WHERE cr.leave_request_id = lr.id
                      AND cr.request_type = 'requester_after_approval'
                      AND cr.status = 'approved'
                )
                ELSE COALESCE(a.approved_at, lr.updated_at)
            END AS processed_at,
            sub.name AS substitute_name
     FROM leave_requests lr
     JOIN shifts s ON s.id = lr.shift_id
     JOIN employees req ON req.id = lr.employee_id
     LEFT JOIN request_view_states rvs
       ON rvs.user_id = :user_id
      AND rvs.item_type = 'manager_leave'
      AND rvs.item_id = lr.id
     LEFT JOIN approvals a ON a.id = (
         SELECT MAX(a2.id) FROM approvals a2 WHERE a2.leave_request_id = lr.id
     )
     LEFT JOIN substitute_candidates sc ON sc.id = a.substitute_candidate_id
     LEFT JOIN employees sub ON sub.id = sc.candidate_employee_id
     WHERE lr.status IN ('approved', 'rejected', 'cancelled', 'cancelled_after_approval')
       AND COALESCE(rvs.is_hidden, 0) = 0
     ORDER BY processed_at DESC, lr.id DESC
     LIMIT :limit OFFSET :offset"
);
$processedStmt->bindValue(':user_id', $managerId, PDO::PARAM_INT);
$processedStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$processedStmt->bindValue(':offset', $processedOffset, PDO::PARAM_INT);
$processedStmt->execute();
$processedLeaveRequests = $processedStmt->fetchAll();

$requestViewStates = fetchRequestViewStates($pdo, $managerId);
foreach ($processedLeaveRequests as &$processedLeaveRequest) {
    $stateKey = requestViewStateKey('manager_leave', (int) $processedLeaveRequest['leave_request_id']);
    $state = $requestViewStates[$stateKey] ?? ['is_hidden' => 0, 'is_favorite' => 0];
    $processedLeaveRequest['is_favorite'] = (int) $state['is_favorite'];
}
unset($processedLeaveRequest);

// 通知確認画面から来た場合は「戻る」先を通知確認画面にする（それ以外はメニュー）
if (($_GET['from'] ?? '') === 'notifications') {
    $backUrl = 'notifications.php';
}

function managerApprovalShiftLabel(array $row): string
{
    $label = $row['shift_date'] . ' ' . substr($row['start_time'], 0, 5) . '-' . substr($row['end_time'], 0, 5);
    if (!empty($row['position'])) {
        $label .= '（' . $row['position'] . '）';
    }
    return $label;
}

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    代勤候補からの回答内容を確認し、休み申請の承認・却下を行います。
</p>

<div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
<div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>

<div class="section">
    <h2>未処理の休み申請</h2>
    <?php if (empty($pendingLeaveRequests)): ?>
        <p class="page-description">未処理の休み申請はありません。</p>
    <?php else: ?>
        <div class="manager-card-list">
        <?php foreach ($pendingLeaveRequests as $lr): ?>
        <?php
            $candidateGroups = [
                'accepted' => [
                    'label'       => '代勤可能',
                    'badge_class' => 'badge-success',
                    'candidates'  => [],
                ],
                'declined' => [
                    'label'       => '代勤不可',
                    'badge_class' => 'badge-danger',
                    'candidates'  => [],
                ],
                'unanswered' => [
                    'label'       => '未回答',
                    'badge_class' => 'badge-active',
                    'candidates'  => [],
                ],
                'not_notified' => [
                    'label'       => '未通知',
                    'badge_class' => 'badge-inactive',
                    'candidates'  => [],
                ],
            ];
            foreach ($lr['candidates'] as $candidateForGroup) {
                if ($candidateForGroup['status'] === 'accepted') {
                    $candidateGroups['accepted']['candidates'][] = $candidateForGroup;
                } elseif ($candidateForGroup['status'] === 'declined') {
                    $candidateGroups['declined']['candidates'][] = $candidateForGroup;
                } elseif ($candidateForGroup['status'] === 'proposed') {
                    if (!empty($candidateForGroup['notified_at'])) {
                        $candidateGroups['unanswered']['candidates'][] = $candidateForGroup;
                    } else {
                        $candidateGroups['not_notified']['candidates'][] = $candidateForGroup;
                    }
                }
            }
        ?>
        <article class="manager-work-card approval-card" id="lr-<?php echo (int) $lr['leave_request_id']; ?>">
            <div class="manager-work-card-header approval-card-header">
                <div>
                    <h3><?php echo htmlspecialchars($lr['requester_name']); ?>さんの休み申請</h3>
                    <p class="approval-shift-summary"><?php echo htmlspecialchars(managerApprovalShiftLabel($lr)); ?></p>
                </div>
                <?php echo renderStatusBadge(leaveRequestStatusLabel($lr['leave_status']), leaveRequestStatusBadgeClass($lr['leave_status'])); ?>
            </div>

            <div class="manager-detail-grid approval-summary-grid">
                <div><span>申請理由</span><strong><?php echo htmlspecialchars($lr['reason'] ?: '-'); ?></strong></div>
                <div><span>抽出モード</span><strong><?php echo htmlspecialchars(getMatchingModeLabel($lr['matching_mode'] ?? 'normal')); ?></strong></div>
                <div><span>申請日時</span><strong><?php echo htmlspecialchars($lr['created_at']); ?></strong></div>
            </div>

            <?php if (empty($lr['candidates'])): ?>
                <?php if ($lr['leave_status'] === 'replacement_pending'): ?>
                <p class="manager-card-note">代勤者再調整中です。代勤候補が見つかりませんでした。下の「代勤候補を再抽出」で再度候補を探せます。</p>
                <?php else: ?>
                <p class="manager-card-note">代勤候補が見つかりませんでした。下の「代勤候補を再抽出」で再度候補を探すか、休み申請を却下してください。</p>
                <?php endif; ?>
            <?php else: ?>
                <div class="approval-candidate-groups">
                <?php foreach ($candidateGroups as $groupKey => $group): ?>
                    <details class="approval-candidate-group approval-candidate-group-<?php echo htmlspecialchars($groupKey); ?>">
                        <summary class="approval-candidate-group-summary">
                            <span class="approval-candidate-group-title">
                                <?php echo renderStatusBadge($group['label'], $group['badge_class']); ?>
                            </span>
                            <span class="approval-candidate-group-count"><?php echo count($group['candidates']); ?>件</span>
                        </summary>
                        <?php if (empty($group['candidates'])): ?>
                            <p class="approval-candidate-empty">該当する候補者はいません。</p>
                        <?php else: ?>
                            <div class="candidate-card-list approval-candidate-list">
                            <?php foreach ($group['candidates'] as $candidate): ?>
                                <?php
                                    $isAcceptedGroup = $groupKey === 'accepted';
                                    $candidateBadgeLabel = $group['label'];
                                    $candidateBadgeClass = $group['badge_class'];
                                    if ($groupKey === 'declined') {
                                        $candidateBadgeLabel = candidateStatusLabel($candidate['status']);
                                        $candidateBadgeClass = candidateStatusBadgeClass($candidate['status']);
                                    }
                                    $confirmMessage = $candidate['candidate_name'] . 'さんを代勤者として承認します。よろしいですか？';
                                ?>
                                <?php if ($isAcceptedGroup): ?>
                                <form method="post" action="approvals.php" class="approval-candidate-card-form">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="leave_request_id" value="<?php echo (int) $lr['leave_request_id']; ?>">
                                    <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate['id']; ?>">
                                    <button
                                        type="submit"
                                        class="candidate-card approval-candidate-card is-selectable"
                                        onclick="return confirm(<?php echo htmlspecialchars(json_encode($confirmMessage, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>);"
                                    >
                                <?php else: ?>
                                    <div class="candidate-card approval-candidate-card is-not-selectable is-muted-candidate" aria-disabled="true">
                                <?php endif; ?>
                                        <div class="candidate-card-main approval-candidate-main">
                                            <div>
                                                <div class="approval-candidate-heading">
                                                    <strong><?php echo htmlspecialchars($candidate['candidate_name']); ?></strong>
                                                    <?php echo renderStatusBadge($candidateBadgeLabel, $candidateBadgeClass); ?>
                                                </div>
                                                <p class="approval-candidate-reason"><?php echo htmlspecialchars($candidate['match_reason'] ?? ''); ?></p>
                                            </div>
                                        </div>
                                        <div class="candidate-card-meta">
                                            <span>スコア：<?php echo $candidate['match_score'] !== null ? htmlspecialchars((string) $candidate['match_score']) . '点' : '-'; ?></span>
                                            <span>スキル：<?php echo htmlspecialchars(skillLevelLabel($candidate['skill_level'] ?? null)); ?></span>
                                            <span>勤続：<?php echo htmlspecialchars(scoreTenure($candidate['hire_date'] ?? null, date('Y-m-d'))['label']); ?></span>
                                        </div>
                                <?php if ($isAcceptedGroup): ?>
                                    </button>
                                </form>
                                <?php else: ?>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </details>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="manager-card-actions">
            <?php if (in_array($lr['leave_status'], ['no_candidate', 'replacement_pending'], true)): ?>
            <form method="post" action="rematch_leave_request.php">
                <input type="hidden" name="action" value="rematch">
                <input type="hidden" name="leave_request_id" value="<?php echo (int) $lr['leave_request_id']; ?>">
                <button type="submit" class="btn">代勤候補を再抽出</button>
            </form>
            <?php endif; ?>

            <form method="post" action="approvals.php">
                <input type="hidden" name="action" value="manual_approve">
                <input type="hidden" name="leave_request_id" value="<?php echo (int) $lr['leave_request_id']; ?>">
                <button type="submit" class="btn">手動対応で代勤登録</button>
            </form>

            <?php if (in_array($lr['leave_status'], ['matching', 'no_candidate'], true)): ?>
            <form method="post" action="approvals.php">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="leave_request_id" value="<?php echo (int) $lr['leave_request_id']; ?>">
                <button type="submit" class="btn btn-secondary">この休み申請を却下</button>
            </form>
            <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="section">
    <h2>処理済みの休み申請</h2>
    <p class="page-description notification-auto-delete-note">
        処理済みの休み申請は、対象シフト日から90日後に自動で非表示になります。保存したい申請は、お気に入り登録してください。
        ここでの削除は画面上の非表示のみで、申請履歴そのものは削除されません。
    </p>
    <?php if (!empty($processedLeaveRequests)): ?>
    <div class="notification-toolbar notification-toolbar-right">
        <details class="notification-menu">
            <summary class="notification-menu-button" aria-label="処理済み休み申請の操作メニュー">…</summary>
            <div class="notification-menu-panel">
                <form method="post" action="<?php echo htmlspecialchars($redirectUrl); ?>" onsubmit="return confirm('お気に入り以外の処理済み休み申請をすべて非表示にします。よろしいですか？\n※申請履歴そのものは削除されません。');">
                    <input type="hidden" name="action" value="hide_all_processed">
                    <button type="submit" class="btn btn-secondary">一括非表示</button>
                </form>
            </div>
        </details>
    </div>
    <?php endif; ?>
    <?php if (empty($processedLeaveRequests)): ?>
        <p class="page-description">処理済みの休み申請はありません。</p>
    <?php else: ?>
        <div class="notification-list">
            <div class="notification-table-header">
                <span>休み申請</span>
                <span>結果</span>
            </div>
            <?php foreach ($processedLeaveRequests as $lr): ?>
                <?php $detailId = 'processed-leave-' . (int) $lr['leave_request_id']; ?>
                <div class="notification-card" id="lr-<?php echo (int) $lr['leave_request_id']; ?>">
                    <div class="notification-summary-row">
                        <button
                            type="button"
                            class="notification-summary-button"
                            data-manager-detail="<?php echo htmlspecialchars($detailId); ?>"
                            data-manager-title="処理済みの休み申請"
                        >
                            <span class="notification-title"><?php echo htmlspecialchars($lr['requester_name']); ?>さんの休み申請</span>
                            <span class="notification-meta">
                                <?php echo htmlspecialchars(managerApprovalShiftLabel($lr)); ?>
                                ・<?php echo htmlspecialchars($lr['processed_at'] ?? ''); ?>
                            </span>
                            <span class="badge notification-status-badge <?php echo htmlspecialchars(leaveRequestStatusBadgeClass($lr['leave_status'])); ?>">
                                <?php echo htmlspecialchars(leaveRequestStatusLabel($lr['leave_status'])); ?>
                            </span>
                        </button>
                        <div class="notification-actions">
                            <form method="post" action="<?php echo htmlspecialchars($redirectUrl); ?>">
                                <input type="hidden" name="action" value="toggle_favorite">
                                <input type="hidden" name="leave_request_id" value="<?php echo (int) $lr['leave_request_id']; ?>">
                                <button
                                    type="submit"
                                    class="btn-icon-favorite <?php echo $lr['is_favorite'] ? 'is-favorite' : ''; ?>"
                                    aria-label="<?php echo $lr['is_favorite'] ? 'お気に入りを解除する' : 'お気に入りに登録する'; ?>"
                                >
                                    <?php echo $lr['is_favorite'] ? '★' : '☆'; ?>
                                </button>
                            </form>
                            <form method="post" action="<?php echo htmlspecialchars($redirectUrl); ?>" onsubmit="return confirm('この処理済み申請を画面上で非表示にします。よろしいですか？\n※申請履歴そのものは削除されません。');">
                                <input type="hidden" name="action" value="delete_processed">
                                <input type="hidden" name="leave_request_id" value="<?php echo (int) $lr['leave_request_id']; ?>">
                                <button type="submit" class="btn-icon-danger" aria-label="処理済み申請を非表示にする">🗑</button>
                            </form>
                        </div>
                    </div>
                    <div id="<?php echo htmlspecialchars($detailId); ?>" class="notification-detail-source" hidden>
                        <table>
                            <tbody>
                                <tr><th>処理日時</th><td><?php echo htmlspecialchars($lr['processed_at'] ?? '-'); ?></td></tr>
                                <tr><th>休み申請者</th><td><?php echo htmlspecialchars($lr['requester_name']); ?></td></tr>
                                <tr><th>対象シフト</th><td><?php echo htmlspecialchars(managerApprovalShiftLabel($lr)); ?></td></tr>
                                <tr><th>結果</th><td><?php echo renderStatusBadge(leaveRequestStatusLabel($lr['leave_status']), leaveRequestStatusBadgeClass($lr['leave_status'])); ?></td></tr>
                                <tr><th>代勤対応者</th><td><?php echo htmlspecialchars($lr['substitute_name'] ?? '-'); ?></td></tr>
                                <tr><th>申請理由</th><td><?php echo nl2br(htmlspecialchars($lr['reason'] ?? '-')); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php renderPagination('processed_page', $processedPage, $processedTotalPages); ?>
</div>

<div class="calendar-modal" data-manager-modal hidden>
    <div class="calendar-modal-backdrop" data-manager-modal-close></div>
    <div class="calendar-modal-panel" role="dialog" aria-modal="true" aria-labelledby="manager-modal-title">
        <button type="button" class="calendar-modal-close" data-manager-modal-close aria-label="閉じる">×</button>
        <h3 id="manager-modal-title" data-manager-modal-title>詳細</h3>
        <div class="calendar-modal-body" data-manager-modal-body></div>
    </div>
</div>

<script>
document.addEventListener('click', function (event) {
    const modal = document.querySelector('[data-manager-modal]');
    if (!modal) {
        return;
    }

    if (event.target.closest('[data-manager-modal-close]')) {
        modal.hidden = true;
        document.body.classList.remove('calendar-modal-open');
        return;
    }

    const button = event.target.closest('[data-manager-detail]');
    if (!button) {
        return;
    }

    const detail = document.getElementById(button.dataset.managerDetail);
    modal.querySelector('[data-manager-modal-title]').textContent = button.dataset.managerTitle || '詳細';
    modal.querySelector('[data-manager-modal-body]').innerHTML = detail ? detail.innerHTML : '';
    modal.hidden = false;
    document.body.classList.add('calendar-modal-open');
});
</script>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
