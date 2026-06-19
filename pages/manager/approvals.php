<?php
/**
 * 承認画面（店長用）
 *
 * - 「候補者回答待ち」「候補者なし」の休み申請を一覧表示し、承認・却下を行う
 * - 承認時: 「代勤可能」と回答した候補者の中から1名を選び、代勤を確定する
 *     - leave_requests.status を 'approved' に更新する
 *     - 対象シフト（shifts）の担当者を選んだ候補者に変更し、status を 'substituted' に更新する
 *     - 選ばれなかった他の候補者（proposed/accepted）は 'expired' にする
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

$pageTitle = '承認';
$basePath  = '../../public/';

$user      = currentUser();
$managerId = (int) $user['id'];

$errorMessage   = '';
$successMessage = '';

// ------------------------------------------------------------
// POST処理（承認・却下）
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action         = $_POST['action'] ?? '';
    $leaveRequestId = (int) ($_POST['leave_request_id'] ?? 0);

    if ($action === 'approve') {
        $candidateId = (int) ($_POST['candidate_id'] ?? 0);

        $pdo->beginTransaction();
        try {
            // 未処理（matching または no_candidate）の休み申請であることを確認する
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

                    // 選ばれなかった他の候補者は期限切れにする（declined はそのまま残す）
                    $pdo->prepare(
                        "UPDATE substitute_candidates
                         SET status = 'expired'
                         WHERE leave_request_id = :leave_request_id
                           AND id <> :candidate_id
                           AND status IN ('proposed', 'accepted')"
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
     WHERE lr.status IN ('matching', 'no_candidate')
     ORDER BY lr.created_at"
)->fetchAll();

$candidateStmt = $pdo->prepare(
    "SELECT sc.id, sc.status, sc.match_score, sc.match_reason, sc.responded_at,
            e.name AS candidate_name, e.skill_level, e.hire_date
     FROM substitute_candidates sc
     JOIN employees e ON e.id = sc.candidate_employee_id
     WHERE sc.leave_request_id = :leave_request_id
     ORDER BY sc.match_score DESC, sc.id"
);

foreach ($pendingLeaveRequests as &$lr) {
    $candidateStmt->execute(['leave_request_id' => $lr['leave_request_id']]);
    $lr['candidates'] = $candidateStmt->fetchAll();
}
unset($lr);

// ------------------------------------------------------------
// 処理済みの休み申請一覧（承認済み・却下・各キャンセル済み）
// ------------------------------------------------------------
$processedLeaveRequests = $pdo->query(
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
     LEFT JOIN approvals a ON a.leave_request_id = lr.id
     LEFT JOIN substitute_candidates sc ON sc.id = a.substitute_candidate_id
     LEFT JOIN employees sub ON sub.id = sc.candidate_employee_id
     WHERE lr.status IN ('approved', 'rejected', 'cancelled', 'cancelled_after_approval')
     ORDER BY processed_at DESC, lr.id DESC"
)->fetchAll();

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
        <?php foreach ($pendingLeaveRequests as $lr): ?>
        <div class="section" id="lr-<?php echo (int) $lr['leave_request_id']; ?>">
            <table>
                <tbody>
                    <tr>
                        <th>休み申請者</th>
                        <td><?php echo htmlspecialchars($lr['requester_name']); ?></td>
                    </tr>
                    <tr>
                        <th>勤務日</th>
                        <td><?php echo htmlspecialchars($lr['shift_date']); ?></td>
                    </tr>
                    <tr>
                        <th>開始時刻</th>
                        <td><?php echo htmlspecialchars(substr($lr['start_time'], 0, 5)); ?></td>
                    </tr>
                    <tr>
                        <th>終了時刻</th>
                        <td><?php echo htmlspecialchars(substr($lr['end_time'], 0, 5)); ?></td>
                    </tr>
                    <tr>
                        <th>担当業務・ポジション</th>
                        <td><?php echo htmlspecialchars($lr['position'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <th>申請理由</th>
                        <td><?php echo htmlspecialchars($lr['reason'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <th>抽出モード</th>
                        <td><?php echo htmlspecialchars(getMatchingModeLabel($lr['matching_mode'] ?? 'normal')); ?></td>
                    </tr>
                    <tr>
                        <th>状態</th>
                        <td><?php echo renderStatusBadge(leaveRequestStatusLabel($lr['leave_status']), leaveRequestStatusBadgeClass($lr['leave_status'])); ?></td>
                    </tr>
                </tbody>
            </table>

            <?php if (empty($lr['candidates'])): ?>
                <p class="page-description">代勤候補が見つかりませんでした。手動で調整するか、休み申請を却下してください。</p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>代勤候補</th>
                        <th>回答状況</th>
                        <th>スコア</th>
                        <th>抽出理由</th>
                        <th>スキルレベル</th>
                        <th>勤続</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lr['candidates'] as $candidate): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($candidate['candidate_name']); ?></td>
                        <td>
                            <?php echo renderStatusBadge(candidateStatusLabel($candidate['status']), candidateStatusBadgeClass($candidate['status'])); ?>
                        </td>
                        <td><?php echo $candidate['match_score'] !== null ? htmlspecialchars((string) $candidate['match_score']) . '点' : '-'; ?></td>
                        <td><?php echo htmlspecialchars($candidate['match_reason'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars(skillLevelLabel($candidate['skill_level'] ?? null)); ?></td>
                        <td><?php echo htmlspecialchars(scoreTenure($candidate['hire_date'] ?? null, date('Y-m-d'))['label']); ?></td>
                        <td>
                            <?php if ($candidate['status'] === 'accepted'): ?>
                            <form method="post" action="approvals.php" style="display:inline;">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="leave_request_id" value="<?php echo (int) $lr['leave_request_id']; ?>">
                                <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate['id']; ?>">
                                <button type="submit" class="btn">この候補者で承認</button>
                            </form>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <form method="post" action="approvals.php" style="display:inline;">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="leave_request_id" value="<?php echo (int) $lr['leave_request_id']; ?>">
                <button type="submit" class="btn btn-secondary">この休み申請を却下</button>
            </form>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="section">
    <h2>処理済みの休み申請</h2>
    <table>
        <thead>
            <tr>
                <th>処理日時</th>
                <th>休み申請者</th>
                <th>対象シフト</th>
                <th>結果</th>
                <th>代勤対応者</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($processedLeaveRequests)): ?>
            <tr>
                <td colspan="5">処理済みの休み申請はありません。</td>
            </tr>
            <?php else: ?>
                <?php foreach ($processedLeaveRequests as $lr): ?>
                <tr id="lr-<?php echo (int) $lr['leave_request_id']; ?>">
                    <td><?php echo htmlspecialchars($lr['processed_at'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($lr['requester_name']); ?></td>
                    <td>
                        <?php echo htmlspecialchars($lr['shift_date'] . ' ' . substr($lr['start_time'], 0, 5) . '-' . substr($lr['end_time'], 0, 5)); ?>
                        <?php if (!empty($lr['position'])): ?>
                            （<?php echo htmlspecialchars($lr['position']); ?>）
                        <?php endif; ?>
                    </td>
                    <td><?php echo renderStatusBadge(leaveRequestStatusLabel($lr['leave_status']), leaveRequestStatusBadgeClass($lr['leave_status'])); ?></td>
                    <td><?php echo htmlspecialchars($lr['substitute_name'] ?? '-'); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
