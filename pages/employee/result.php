<?php
/**
 * 承認結果確認画面（従業員用）
 *
 * - 自分の休み申請・承認結果・承認後キャンセル申請状況を確認する
 * - 承認済みで代勤者へ担当変更済みの休み申請について、
 *   休み申請者本人だけが承認後キャンセル申請を作成できる
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('employee');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/services/cancellation_request_service.php';
require_once __DIR__ . '/../../app/includes/status_labels.php';

$pageTitle = '承認結果確認';
$basePath  = '../../public/';

$user       = currentUser();
$userId     = (int) $user['id'];
$employeeId = (int) $user['employee_id'];

$errorMessage   = '';
$successMessage = '';

// ------------------------------------------------------------
// POST処理（承認後キャンセル申請）
// ------------------------------------------------------------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'request_after_approval_cancel'
) {
    $leaveRequestId = (int) ($_POST['leave_request_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($leaveRequestId <= 0) {
        $errorMessage = '対象の休み申請を確認できませんでした。';
    } else {
        $result = createAfterApprovalCancellationRequest(
            $pdo,
            $leaveRequestId,
            $employeeId,
            $reason
        );

        if ($result === 'created') {
            header('Location: result.php?msg=cancel_requested#lr-' . $leaveRequestId);
            exit;
        }
        if ($result === 'already_pending') {
            $errorMessage = 'この休み申請は、既にキャンセル申請中です。';
        } elseif ($result === 'not_found') {
            $errorMessage = '指定された休み申請が見つかりません。自分の申請のみ操作できます。';
        } else {
            $errorMessage = 'この休み申請は、承認後キャンセル申請の対象ではありません。';
        }
    }
}

if (($_GET['msg'] ?? '') === 'cancel_requested') {
    $successMessage = '承認後キャンセル申請を店長へ送信しました。';
}

// ------------------------------------------------------------
// 自分が申請した休み申請と、最新の承認後キャンセル申請
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT lr.id AS leave_request_id, lr.reason AS leave_reason, lr.status AS leave_status,
            s.shift_date, s.start_time, s.end_time, s.position,
            s.status AS shift_status, s.employee_id AS current_shift_employee_id,
            current_emp.name AS current_shift_employee_name,
            cr.id AS cancellation_request_id, cr.status AS cancellation_status,
            cr.reason AS cancellation_reason, cr.created_at AS cancellation_created_at,
            cr.decided_at AS cancellation_decided_at
     FROM leave_requests lr
     JOIN shifts s ON s.id = lr.shift_id
     JOIN employees current_emp ON current_emp.id = s.employee_id
     LEFT JOIN cancellation_requests cr
        ON cr.id = (
            SELECT MAX(cr2.id)
            FROM cancellation_requests cr2
            WHERE cr2.leave_request_id = lr.id
              AND cr2.request_type = "requester_after_approval"
        )
     WHERE lr.employee_id = :employee_id
     ORDER BY lr.created_at DESC'
);
$stmt->execute(['employee_id' => $employeeId]);
$leaveRequests = $stmt->fetchAll();

// ------------------------------------------------------------
// 自分宛の承認・承認後キャンセル結果通知
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT n.*, lr.status AS leave_status,
            s.shift_date, s.start_time, s.end_time, s.position
     FROM notifications n
     LEFT JOIN leave_requests lr ON lr.id = n.related_leave_request_id
     LEFT JOIN shifts s ON s.id = lr.shift_id
     WHERE n.user_id = :user_id
       AND n.type IN (
           'approval_result',
           'after_approval_cancel_approved',
           'after_approval_cancel_rejected'
       )
     ORDER BY n.created_at DESC"
);
$stmt->execute(['user_id' => $userId]);
$results = $stmt->fetchAll();

// 通知確認画面から来た場合は「戻る」先を通知確認画面にする（それ以外はメニュー）
if (($_GET['from'] ?? '') === 'notifications') {
    $backUrl = 'notifications.php';
}

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    自分の休み申請の承認結果と、承認後キャンセル申請の状況を確認できます。
</p>

<div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
<div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>

<div class="section">
    <h2>自分の休み申請結果</h2>
    <table>
        <thead>
            <tr>
                <th>対象シフト</th>
                <th>休み申請状態</th>
                <th>現在の担当者</th>
                <th>キャンセル申請状況</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($leaveRequests)): ?>
            <tr>
                <td colspan="5">休み申請はありません。</td>
            </tr>
            <?php else: ?>
                <?php foreach ($leaveRequests as $lr): ?>
                <?php
                $canRequestCancellation = (
                    $lr['leave_status'] === 'approved'
                    && $lr['shift_status'] === 'substituted'
                    && (int) $lr['current_shift_employee_id'] !== $employeeId
                    && $lr['cancellation_status'] !== 'pending'
                );
                ?>
                <tr id="lr-<?php echo (int) $lr['leave_request_id']; ?>">
                    <td>
                        <?php echo htmlspecialchars(
                            $lr['shift_date'] . ' '
                            . substr($lr['start_time'], 0, 5) . '-'
                            . substr($lr['end_time'], 0, 5)
                        ); ?>
                        <?php if (!empty($lr['position'])): ?>
                            （<?php echo htmlspecialchars($lr['position']); ?>）
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo renderStatusBadge(
                            leaveRequestStatusLabel($lr['leave_status']),
                            leaveRequestStatusBadgeClass($lr['leave_status'])
                        ); ?>
                    </td>
                    <td><?php echo htmlspecialchars($lr['current_shift_employee_name']); ?></td>
                    <td>
                        <?php if ($lr['cancellation_status'] !== null): ?>
                            <?php echo renderStatusBadge(
                                cancellationRequestStatusLabel($lr['cancellation_status']),
                                cancellationRequestStatusBadgeClass($lr['cancellation_status'])
                            ); ?>
                            <?php if (!empty($lr['cancellation_reason'])): ?>
                                <br><?php echo nl2br(htmlspecialchars($lr['cancellation_reason'])); ?>
                            <?php endif; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($canRequestCancellation): ?>
                        <form method="post" action="result.php">
                            <input type="hidden" name="action" value="request_after_approval_cancel">
                            <input type="hidden" name="leave_request_id" value="<?php echo (int) $lr['leave_request_id']; ?>">
                            <div class="form-group">
                                <label for="cancel_reason_<?php echo (int) $lr['leave_request_id']; ?>">キャンセル理由</label>
                                <textarea
                                    id="cancel_reason_<?php echo (int) $lr['leave_request_id']; ?>"
                                    name="reason"
                                    rows="3"
                                    placeholder="例: 出勤できるようになったため"
                                ></textarea>
                            </div>
                            <button type="submit" class="btn">キャンセル申請を送る</button>
                        </form>
                        <?php elseif ($lr['cancellation_status'] === 'pending'): ?>
                            <p class="page-description">店長の確認をお待ちください。</p>
                        <?php elseif ($lr['leave_status'] === 'cancelled_after_approval'): ?>
                            <p class="page-description">キャンセルが承認され、シフト担当者があなたに戻りました。</p>
                        <?php elseif ($lr['cancellation_status'] === 'rejected'): ?>
                            <p class="page-description">前回のキャンセル申請は却下されました。</p>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="section">
    <h2>結果通知</h2>
    <table>
        <thead>
            <tr>
                <th>日時</th>
                <th>勤務日</th>
                <th>申請状態</th>
                <th>タイトル</th>
                <th>内容</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($results)): ?>
            <tr>
                <td colspan="5">結果通知はありません。</td>
            </tr>
            <?php else: ?>
                <?php foreach ($results as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                    <td><?php echo htmlspecialchars($r['shift_date'] ?? '-'); ?></td>
                    <td>
                        <?php echo $r['leave_status'] !== null
                            ? renderStatusBadge(
                                leaveRequestStatusLabel($r['leave_status']),
                                leaveRequestStatusBadgeClass($r['leave_status'])
                            )
                            : '-'; ?>
                    </td>
                    <td><?php echo htmlspecialchars($r['title']); ?></td>
                    <td><?php echo nl2br(htmlspecialchars($r['message'])); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
