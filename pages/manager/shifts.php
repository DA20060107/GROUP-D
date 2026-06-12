<?php
/**
 * シフト作成・一覧確認画面（店長用）
 *
 * - シフトの新規作成
 * - 登録済みシフトの一覧確認
 * - シフトの無効化（status を 'cancelled' に更新する論理削除）
 *
 * TODO: availability（勤務可能日）を参照したシフト作成支援は今後実装する予定
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');
require_once __DIR__ . '/../../app/config/database.php';

$pageTitle = 'シフト作成・一覧確認';
$basePath  = '../../public/';

$errorMessage   = '';
$successMessage = '';

$newShiftForm = [
    'employee_id' => '',
    'shift_date'  => '',
    'start_time'  => '',
    'end_time'    => '',
    'position'    => '',
    'note'        => '',
];

// ------------------------------------------------------------
// POST処理
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_shift') {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $shiftDate  = trim($_POST['shift_date'] ?? '');
        $startTime  = trim($_POST['start_time'] ?? '');
        $endTime    = trim($_POST['end_time'] ?? '');
        $position   = trim($_POST['position'] ?? '');
        $note       = trim($_POST['note'] ?? '');

        $newShiftForm = [
            'employee_id' => $_POST['employee_id'] ?? '',
            'shift_date'  => $shiftDate,
            'start_time'  => $startTime,
            'end_time'    => $endTime,
            'position'    => $position,
            'note'        => $note,
        ];

        if ($employeeId <= 0 || $shiftDate === '' || $startTime === '' || $endTime === '') {
            $errorMessage = '従業員・勤務日・開始時刻・終了時刻は必須です。';
        } elseif ($startTime >= $endTime) {
            $errorMessage = '開始時刻は終了時刻より前にしてください。';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM employees WHERE id = :id AND is_active = 1');
            $stmt->execute(['id' => $employeeId]);

            if ($stmt->fetch() === false) {
                $errorMessage = '指定された従業員が存在しないか、無効化されています。';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO shifts (employee_id, shift_date, start_time, end_time, position, note, status)
                     VALUES (:employee_id, :shift_date, :start_time, :end_time, :position, :note, 'scheduled')"
                );
                $stmt->execute([
                    'employee_id' => $employeeId,
                    'shift_date'  => $shiftDate,
                    'start_time'  => $startTime,
                    'end_time'    => $endTime,
                    'position'    => $position !== '' ? $position : null,
                    'note'        => $note !== '' ? $note : null,
                ]);

                header('Location: shifts.php?msg=created');
                exit;
            }
        }
    } elseif ($action === 'cancel_shift') {
        $id = (int) ($_POST['shift_id'] ?? 0);

        $stmt = $pdo->prepare('SELECT id FROM shifts WHERE id = :id');
        $stmt->execute(['id' => $id]);

        if ($stmt->fetch() === false) {
            $errorMessage = '指定されたシフトが見つかりません。';
        } else {
            $pdo->prepare("UPDATE shifts SET status = 'cancelled' WHERE id = :id")
                ->execute(['id' => $id]);

            header('Location: shifts.php?msg=cancelled');
            exit;
        }
    }
}

// ------------------------------------------------------------
// 完了メッセージ（リダイレクト後）
// ------------------------------------------------------------
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'created':
            $successMessage = 'シフトを登録しました。';
            break;
        case 'cancelled':
            $successMessage = 'シフトを無効化しました。';
            break;
    }
}

// ------------------------------------------------------------
// シフト作成フォーム用：有効な従業員一覧
// ------------------------------------------------------------
$activeEmployees = $pdo->query(
    'SELECT id, name FROM employees WHERE is_active = 1 ORDER BY name'
)->fetchAll();

// ------------------------------------------------------------
// シフト一覧（無効化済みを除く）
// ------------------------------------------------------------
$shifts = $pdo->query(
    "SELECT s.*, e.name AS employee_name
     FROM shifts s
     JOIN employees e ON e.id = s.employee_id
     WHERE s.status <> 'cancelled'
     ORDER BY s.shift_date, s.start_time"
)->fetchAll();

$statusLabels = [
    'scheduled'        => '予定',
    'leave_requested'  => '休み申請中',
    'substituted'      => '代勤対応済み',
    'cancelled'        => '無効化済み',
];

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    シフトの新規作成と、登録済みシフトの一覧確認を行います。
</p>

<div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
<div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>

<div class="section">
    <h2>シフトの新規作成</h2>
    <?php if (empty($activeEmployees)): ?>
        <p class="page-description">有効な従業員が登録されていません。先に従業員情報管理から従業員を登録してください。</p>
    <?php else: ?>
    <form method="post" action="shifts.php">
        <input type="hidden" name="action" value="create_shift">
        <div class="form-group">
            <label for="shift_employee_id">従業員</label>
            <select id="shift_employee_id" name="employee_id">
                <option value="">選択してください</option>
                <?php foreach ($activeEmployees as $emp): ?>
                <option value="<?php echo (int) $emp['id']; ?>" <?php echo ((string) $emp['id'] === (string) $newShiftForm['employee_id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($emp['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="shift_date">勤務日</label>
            <input type="date" id="shift_date" name="shift_date" value="<?php echo htmlspecialchars($newShiftForm['shift_date']); ?>">
        </div>
        <div class="form-group">
            <label for="shift_start_time">開始時刻</label>
            <input type="time" id="shift_start_time" name="start_time" value="<?php echo htmlspecialchars($newShiftForm['start_time']); ?>">
        </div>
        <div class="form-group">
            <label for="shift_end_time">終了時刻</label>
            <input type="time" id="shift_end_time" name="end_time" value="<?php echo htmlspecialchars($newShiftForm['end_time']); ?>">
        </div>
        <div class="form-group">
            <label for="shift_position">担当業務・ポジション</label>
            <input type="text" id="shift_position" name="position" placeholder="例: ホール, キッチン" value="<?php echo htmlspecialchars($newShiftForm['position']); ?>">
        </div>
        <div class="form-group">
            <label for="shift_note">備考</label>
            <textarea id="shift_note" name="note" rows="3"><?php echo htmlspecialchars($newShiftForm['note']); ?></textarea>
        </div>
        <button type="submit" class="btn">登録する</button>
    </form>
    <?php endif; ?>
</div>

<div class="section">
    <h2>シフト一覧</h2>
    <table>
        <thead>
            <tr>
                <th>勤務日</th>
                <th>開始時刻</th>
                <th>終了時刻</th>
                <th>従業員</th>
                <th>担当業務・ポジション</th>
                <th>備考</th>
                <th>状態</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($shifts)): ?>
            <tr>
                <td colspan="8">登録されているシフトはありません。</td>
            </tr>
            <?php else: ?>
                <?php foreach ($shifts as $shift): ?>
                <tr>
                    <td><?php echo htmlspecialchars($shift['shift_date']); ?></td>
                    <td><?php echo htmlspecialchars(substr($shift['start_time'], 0, 5)); ?></td>
                    <td><?php echo htmlspecialchars(substr($shift['end_time'], 0, 5)); ?></td>
                    <td><?php echo htmlspecialchars($shift['employee_name']); ?></td>
                    <td><?php echo htmlspecialchars($shift['position'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($shift['note'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($statusLabels[$shift['status']] ?? $shift['status']); ?></td>
                    <td>
                        <form method="post" action="shifts.php">
                            <input type="hidden" name="action" value="cancel_shift">
                            <input type="hidden" name="shift_id" value="<?php echo (int) $shift['id']; ?>">
                            <button type="submit" class="btn btn-secondary">無効化</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
