<?php
/**
 * 承認後キャンセル申請確認画面（店長用）
 *
 * 現在は、休み申請者本人による requester_after_approval を承認・却下する。
 * 代勤者側キャンセルは将来拡張用で、今回は処理しない。
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/services/cancellation_request_service.php';
require_once __DIR__ . '/../../app/includes/status_labels.php';

$pageTitle = 'キャンセル申請確認';
$basePath  = '../../public/';

$user = currentUser();
$managerId = (int) $user['id'];

$errorMessage   = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $cancellationRequestId = (int) ($_POST['cancellation_request_id'] ?? 0);

    if (!in_array($action, ['approve', 'reject'], true) || $cancellationRequestId <= 0) {
        $errorMessage = '処理対象のキャンセル申請を確認できませんでした。';
    } else {
        $decision = $action === 'approve' ? 'approved' : 'rejected';

        // 申請種別を確認し、休み申請者側 / 代勤者側で処理を振り分ける
        $typeStmt = $pdo->prepare('SELECT request_type FROM cancellation_requests WHERE id = :id');
        $typeStmt->execute(['id' => $cancellationRequestId]);
        $requestType = $typeStmt->fetchColumn();

        if ($requestType === CANCELLATION_TYPE_SUBSTITUTE_AFTER_APPROVAL) {
            $result = decideSubstituteAfterApprovalCancellationRequest(
                $pdo,
                $cancellationRequestId,
                $managerId,
                $decision
            );
            $approvedMsg = 'approved_substitute';
        } else {
            $result = decideAfterApprovalCancellationRequest(
                $pdo,
                $cancellationRequestId,
                $managerId,
                $decision
            );
            $approvedMsg = 'approved';
        }

        if ($result === 'approved') {
            header('Location: cancellation_requests.php?msg=' . $approvedMsg . '#cr-' . $cancellationRequestId);
            exit;
        }
        if ($result === 'rejected') {
            header('Location: cancellation_requests.php?msg=rejected#cr-' . $cancellationRequestId);
            exit;
        }
        if ($result === 'already_decided') {
            $errorMessage = 'このキャンセル申請は既に処理済みです。';
        } elseif ($result === 'invalid_type') {
            $errorMessage = 'この申請種別は現在の処理対象ではありません。';
        } elseif ($result === 'not_eligible') {
            $errorMessage = '元の休み申請またはシフトの状態が変わったため処理できません。';
        } else {
            $errorMessage = '指定されたキャンセル申請が見つかりません。';
        }
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'approved') {
        $successMessage = 'キャンセル申請を承認し、シフト担当者を元の休み申請者へ戻しました。';
    } elseif ($_GET['msg'] === 'approved_substitute') {
        $successMessage = 'キャンセル申請を承認しました。対象シフトと休み申請を「代勤者再調整中」にしました（シフト担当者は元の休み申請者へは戻していません）。代勤者の再調整を行ってください。';
    } elseif ($_GET['msg'] === 'rejected') {
        $successMessage = 'キャンセル申請を却下しました。現在の代勤状態は維持されます。';
    }
}

$baseSelect = "
    SELECT cr.id AS cancellation_request_id, cr.request_type, cr.reason,
           cr.status AS cancellation_status, cr.created_at,
           cr.decided_at, lr.id AS leave_request_id, lr.status AS leave_status,
           requester.name AS requester_name,
           approved_sub.name AS substitute_name,
           current_emp.name AS current_shift_employee_name,
           s.shift_date, s.start_time, s.end_time, s.position,
           decider.name AS decided_by_name
    FROM cancellation_requests cr
    JOIN leave_requests lr ON lr.id = cr.leave_request_id
    JOIN shifts s ON s.id = lr.shift_id
    JOIN employees requester ON requester.id = lr.employee_id
    JOIN employees current_emp ON current_emp.id = s.employee_id
    LEFT JOIN approvals a
       ON a.leave_request_id = lr.id
      AND a.status = 'approved'
    LEFT JOIN substitute_candidates approved_sc
       ON approved_sc.id = a.substitute_candidate_id
    LEFT JOIN employees approved_sub
       ON approved_sub.id = approved_sc.candidate_employee_id
    LEFT JOIN users decider ON decider.id = cr.decided_by_user_id
";

$pendingRequests = $pdo->query(
    $baseSelect
    . " WHERE cr.status = 'pending'
        ORDER BY cr.created_at"
)->fetchAll();

$processedRequests = $pdo->query(
    $baseSelect
    . " WHERE cr.status IN ('approved', 'rejected')
        ORDER BY cr.decided_at DESC, cr.id DESC"
)->fetchAll();

// 通知確認画面から来た場合は「戻る」先を通知確認画面にする（それ以外はメニュー）
if (($_GET['from'] ?? '') === 'notifications') {
    $backUrl = 'notifications.php';
}

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    承認済みの休み申請に対するキャンセル申請を確認し、承認または却下します。
</p>

<div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
<div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>

<div class="section">
    <h2>未処理のキャンセル申請</h2>
    <?php if (empty($pendingRequests)): ?>
        <p class="page-description">未処理のキャンセル申請はありません。</p>
    <?php else: ?>
        <?php foreach ($pendingRequests as $request): ?>
        <div class="section" id="cr-<?php echo (int) $request['cancellation_request_id']; ?>">
            <table>
                <tbody>
                    <tr><th>キャンセル申請ID</th><td><?php echo (int) $request['cancellation_request_id']; ?></td></tr>
                    <tr><th>申請種別</th><td><?php echo htmlspecialchars(cancellationRequestTypeLabel($request['request_type'])); ?></td></tr>
                    <tr><th>休み申請者</th><td><?php echo htmlspecialchars($request['requester_name']); ?></td></tr>
                    <tr><th>代勤者</th><td><?php echo htmlspecialchars($request['substitute_name'] ?? '-'); ?></td></tr>
                    <tr>
                        <th>対象シフト</th>
                        <td><?php echo htmlspecialchars(
                            $request['shift_date'] . ' '
                            . substr($request['start_time'], 0, 5) . '-'
                            . substr($request['end_time'], 0, 5)
                        ); ?></td>
                    </tr>
                    <tr><th>現在のシフト担当者</th><td><?php echo htmlspecialchars($request['current_shift_employee_name']); ?></td></tr>
                    <tr><th>キャンセル理由</th><td><?php echo nl2br(htmlspecialchars($request['reason'] ?? '')); ?></td></tr>
                    <tr><th>申請日時</th><td><?php echo htmlspecialchars($request['created_at']); ?></td></tr>
                    <tr>
                        <th>申請状態</th>
                        <td><?php echo renderStatusBadge(
                            cancellationRequestStatusLabel($request['cancellation_status']),
                            cancellationRequestStatusBadgeClass($request['cancellation_status'])
                        ); ?></td>
                    </tr>
                </tbody>
            </table>
            <div class="table-actions">
                <form method="post" action="cancellation_requests.php">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="cancellation_request_id" value="<?php echo (int) $request['cancellation_request_id']; ?>">
                    <button type="submit" class="btn">キャンセルを承認</button>
                </form>
                <form method="post" action="cancellation_requests.php">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="cancellation_request_id" value="<?php echo (int) $request['cancellation_request_id']; ?>">
                    <button type="submit" class="btn btn-secondary">キャンセルを却下</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="section">
    <h2>処理済みのキャンセル申請</h2>
    <table>
        <thead>
            <tr>
                <th>処理日時</th>
                <th>申請種別</th>
                <th>休み申請者</th>
                <th>対象シフト</th>
                <th>結果</th>
                <th>処理した店長</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($processedRequests)): ?>
            <tr><td colspan="6">処理済みのキャンセル申請はありません。</td></tr>
            <?php else: ?>
                <?php foreach ($processedRequests as $request): ?>
                <tr id="cr-<?php echo (int) $request['cancellation_request_id']; ?>">
                    <td><?php echo htmlspecialchars($request['decided_at'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars(cancellationRequestTypeLabel($request['request_type'])); ?></td>
                    <td><?php echo htmlspecialchars($request['requester_name']); ?></td>
                    <td><?php echo htmlspecialchars(
                        $request['shift_date'] . ' '
                        . substr($request['start_time'], 0, 5) . '-'
                        . substr($request['end_time'], 0, 5)
                    ); ?></td>
                    <td><?php echo renderStatusBadge(
                        cancellationRequestStatusLabel($request['cancellation_status']),
                        cancellationRequestStatusBadgeClass($request['cancellation_status'])
                    ); ?></td>
                    <td><?php echo htmlspecialchars($request['decided_by_name'] ?? '-'); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
