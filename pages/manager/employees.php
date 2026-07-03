<?php
/**
 * 従業員情報管理画面（店長用）
 *
 * - 従業員一覧の表示・新規登録（ログインアカウント同時作成）・編集・有効/無効切替
 * - 従業員ごとの勤務可能日確認（店長は登録しない。確認のみ）
 *
 * TODO: 休み申請・代勤候補抽出・通知作成・店長承認は今後実装する予定
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/includes/status_labels.php';

$pageTitle = '従業員情報管理';
$basePath  = '../../public/';

$errorMessage   = '';
$successMessage = '';

// 編集フォームに表示する従業員データ（再表示用）
$editEmployee = null;

// 新規登録フォームの再表示用データ
$newEmployeeForm = ['name' => '', 'username' => '', 'position' => '', 'note' => '', 'hire_date' => '', 'skill_level' => '3'];

// ------------------------------------------------------------
// POST処理
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_employee') {
        $name        = trim($_POST['name'] ?? '');
        $username    = trim($_POST['username'] ?? '');
        $password    = (string) ($_POST['password'] ?? '');
        $position    = trim($_POST['position'] ?? '');
        $note        = trim($_POST['note'] ?? '');
        $hireDate    = trim($_POST['hire_date'] ?? '');
        $skillLevel  = trim($_POST['skill_level'] ?? '3');

        // 入力エラー時に再表示するためのフォーム値
        $newEmployeeForm = [
            'name'        => $name,
            'username'    => $username,
            'position'    => $position,
            'note'        => $note,
            'hire_date'   => $hireDate,
            'skill_level' => $skillLevel,
        ];

        if ($name === '' || $username === '' || $password === '') {
            $errorMessage = '氏名・ログインID・初期パスワードは必須です。';
        } elseif (!in_array($skillLevel, ['1', '2', '3', '4', '5'], true)) {
            $errorMessage = 'スキルレベルは1〜5の範囲で指定してください。';
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
            $stmt->execute(['username' => $username]);

            if ((int) $stmt->fetchColumn() > 0) {
                $errorMessage = 'このログインIDは既に使用されています。別のログインIDを指定してください。';
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare(
                        'INSERT INTO employees (name, position, note, hire_date, skill_level)
                         VALUES (:name, :position, :note, :hire_date, :skill_level)'
                    );
                    $stmt->execute([
                        'name'        => $name,
                        'position'    => $position !== '' ? $position : null,
                        'note'        => $note !== '' ? $note : null,
                        'hire_date'   => $hireDate !== '' ? $hireDate : null,
                        'skill_level' => (int) $skillLevel,
                    ]);
                    $newEmployeeId = (int) $pdo->lastInsertId();

                    $stmt = $pdo->prepare(
                        'INSERT INTO users (username, password, role, employee_id, name)
                         VALUES (:username, :password, "employee", :employee_id, :name)'
                    );
                    $stmt->execute([
                        'username'    => $username,
                        'password'    => password_hash($password, PASSWORD_DEFAULT),
                        'employee_id' => $newEmployeeId,
                        'name'        => $name,
                    ]);

                    $pdo->commit();

                    header('Location: employees.php?msg=created');
                    exit;
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $errorMessage = '登録に失敗しました。エラー詳細: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'update_employee') {
        $id         = (int) ($_POST['employee_id'] ?? 0);
        $name       = trim($_POST['name'] ?? '');
        $username   = trim($_POST['username'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');
        $hireDate   = trim($_POST['hire_date'] ?? '');
        $position   = trim($_POST['position'] ?? '');
        $note       = trim($_POST['note'] ?? '');
        $skillLevel = trim($_POST['skill_level'] ?? '3');

        $stmt = $pdo->prepare(
            'SELECT e.*, u.id AS user_id, u.username
             FROM employees e
             LEFT JOIN users u ON u.employee_id = e.id AND u.role = "employee"
             WHERE e.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $target = $stmt->fetch();

        if ($target === false) {
            $errorMessage = '指定された従業員が見つかりません。';
        } elseif ($name === '') {
            $errorMessage = '氏名は必須です。';
            $editEmployee = $_POST;
        } elseif ($username === '') {
            $errorMessage = 'ログインIDは必須です。';
            $editEmployee = $_POST;
        } elseif (!in_array($skillLevel, ['1', '2', '3', '4', '5'], true)) {
            $errorMessage = 'スキルレベルは1〜5の範囲で指定してください。';
            $editEmployee = $_POST;
        } elseif ($target['user_id'] === null) {
            $errorMessage = '対象従業員のログインアカウントが見つかりません。';
            $editEmployee = $_POST;
        } else {
            // パスワードは任意。空欄の場合は現在のパスワードを維持する（トリムしない）
            $newPassword = (string) ($_POST['password'] ?? '');
            $isUsernameChanged = $username !== (string) $target['username'];

            // users.username は全ユーザーで一意。本人以外が使っているログインIDは指定できない。
            $stmt = $pdo->prepare(
                'SELECT id
                 FROM users
                 WHERE username = :username
                   AND id <> :current_user_id
                 LIMIT 1'
            );
            $stmt->execute([
                'username'        => $username,
                'current_user_id' => (int) $target['user_id'],
            ]);

            if ($stmt->fetch() !== false) {
                $errorMessage = 'このログインIDは既に使用されています。別のログインIDを指定してください。';
                $editEmployee = $_POST;
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare(
                        'UPDATE employees
                         SET name = :name, email = :email, phone = :phone, hire_date = :hire_date,
                             position = :position, note = :note, skill_level = :skill_level
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        'name'        => $name,
                        'email'       => $email !== '' ? $email : null,
                        'phone'       => $phone !== '' ? $phone : null,
                        'hire_date'   => $hireDate !== '' ? $hireDate : null,
                        'position'    => $position !== '' ? $position : null,
                        'note'        => $note !== '' ? $note : null,
                        'skill_level' => (int) $skillLevel,
                        'id'          => $id,
                    ]);

                    // ログイン中ユーザー名の表示とログインIDにも反映させるため users も更新する
                    $userSql = 'UPDATE users SET name = :name, username = :username';
                    $userParams = [
                        'name'     => $name,
                        'username' => $username,
                        'id'       => $id,
                    ];

                    // パスワードが入力された場合のみ、ハッシュ化して更新する
                    if ($newPassword !== '') {
                        $userSql .= ', password = :password';
                        $userParams['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
                    }

                    $userSql .= ' WHERE employee_id = :id AND role = "employee"';
                    $pdo->prepare($userSql)->execute($userParams);

                    $pdo->commit();

                    if ($isUsernameChanged && $newPassword !== '') {
                        $messageKey = 'updated_login_pw';
                    } elseif ($isUsernameChanged) {
                        $messageKey = 'updated_login';
                    } elseif ($newPassword !== '') {
                        $messageKey = 'updated_pw';
                    } else {
                        $messageKey = 'updated';
                    }

                    header('Location: employees.php?msg=' . $messageKey);
                    exit;
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $errorMessage = '更新に失敗しました。エラー詳細: ' . $e->getMessage();
                    $editEmployee = $_POST;
                }
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int) ($_POST['employee_id'] ?? 0);

        $stmt = $pdo->prepare('SELECT id FROM employees WHERE id = :id');
        $stmt->execute(['id' => $id]);

        if ($stmt->fetch() === false) {
            $errorMessage = '指定された従業員が見つかりません。';
        } else {
            $pdo->prepare('UPDATE employees SET is_active = 1 - is_active WHERE id = :id')
                ->execute(['id' => $id]);

            header('Location: employees.php?msg=toggled');
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
            $successMessage = '従業員情報とログインアカウントを登録しました。';
            break;
        case 'updated':
            $successMessage = '従業員情報を更新しました。';
            break;
        case 'updated_pw':
            $successMessage = '従業員情報を更新し、パスワードを変更しました。';
            break;
        case 'updated_login':
            $successMessage = '従業員情報を更新し、ログインIDを変更しました。';
            break;
        case 'updated_login_pw':
            $successMessage = '従業員情報を更新し、ログインIDとパスワードを変更しました。';
            break;
        case 'toggled':
            $successMessage = '従業員の有効/無効状態を変更しました。';
            break;
    }
}

// ------------------------------------------------------------
// 編集対象の取得（GET ?edit=ID）
// ------------------------------------------------------------
if ($editEmployee === null && isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $stmt = $pdo->prepare(
        'SELECT e.*, u.username
         FROM employees e
         LEFT JOIN users u ON u.employee_id = e.id AND u.role = "employee"
         WHERE e.id = :id'
    );
    $stmt->execute(['id' => $editId]);
    $row = $stmt->fetch();

    if ($row === false) {
        $errorMessage = '指定された従業員が見つかりません。';
    } else {
        $editEmployee = $row;
    }
}

// ------------------------------------------------------------
// 従業員一覧（ログインIDを併せて表示）
// ------------------------------------------------------------
$employees = $pdo->query(
    'SELECT e.*, u.username
     FROM employees e
     LEFT JOIN users u ON u.employee_id = e.id AND u.role = "employee"
     ORDER BY e.id'
)->fetchAll();

// ------------------------------------------------------------
// 従業員ごとの勤務可能日確認（絞り込み対応）
// ------------------------------------------------------------
$availabilityEmployeeId = null;
if (isset($_GET['availability_employee_id']) && $_GET['availability_employee_id'] !== '') {
    $availabilityEmployeeId = (int) $_GET['availability_employee_id'];
}

if ($availabilityEmployeeId !== null) {
    $stmt = $pdo->prepare(
        'SELECT a.*, e.name AS employee_name
         FROM availability a
         JOIN employees e ON e.id = a.employee_id
         WHERE a.employee_id = :employee_id
         ORDER BY a.available_date, a.start_time'
    );
    $stmt->execute(['employee_id' => $availabilityEmployeeId]);
} else {
    $stmt = $pdo->query(
        'SELECT a.*, e.name AS employee_name
         FROM availability a
         JOIN employees e ON e.id = a.employee_id
         ORDER BY a.available_date, a.start_time'
    );
}
$availabilityList = $stmt->fetchAll();

require_once __DIR__ . '/../../app/includes/header.php';

/**
 * フォーム再表示用に、編集中データまたは空文字を取得する
 */
function ef($editEmployee, $key)
{
    return htmlspecialchars((string) ($editEmployee[$key] ?? ''));
}
?>

<p class="page-description">
    従業員の基本情報・ログインアカウントの管理と、従業員ごとの勤務可能日の確認を行います。
</p>

<div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
<div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>

<?php if ($editEmployee !== null): ?>
<div class="section">
    <h2>従業員情報の編集</h2>
    <form method="post" action="employees.php">
        <input type="hidden" name="action" value="update_employee">
        <input type="hidden" name="employee_id" value="<?php echo ef($editEmployee, 'id'); ?>">
        <div class="form-group">
            <label for="edit_name">氏名</label>
            <input type="text" id="edit_name" name="name" value="<?php echo ef($editEmployee, 'name'); ?>">
        </div>
        <div class="form-group">
            <label for="edit_username">ログインID</label>
            <input type="text" id="edit_username" name="username" value="<?php echo ef($editEmployee, 'username'); ?>" placeholder="例: employee06">
        </div>
        <div class="form-group">
            <label for="edit_email">メールアドレス</label>
            <input type="email" id="edit_email" name="email" value="<?php echo ef($editEmployee, 'email'); ?>">
        </div>
        <div class="form-group">
            <label for="edit_phone">電話番号</label>
            <input type="text" id="edit_phone" name="phone" value="<?php echo ef($editEmployee, 'phone'); ?>">
        </div>
        <div class="form-group">
            <label for="edit_hire_date">入社日</label>
            <input type="date" id="edit_hire_date" name="hire_date" value="<?php echo ef($editEmployee, 'hire_date'); ?>">
        </div>
        <div class="form-group">
            <label for="edit_position">担当可能業務・ポジション</label>
            <input type="text" id="edit_position" name="position" value="<?php echo ef($editEmployee, 'position'); ?>" placeholder="例: ホール, キッチン">
        </div>
        <div class="form-group">
            <label for="edit_skill_level">スキルレベル</label>
            <select id="edit_skill_level" name="skill_level">
                <?php foreach (skillLevelOptions() as $level => $label): ?>
                <option value="<?php echo (int) $level; ?>" <?php echo ((string) $level === (string) ($editEmployee['skill_level'] ?? '3')) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="edit_note">備考</label>
            <textarea id="edit_note" name="note" rows="3"><?php echo ef($editEmployee, 'note'); ?></textarea>
        </div>
        <div class="form-group">
            <label for="edit_password">新しいパスワード</label>
            <input type="text" id="edit_password" name="password" value="" autocomplete="new-password" placeholder="変更する場合のみ入力">
            <p class="page-description" style="margin: 6px 0 0; font-size: 0.9em;">
                空欄のまま更新すると、パスワードは変更されません。入力した場合はハッシュ化して保存されます。
            </p>
        </div>
        <button type="submit" class="btn">更新する</button>
        <a class="btn btn-secondary" href="employees.php">キャンセル</a>
    </form>
</div>
<?php endif; ?>

<div class="section">
    <h2>従業員の新規登録</h2>
    <p class="page-description">
        新規登録時に、ログイン用アカウント（role: employee）も同時に作成されます。
    </p>
    <form method="post" action="employees.php">
        <input type="hidden" name="action" value="create_employee">
        <div class="form-group">
            <label for="new_name">氏名</label>
            <input type="text" id="new_name" name="name" value="<?php echo htmlspecialchars($newEmployeeForm['name']); ?>">
        </div>
        <div class="form-group">
            <label for="new_username">ログインID</label>
            <input type="text" id="new_username" name="username" placeholder="例: employee06" value="<?php echo htmlspecialchars($newEmployeeForm['username']); ?>">
        </div>
        <div class="form-group">
            <label for="new_password">初期パスワード</label>
            <input type="text" id="new_password" name="password" placeholder="例: password123">
        </div>
        <div class="form-group">
            <label for="new_position">担当可能業務・ポジション</label>
            <input type="text" id="new_position" name="position" placeholder="例: ホール, キッチン" value="<?php echo htmlspecialchars($newEmployeeForm['position']); ?>">
        </div>
        <div class="form-group">
            <label for="new_hire_date">入社日</label>
            <input type="date" id="new_hire_date" name="hire_date" value="<?php echo htmlspecialchars($newEmployeeForm['hire_date']); ?>">
        </div>
        <div class="form-group">
            <label for="new_skill_level">スキルレベル</label>
            <select id="new_skill_level" name="skill_level">
                <?php foreach (skillLevelOptions() as $level => $label): ?>
                <option value="<?php echo (int) $level; ?>" <?php echo ((string) $level === $newEmployeeForm['skill_level']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="new_note">備考</label>
            <textarea id="new_note" name="note" rows="3"><?php echo htmlspecialchars($newEmployeeForm['note']); ?></textarea>
        </div>
        <button type="submit" class="btn">登録する</button>
    </form>
</div>

<div class="section">
    <h2>従業員一覧</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>氏名</th>
                <th>ログインID</th>
                <th>メールアドレス</th>
                <th>電話番号</th>
                <th>入社日</th>
                <th>ポジション</th>
                <th>スキルレベル</th>
                <th>備考</th>
                <th>状態</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($employees as $emp): ?>
            <tr>
                <td><?php echo (int) $emp['id']; ?></td>
                <td><?php echo htmlspecialchars($emp['name']); ?></td>
                <td><?php echo htmlspecialchars($emp['username'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($emp['email'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($emp['phone'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($emp['hire_date'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($emp['position'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars(skillLevelLabel($emp['skill_level'] ?? null)); ?></td>
                <td><?php echo htmlspecialchars($emp['note'] ?? ''); ?></td>
                <td>
                    <?php if ((int) $emp['is_active'] === 1): ?>
                        <span class="badge badge-active">有効</span>
                    <?php else: ?>
                        <span class="badge badge-inactive">無効</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="table-actions">
                        <a class="btn btn-secondary" href="employees.php?edit=<?php echo (int) $emp['id']; ?>">編集</a>
                        <form method="post" action="employees.php">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="employee_id" value="<?php echo (int) $emp['id']; ?>">
                            <?php if ((int) $emp['is_active'] === 1): ?>
                                <button type="submit" class="btn btn-secondary">無効化</button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-secondary">有効化</button>
                            <?php endif; ?>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="section">
    <h2>従業員ごとの勤務可能日確認</h2>
    <p class="page-description">
        勤務可能日は従業員本人が登録します。店長はここで内容を確認できます。
    </p>
    <form class="form-group" method="get" action="employees.php">
        <label for="availability_employee_id">従業員で絞り込み</label>
        <select id="availability_employee_id" name="availability_employee_id" onchange="this.form.submit()">
            <option value="">すべて表示</option>
            <?php foreach ($employees as $emp): ?>
                <option value="<?php echo (int) $emp['id']; ?>" <?php echo $availabilityEmployeeId === (int) $emp['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($emp['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn-secondary">絞り込み</button></noscript>
    </form>

    <table>
        <thead>
            <tr>
                <th>従業員名</th>
                <th>勤務可能日</th>
                <th>開始時刻</th>
                <th>終了時刻</th>
                <th>備考</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($availabilityList)): ?>
            <tr>
                <td colspan="5">登録された勤務可能日はありません。</td>
            </tr>
            <?php else: ?>
                <?php foreach ($availabilityList as $a): ?>
                <tr>
                    <td><?php echo htmlspecialchars($a['employee_name']); ?></td>
                    <td><?php echo htmlspecialchars($a['available_date']); ?></td>
                    <td><?php echo htmlspecialchars(substr($a['start_time'], 0, 5)); ?></td>
                    <td><?php echo htmlspecialchars(substr($a['end_time'], 0, 5)); ?></td>
                    <td><?php echo htmlspecialchars($a['note'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
