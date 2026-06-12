<?php
/**
 * 勤務可能日登録・一覧確認画面（従業員用）
 *
 * - ログイン中の従業員本人の勤務可能日を登録・一覧確認・削除する
 * - 他の従業員の勤務可能日は操作できない（employee_id はログイン中ユーザーのものを使用）
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('employee');
require_once __DIR__ . '/../../app/config/database.php';

$pageTitle = '勤務可能日登録';
$basePath  = '../../public/';

$user       = currentUser();
$employeeId = (int) $user['employee_id'];

$errorMessage   = '';
$successMessage = '';

$newAvailabilityForm = [
    'available_date' => '',
    'start_time'     => '',
    'end_time'       => '',
    'note'           => '',
];

// ------------------------------------------------------------
// POST処理
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $availableDate = trim($_POST['available_date'] ?? '');
        $startTime     = trim($_POST['start_time'] ?? '');
        $endTime       = trim($_POST['end_time'] ?? '');
        $note          = trim($_POST['note'] ?? '');

        $newAvailabilityForm = [
            'available_date' => $availableDate,
            'start_time'     => $startTime,
            'end_time'       => $endTime,
            'note'           => $note,
        ];

        if ($availableDate === '' || $startTime === '' || $endTime === '') {
            $errorMessage = '勤務可能日・開始時刻・終了時刻は必須です。';
        } elseif ($startTime >= $endTime) {
            $errorMessage = '開始時刻は終了時刻より前にしてください。';
        } else {
            // 同じ日付で時間帯が重複する登録をある程度防ぐ
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM availability
                 WHERE employee_id = :employee_id
                   AND available_date = :available_date
                   AND NOT (end_time <= :start_time OR start_time >= :end_time)'
            );
            $stmt->execute([
                'employee_id'    => $employeeId,
                'available_date' => $availableDate,
                'start_time'     => $startTime,
                'end_time'       => $endTime,
            ]);

            if ((int) $stmt->fetchColumn() > 0) {
                $errorMessage = '同じ日付・時間帯と重複する勤務可能日が既に登録されています。';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO availability (employee_id, available_date, start_time, end_time, note, created_by)
                     VALUES (:employee_id, :available_date, :start_time, :end_time, :note, :created_by)'
                );
                $stmt->execute([
                    'employee_id'    => $employeeId,
                    'available_date' => $availableDate,
                    'start_time'     => $startTime,
                    'end_time'       => $endTime,
                    'note'           => $note !== '' ? $note : null,
                    'created_by'     => (int) $user['id'],
                ]);

                header('Location: availability.php?msg=created');
                exit;
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);

        // employee_id を条件に含めることで、他人のレコードを削除できないようにする
        $stmt = $pdo->prepare('DELETE FROM availability WHERE id = :id AND employee_id = :employee_id');
        $stmt->execute(['id' => $id, 'employee_id' => $employeeId]);

        header('Location: availability.php?msg=deleted');
        exit;
    }
}

// ------------------------------------------------------------
// 完了メッセージ（リダイレクト後）
// ------------------------------------------------------------
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'created':
            $successMessage = '勤務可能日を登録しました。';
            break;
        case 'deleted':
            $successMessage = '勤務可能日を削除しました。';
            break;
    }
}

// ------------------------------------------------------------
// 自分の勤務可能日一覧
// ------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT * FROM availability WHERE employee_id = :employee_id ORDER BY available_date, start_time'
);
$stmt->execute(['employee_id' => $employeeId]);
$availabilityList = $stmt->fetchAll();

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    自分の勤務可能日を登録・確認できます。登録した内容は店長が代勤候補の検討に利用します。
</p>

<div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
<div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>

<div class="section">
    <h2>勤務可能日の登録</h2>
    <form method="post" action="availability.php">
        <input type="hidden" name="action" value="create">
        <div class="form-group">
            <label for="available_date">勤務可能日</label>
            <input type="date" id="available_date" name="available_date" value="<?php echo htmlspecialchars($newAvailabilityForm['available_date']); ?>">
        </div>
        <div class="form-group">
            <label for="start_time">開始時刻</label>
            <input type="time" id="start_time" name="start_time" value="<?php echo htmlspecialchars($newAvailabilityForm['start_time']); ?>">
        </div>
        <div class="form-group">
            <label for="end_time">終了時刻</label>
            <input type="time" id="end_time" name="end_time" value="<?php echo htmlspecialchars($newAvailabilityForm['end_time']); ?>">
        </div>
        <div class="form-group">
            <label for="note">備考</label>
            <textarea id="note" name="note" rows="3"><?php echo htmlspecialchars($newAvailabilityForm['note']); ?></textarea>
        </div>
        <button type="submit" class="btn">登録する</button>
    </form>
</div>

<div class="section">
    <h2>登録済みの勤務可能日</h2>
    <table>
        <thead>
            <tr>
                <th>勤務可能日</th>
                <th>開始時刻</th>
                <th>終了時刻</th>
                <th>備考</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($availabilityList)): ?>
            <tr>
                <td colspan="5">登録されている勤務可能日はありません。</td>
            </tr>
            <?php else: ?>
                <?php foreach ($availabilityList as $a): ?>
                <tr>
                    <td><?php echo htmlspecialchars($a['available_date']); ?></td>
                    <td><?php echo htmlspecialchars(substr($a['start_time'], 0, 5)); ?></td>
                    <td><?php echo htmlspecialchars(substr($a['end_time'], 0, 5)); ?></td>
                    <td><?php echo htmlspecialchars($a['note'] ?? ''); ?></td>
                    <td>
                        <form method="post" action="availability.php">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int) $a['id']; ?>">
                            <button type="submit" class="btn btn-secondary">削除</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
