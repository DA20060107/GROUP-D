<?php
/**
 * 休み申請画面（従業員用）
 *
 * - ログイン中の従業員本人のシフトに対してのみ休み申請できる
 * - 休み申請の登録直後に代勤候補抽出・通知作成を行う（app/services/substitute_matcher.php）
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('employee');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/services/substitute_matcher.php';

$pageTitle = '休み申請';
$basePath  = '../../public/';

$user       = currentUser();
$employeeId = (int) $user['employee_id'];

$errorMessage   = '';
$successMessage = '';

$newLeaveRequestForm = [
    'shift_id' => '',
    'reason'   => '',
];

// ------------------------------------------------------------
// POST処理
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $shiftId = (int) ($_POST['shift_id'] ?? 0);
        $reason  = trim($_POST['reason'] ?? '');

        $newLeaveRequestForm = [
            'shift_id' => $_POST['shift_id'] ?? '',
            'reason'   => $reason,
        ];

        if ($shiftId <= 0) {
            $errorMessage = '対象シフトを選択してください。';
        } else {
            // 自分のシフトかつ申請可能な状態（予定）であることを確認する
            $stmt = $pdo->prepare(
                "SELECT id FROM shifts WHERE id = :id AND employee_id = :employee_id AND status = 'scheduled'"
            );
            $stmt->execute(['id' => $shiftId, 'employee_id' => $employeeId]);

            if ($stmt->fetch() === false) {
                $errorMessage = '指定されたシフトは休み申請の対象になっていません。';
            } else {
                // 同じシフトに対する休み申請の重複を防ぐ
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM leave_requests
                     WHERE shift_id = :shift_id AND status IN ('pending', 'matching')"
                );
                $stmt->execute(['shift_id' => $shiftId]);

                if ((int) $stmt->fetchColumn() > 0) {
                    $errorMessage = 'このシフトは既に休み申請済みです。';
                } else {
                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare(
                            "INSERT INTO leave_requests (shift_id, employee_id, reason, status)
                             VALUES (:shift_id, :employee_id, :reason, 'pending')"
                        );
                        $stmt->execute([
                            'shift_id'    => $shiftId,
                            'employee_id' => $employeeId,
                            'reason'      => $reason !== '' ? $reason : null,
                        ]);
                        $leaveRequestId = (int) $pdo->lastInsertId();

                        $pdo->prepare("UPDATE shifts SET status = 'leave_requested' WHERE id = :id")
                            ->execute(['id' => $shiftId]);

                        // 代勤候補抽出・通知作成・休み申請ステータス更新
                        processSubstituteMatching($pdo, $leaveRequestId);

                        $pdo->commit();
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        throw $e;
                    }

                    header('Location: leave_request.php?msg=created');
                    exit;
                }
            }
        }
    }
}

// ------------------------------------------------------------
// 完了メッセージ（リダイレクト後）
// ------------------------------------------------------------
if (isset($_GET['msg']) && $_GET['msg'] === 'created') {
    $successMessage = '休み申請を登録しました。代勤候補の抽出を行いました。';
}

// ------------------------------------------------------------
// 申請可能なシフト一覧（自分のシフトのうち「予定」のもの）
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    "SELECT * FROM shifts
     WHERE employee_id = :employee_id AND status = 'scheduled'
     ORDER BY shift_date, start_time"
);
$stmt->execute(['employee_id' => $employeeId]);
$eligibleShifts = $stmt->fetchAll();

// ------------------------------------------------------------
// 自分の休み申請一覧
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT lr.*, s.shift_date, s.start_time, s.end_time, s.position
     FROM leave_requests lr
     JOIN shifts s ON s.id = lr.shift_id
     WHERE lr.employee_id = :employee_id
     ORDER BY lr.created_at DESC'
);
$stmt->execute(['employee_id' => $employeeId]);
$myLeaveRequests = $stmt->fetchAll();

$statusLabels = [
    'pending'      => '受付中',
    'matching'     => '候補者回答待ち',
    'no_candidate' => '候補者なし（店長確認待ち）',
    'approved'     => '承認済み',
    'rejected'     => '却下',
];

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    休みたいシフトを選択し、理由を入力して申請してください。申請すると、自動で代勤候補の抽出が行われます。
</p>

<div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
<div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>

<div class="section">
    <h2>休み申請</h2>
    <?php if (empty($eligibleShifts)): ?>
        <p class="page-description">申請可能なシフトがありません。</p>
    <?php else: ?>
    <form method="post" action="leave_request.php">
        <input type="hidden" name="action" value="create">
        <div class="form-group">
            <label for="shift_id">対象シフト</label>
            <select id="shift_id" name="shift_id">
                <option value="">選択してください</option>
                <?php foreach ($eligibleShifts as $shift): ?>
                <option value="<?php echo (int) $shift['id']; ?>" <?php echo ((string) $shift['id'] === (string) $newLeaveRequestForm['shift_id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($shift['shift_date'] . ' ' . substr($shift['start_time'], 0, 5) . '-' . substr($shift['end_time'], 0, 5)); ?>
                    <?php if (!empty($shift['position'])): ?>
                        （<?php echo htmlspecialchars($shift['position']); ?>）
                    <?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="reason">申請理由</label>
            <textarea id="reason" name="reason" rows="4" placeholder="例: 通院のため"><?php echo htmlspecialchars($newLeaveRequestForm['reason']); ?></textarea>
        </div>
        <button type="submit" class="btn">申請する</button>
    </form>
    <?php endif; ?>
</div>

<div class="section">
    <h2>申請済みの休み申請</h2>
    <table>
        <thead>
            <tr>
                <th>勤務日</th>
                <th>開始時刻</th>
                <th>終了時刻</th>
                <th>申請理由</th>
                <th>状態</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($myLeaveRequests)): ?>
            <tr>
                <td colspan="5">申請済みの休み申請はありません。</td>
            </tr>
            <?php else: ?>
                <?php foreach ($myLeaveRequests as $lr): ?>
                <tr>
                    <td><?php echo htmlspecialchars($lr['shift_date']); ?></td>
                    <td><?php echo htmlspecialchars(substr($lr['start_time'], 0, 5)); ?></td>
                    <td><?php echo htmlspecialchars(substr($lr['end_time'], 0, 5)); ?></td>
                    <td><?php echo htmlspecialchars($lr['reason'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($statusLabels[$lr['status']] ?? $lr['status']); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
