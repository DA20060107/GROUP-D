<?php
/**
 * シフト確認画面（従業員用）
 *
 * - ログイン中の従業員本人に割り当てられているシフトのみを表示する
 * - 代勤承認により担当者が自分に変更されたシフト（status = 'substituted'）も表示する
 *   （元の担当者からは employee_id が変わるため一覧に表示されなくなる。結果は承認結果確認画面で確認できる）
 * - 無効化（cancelled）されたシフトは表示しない
 * - 承認済みの代勤シフト（substituted）について、代勤者本人が
 *   「代勤キャンセル申請」を出せる（店長承認後キャンセル / substitute_after_approval）
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('employee');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/services/cancellation_request_service.php';
require_once __DIR__ . '/../../app/includes/status_labels.php';

$pageTitle = 'シフト確認';
$basePath  = '../../public/';

$user       = currentUser();
$employeeId = (int) $user['employee_id'];

$errorMessage   = '';
$successMessage = '';

// ------------------------------------------------------------
// POST処理（代勤者による承認後キャンセル申請）
// ------------------------------------------------------------
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'request_substitute_cancel'
) {
    $leaveRequestId = (int) ($_POST['leave_request_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($leaveRequestId <= 0) {
        $errorMessage = '対象の代勤シフトを確認できませんでした。';
    } else {
        $result = createSubstituteAfterApprovalCancellationRequest(
            $pdo,
            $leaveRequestId,
            $employeeId,
            $reason
        );

        if ($result === 'created') {
            header('Location: shifts.php?msg=cancel_requested');
            exit;
        }
        if ($result === 'already_pending') {
            $errorMessage = 'この代勤は、既にキャンセル申請中です。';
        } elseif ($result === 'not_found') {
            $errorMessage = '指定された代勤シフトが見つかりません。自分の代勤のみ操作できます。';
        } else {
            $errorMessage = 'この代勤は、キャンセル申請の対象ではありません。';
        }
    }
}

if (($_GET['msg'] ?? '') === 'cancel_requested') {
    $successMessage = '代勤キャンセル申請を店長へ送信しました。店長の確認をお待ちください。';
}

// ------------------------------------------------------------
// 自分のシフト一覧（無効化済みを除く）
//
// 承認済みの代勤シフトについて、代勤キャンセル申請に必要な
// 休み申請ID・休み申請者・最新の代勤キャンセル申請状況も併せて取得する。
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT s.*,
            lr.id AS leave_request_id,
            lr.employee_id AS requester_employee_id,
            lr.status AS leave_status,
            cr.status AS cancellation_status,
            cr.reason AS cancellation_reason
     FROM shifts s
     LEFT JOIN leave_requests lr
        ON lr.id = (
            SELECT MAX(lr2.id)
            FROM leave_requests lr2
            WHERE lr2.shift_id = s.id
              AND lr2.status IN ('approved', 'replacement_pending')
        )
     LEFT JOIN cancellation_requests cr
        ON cr.id = (
            SELECT MAX(cr2.id)
            FROM cancellation_requests cr2
            WHERE cr2.leave_request_id = lr.id
              AND cr2.request_type = 'substitute_after_approval'
        )
     WHERE s.employee_id = :employee_id AND s.status <> 'cancelled'
     ORDER BY s.shift_date, s.start_time"
);
$stmt->execute(['employee_id' => $employeeId]);
$shifts = $stmt->fetchAll();

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    自分に割り当てられているシフトを確認できます。代勤として対応が確定したシフトもここに表示されます。
    承認済みの代勤を都合により対応できなくなった場合は、「代勤キャンセル申請」から店長へ申請できます。
</p>

<div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
<div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>

<div class="section">
    <table>
        <thead>
            <tr>
                <th>勤務日</th>
                <th>開始時刻</th>
                <th>終了時刻</th>
                <th>担当業務・ポジション</th>
                <th>備考</th>
                <th>状態</th>
                <th>代勤キャンセル</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($shifts)): ?>
            <tr>
                <td colspan="7">割り当てられているシフトはありません。</td>
            </tr>
            <?php else: ?>
                <?php foreach ($shifts as $shift): ?>
                <?php
                // 代勤キャンセル申請が出せる条件:
                //  - シフトが代勤反映済み（substituted）
                //  - その代勤の元になった承認済み休み申請がある
                //  - 自分は休み申請者本人ではない（＝代勤者本人）
                //  - 申請中（pending）の代勤キャンセルが無い
                $canRequestSubstituteCancel = (
                    $shift['status'] === 'substituted'
                    && $shift['leave_request_id'] !== null
                    && $shift['leave_status'] === 'approved'
                    && (int) $shift['requester_employee_id'] !== $employeeId
                    && $shift['cancellation_status'] !== 'pending'
                );
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($shift['shift_date']); ?></td>
                    <td><?php echo htmlspecialchars(substr($shift['start_time'], 0, 5)); ?></td>
                    <td><?php echo htmlspecialchars(substr($shift['end_time'], 0, 5)); ?></td>
                    <td><?php echo htmlspecialchars($shift['position'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($shift['note'] ?? ''); ?></td>
                    <td><?php echo renderStatusBadge(shiftStatusLabel($shift['status']), shiftStatusBadgeClass($shift['status'])); ?></td>
                    <td>
                        <?php if ($canRequestSubstituteCancel): ?>
                        <form method="post" action="shifts.php">
                            <input type="hidden" name="action" value="request_substitute_cancel">
                            <input type="hidden" name="leave_request_id" value="<?php echo (int) $shift['leave_request_id']; ?>">
                            <div class="form-group">
                                <label for="sub_cancel_reason_<?php echo (int) $shift['id']; ?>">キャンセル理由</label>
                                <textarea
                                    id="sub_cancel_reason_<?php echo (int) $shift['id']; ?>"
                                    name="reason"
                                    rows="2"
                                    placeholder="例: 体調不良のため対応できなくなりました"
                                ></textarea>
                            </div>
                            <button type="submit" class="btn">代勤キャンセル申請を送る</button>
                        </form>
                        <?php elseif ($shift['cancellation_status'] === 'pending'): ?>
                            <?php echo renderStatusBadge('キャンセル申請中', 'badge-warning'); ?>
                            <p class="page-description">店長の確認をお待ちください。</p>
                        <?php elseif ($shift['status'] === 'replacement_pending'): ?>
                            <?php echo renderStatusBadge('代勤キャンセル承認済み', 'badge-inactive'); ?>
                            <p class="page-description">キャンセルが承認され、この代勤の担当から外れました。店長が代勤者を再調整中です。</p>
                        <?php elseif ($shift['cancellation_status'] === 'rejected'): ?>
                            <?php echo renderStatusBadge('代勤キャンセル却下済み', 'badge-danger'); ?>
                            <p class="page-description">前回のキャンセル申請は却下されました。現在の代勤予定は維持されています。</p>
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

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
