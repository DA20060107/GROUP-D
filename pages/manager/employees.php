<?php
/**
 * 従業員情報管理画面（店長用）
 *
 * - 従業員一覧の表示・新規登録（ログインアカウント同時作成）・編集・削除
 *
 * 削除時の注意：
 *   employees の削除に伴い、外部キー制約（ON DELETE CASCADE）により
 *   当該従業員の shifts / availability / leave_requests（申請者として） /
 *   substitute_candidates（代勤候補として） / cancellation_requests（申請者として）
 *   も連動して削除される。ログインアカウント（users）は ON DELETE SET NULL のため、
 *   宙に浮いたアカウントが残らないよう、本画面側で明示的に削除している。
 *
 *   休み申請・代勤候補・代勤承認・キャンセル申請など、他の従業員と関連するデータが
 *   1件でも残っている場合は、削除すると他の従業員の履歴まで巻き添えで消えてしまうため、
 *   getEmployeeDeletionBlockers() の判定により削除をブロックする（一覧画面ではボタンが
 *   削除できない理由の詳細表示に変わる。サーバー側でも
 *   同じ判定を行い、直接POSTされた場合でもブロックする）。
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/includes/status_labels.php';
require_once __DIR__ . '/../../app/includes/position_helpers.php';

$pageTitle = '従業員情報管理';
$basePath  = '../../public/';

/**
 * 従業員削除をブロックすべき理由の一覧を返す（空配列なら削除可能）
 *
 * employees の削除は外部キー制約（ON DELETE CASCADE）により、
 * shifts / availability / leave_requests / substitute_candidates /
 * cancellation_requests を連動して削除してしまう。
 * これらのテーブルに、他の従業員と関連するデータ（休み申請・代勤候補・
 * 代勤承認・キャンセル申請・代勤中のシフトなど）が残っている場合は、
 * 削除すると他の従業員の履歴まで巻き添えで消えてしまうため、削除をブロックする。
 *
 * @return string[] ブロック理由の一覧（日本語の説明文）
 */
function getEmployeeDeletionBlockers(PDO $pdo, int $employeeId): array
{
    $reasons = [];

    // 本人が提出した休み申請（どの状態でも、関連する代勤候補・承認・キャンセル申請が
    // 連鎖削除され、他の従業員の履歴に影響するためブロックする）
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM leave_requests WHERE employee_id = :id');
    $stmt->execute(['id' => $employeeId]);
    $count = (int) $stmt->fetchColumn();
    if ($count > 0) {
        $reasons[] = "本人が提出した休み申請の履歴が{$count}件あります（関連する代勤候補・承認記録も削除されます）";
    }

    // 他の従業員の休み申請に対する代勤候補・代勤対応の記録（未回答・回答済み・無効化済みを問わず）
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM substitute_candidates WHERE candidate_employee_id = :id');
    $stmt->execute(['id' => $employeeId]);
    $count = (int) $stmt->fetchColumn();
    if ($count > 0) {
        $reasons[] = "他の従業員の休み申請に対する代勤候補・代勤対応の記録が{$count}件あります";
    }

    // 承認後キャンセル申請（休み申請者側・代勤者側どちらも requested_by_employee_id で判定）
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM cancellation_requests WHERE requested_by_employee_id = :id');
    $stmt->execute(['id' => $employeeId]);
    $count = (int) $stmt->fetchColumn();
    if ($count > 0) {
        $reasons[] = "代勤・休み申請のキャンセル申請履歴が{$count}件あります";
    }

    // 現在、他の従業員の休み申請の代わりに勤務しているシフト（代勤中・代勤者再調整中）
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM shifts s
         JOIN leave_requests lr ON lr.shift_id = s.id
         WHERE s.employee_id = :id
           AND lr.employee_id <> :id2
           AND lr.status IN ("approved", "replacement_pending")'
    );
    $stmt->execute(['id' => $employeeId, 'id2' => $employeeId]);
    $count = (int) $stmt->fetchColumn();
    if ($count > 0) {
        $reasons[] = "現在、他の従業員の代わりに勤務している代勤シフトが{$count}件あります";
    }

    return $reasons;
}

$errorMessage   = '';
$successMessage = '';

// 編集フォームに表示する従業員データ（再表示用）
$editEmployee = null;

// 入力エラーや絞り込み後に自動で開くモーダル
$initialManagerModalDetail = '';
$initialManagerModalTitle = '';

// 新規登録フォームの再表示用データ
$newEmployeeForm = [
    'name'        => '',
    'username'    => '',
    'email'       => '',
    'phone'       => '',
    'position'    => '',
    'note'        => '',
    'hire_date'   => '',
    'skill_level' => '3',
];
$positionCheckboxOptions = positionPresetOptions();

// ------------------------------------------------------------
// POST処理
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_employee') {
        $name        = trim($_POST['name'] ?? '');
        $username    = trim($_POST['username'] ?? '');
        $password    = (string) ($_POST['password'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $position    = buildPositionValue($_POST['position_options'] ?? [], '');
        $note        = trim($_POST['note'] ?? '');
        $hireDate    = trim($_POST['hire_date'] ?? '');
        $skillLevel  = trim($_POST['skill_level'] ?? '3');

        // 入力エラー時に再表示するためのフォーム値
        $newEmployeeForm = [
            'name'        => $name,
            'username'    => $username,
            'email'       => $email,
            'phone'       => $phone,
            'position'    => $position,
            'note'        => $note,
            'hire_date'   => $hireDate,
            'skill_level' => $skillLevel,
        ];

        if ($name === '' || $username === '' || $password === '') {
            $errorMessage = '氏名・ログインID・初期パスワードは必須です。';
            $initialManagerModalDetail = 'employee-create-form-detail';
            $initialManagerModalTitle = '従業員の新規登録';
        } elseif (!in_array($skillLevel, ['1', '2', '3', '4', '5'], true)) {
            $errorMessage = 'スキルレベルは1〜5の範囲で指定してください。';
            $initialManagerModalDetail = 'employee-create-form-detail';
            $initialManagerModalTitle = '従業員の新規登録';
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
            $stmt->execute(['username' => $username]);

            if ((int) $stmt->fetchColumn() > 0) {
                $errorMessage = 'このログインIDは既に使用されています。別のログインIDを指定してください。';
                $initialManagerModalDetail = 'employee-create-form-detail';
                $initialManagerModalTitle = '従業員の新規登録';
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare(
                        'INSERT INTO employees (name, email, phone, position, note, hire_date, skill_level)
                         VALUES (:name, :email, :phone, :position, :note, :hire_date, :skill_level)'
                    );
                    $stmt->execute([
                        'name'        => $name,
                        'email'       => $email !== '' ? $email : null,
                        'phone'       => $phone !== '' ? $phone : null,
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
                    $initialManagerModalDetail = 'employee-create-form-detail';
                    $initialManagerModalTitle = '従業員の新規登録';
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
        $position   = buildPositionValue($_POST['position_options'] ?? [], '');
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
    } elseif ($action === 'delete_employee') {
        $id = (int) ($_POST['employee_id'] ?? 0);

        $stmt = $pdo->prepare('SELECT id, name FROM employees WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $target = $stmt->fetch();

        if ($target === false) {
            $errorMessage = '指定された従業員が見つかりません。';
        } else {
            // サーバー側でも必ず判定する（画面側のJS警告はあくまで補助であり、
            // ここでブロックしないと直接POSTされた場合に削除が通ってしまうため）。
            $blockers = getEmployeeDeletionBlockers($pdo, $id);

            if (!empty($blockers)) {
                $errorMessage = $target['name'] . 'さんは削除できません。他の従業員と関連するデータがあるため、削除すると影響が及びます：'
                    . implode(' / ', $blockers);
            } else {
                try {
                    $pdo->beginTransaction();

                    // ログインアカウントを先に削除する（employees側のON DELETE SET NULLだけでは
                    // ログインIDが宙に浮いた users レコードが残ってしまうため）。
                    $pdo->prepare("DELETE FROM users WHERE employee_id = :id AND role = 'employee'")
                        ->execute(['id' => $id]);

                    // 従業員本体を削除する。この時点で関連データがないことは確認済みのため、
                    // 削除されるのは本人自身の availability など、他者に影響しないデータのみ。
                    $pdo->prepare('DELETE FROM employees WHERE id = :id')
                        ->execute(['id' => $id]);

                    $pdo->commit();

                    header('Location: employees.php?msg=deleted');
                    exit;
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $errorMessage = '削除に失敗しました。エラー詳細: ' . $e->getMessage();
                }
            }
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
        case 'deleted':
            $successMessage = '従業員を削除しました。';
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

// 編集URL、または編集POSTの入力エラー時は、編集フォームだけを表示する
$isEditMode = isset($_GET['edit'])
    || $editEmployee !== null
    || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_employee');

// ------------------------------------------------------------
// 従業員一覧（ログインIDを併せて表示）
// ------------------------------------------------------------
$employees = [];
if (!$isEditMode) {
    $employees = $pdo->query(
        'SELECT e.*, u.username
         FROM employees e
         LEFT JOIN users u ON u.employee_id = e.id AND u.role = "employee"
         ORDER BY e.id'
    )->fetchAll();
}

// 編集フォーム表示中の「戻る」先は、一つ前の画面である従業員一覧（このページの一覧表示）にする
if ($isEditMode) {
    $backUrl = 'employees.php';
}

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
    <?php if ($isEditMode): ?>
    従業員の基本情報とログインアカウントを編集します。
    <?php else: ?>
    従業員の基本情報・ログインアカウントを管理します。
    <?php endif; ?>
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
            <?php $editPositionItems = parsePositionItems($editEmployee['position'] ?? ''); ?>
            <div class="position-checkbox-group">
                <?php foreach ($positionCheckboxOptions as $positionOption): ?>
                <label class="position-checkbox-item">
                    <input type="checkbox" name="position_options[]" value="<?php echo htmlspecialchars($positionOption); ?>" <?php echo in_array($positionOption, $editPositionItems, true) ? 'checked' : ''; ?>>
                    <span><?php echo htmlspecialchars($positionOption); ?></span>
                </label>
                <?php endforeach; ?>
            </div>
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

<?php if (!$isEditMode): ?>
<div class="section employee-management-panel">
    <div class="employee-management-header">
        <div>
            <h2>従業員一覧</h2>
        </div>
        <div class="employee-management-actions">
            <button type="button" class="btn" data-manager-detail="employee-create-form-detail" data-manager-title="従業員の新規登録">＋ 従業員を登録</button>
        </div>
    </div>

    <div class="employee-card-list">
        <?php foreach ($employees as $emp): ?>
        <?php
            $detailId = 'employee-detail-' . (int) $emp['id'];
            $deleteBlockers = getEmployeeDeletionBlockers($pdo, (int) $emp['id']);
            $blockerId = 'employee-delete-blockers-' . (int) $emp['id'];
        ?>
        <article
            class="employee-card employee-card-clickable"
            role="button"
            tabindex="0"
            data-manager-card-detail="<?php echo htmlspecialchars($detailId); ?>"
            data-manager-card-title="従業員詳細"
        >
            <div class="employee-card-main">
                <div class="employee-card-summary">
                    <h3><?php echo htmlspecialchars($emp['name']); ?></h3>
                    <div class="employee-card-meta">
                        <span>ログインID：<?php echo htmlspecialchars($emp['username'] ?? '-'); ?></span>
                        <span>ポジション：<?php echo htmlspecialchars($emp['position'] ?? '-'); ?></span>
                        <span>スキル：<?php echo htmlspecialchars(skillLevelLabel($emp['skill_level'] ?? null)); ?></span>
                    </div>
                </div>
            </div>
            <div class="employee-card-actions">
                <a class="employee-icon-button" href="employees.php?edit=<?php echo (int) $emp['id']; ?>" aria-label="<?php echo htmlspecialchars($emp['name']); ?>さんを編集" title="編集">✎</a>
                <?php if (empty($deleteBlockers)): ?>
                <form method="post" action="employees.php"
                      onsubmit="return confirm('<?php echo htmlspecialchars($emp['name'], ENT_QUOTES); ?>さんを削除しますか？この操作は取り消せません。');">
                    <input type="hidden" name="action" value="delete_employee">
                    <input type="hidden" name="employee_id" value="<?php echo (int) $emp['id']; ?>">
                    <button type="submit" class="employee-icon-button employee-icon-button-danger" aria-label="<?php echo htmlspecialchars($emp['name']); ?>さんを削除" title="削除">🗑</button>
                </form>
                <?php else: ?>
                <button type="button" class="employee-icon-button employee-icon-button-danger" data-manager-detail="<?php echo htmlspecialchars($blockerId); ?>" data-manager-title="削除できない理由" aria-label="<?php echo htmlspecialchars($emp['name']); ?>さんを削除" title="削除">🗑</button>
                <?php endif; ?>
            </div>
        </article>

        <div id="<?php echo htmlspecialchars($detailId); ?>" class="notification-detail-source" hidden>
            <table>
                <tbody>
                    <tr><th>ID</th><td><?php echo (int) $emp['id']; ?></td></tr>
                    <tr><th>氏名</th><td><?php echo htmlspecialchars($emp['name']); ?></td></tr>
                    <tr><th>ログインID</th><td><?php echo htmlspecialchars($emp['username'] ?? '-'); ?></td></tr>
                    <tr><th>メールアドレス</th><td><?php echo htmlspecialchars($emp['email'] ?? '-'); ?></td></tr>
                    <tr><th>電話番号</th><td><?php echo htmlspecialchars($emp['phone'] ?? '-'); ?></td></tr>
                    <tr><th>入社日</th><td><?php echo htmlspecialchars($emp['hire_date'] ?? '-'); ?></td></tr>
                    <tr><th>担当可能業務・ポジション</th><td><?php echo htmlspecialchars($emp['position'] ?? '-'); ?></td></tr>
                    <tr><th>スキルレベル</th><td><?php echo htmlspecialchars(skillLevelLabel($emp['skill_level'] ?? null)); ?></td></tr>
                    <tr><th>備考</th><td><?php echo nl2br(htmlspecialchars($emp['note'] ?? '-')); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php if (!empty($deleteBlockers)): ?>
        <div id="<?php echo htmlspecialchars($blockerId); ?>" class="notification-detail-source" hidden>
            <p><?php echo htmlspecialchars($emp['name']); ?>さんは削除できません。他の従業員の記録に影響するため、削除をブロックしています。</p>
            <ul>
                <?php foreach ($deleteBlockers as $reason): ?>
                <li><?php echo htmlspecialchars($reason); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<div id="employee-create-form-detail" class="notification-detail-source" hidden>
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
            <label for="new_email">メールアドレス</label>
            <input type="email" id="new_email" name="email" placeholder="例: yamada@example.com" value="<?php echo htmlspecialchars($newEmployeeForm['email']); ?>">
        </div>
        <div class="form-group">
            <label for="new_phone">電話番号</label>
            <input type="text" id="new_phone" name="phone" placeholder="例: 090-1234-5678" value="<?php echo htmlspecialchars($newEmployeeForm['phone']); ?>">
        </div>
        <div class="form-group">
            <label for="new_position">担当可能業務・ポジション</label>
            <?php $newPositionItems = parsePositionItems($newEmployeeForm['position']); ?>
            <div class="position-checkbox-group">
                <?php foreach ($positionCheckboxOptions as $positionOption): ?>
                <label class="position-checkbox-item">
                    <input type="checkbox" name="position_options[]" value="<?php echo htmlspecialchars($positionOption); ?>" <?php echo in_array($positionOption, $newPositionItems, true) ? 'checked' : ''; ?>>
                    <span><?php echo htmlspecialchars($positionOption); ?></span>
                </label>
                <?php endforeach; ?>
            </div>
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

    const openManagerModal = function (detailId, title) {
        const detail = document.getElementById(detailId);
        modal.querySelector('[data-manager-modal-title]').textContent = title || '詳細';
        modal.querySelector('[data-manager-modal-body]').innerHTML = detail ? detail.innerHTML : '';
        modal.hidden = false;
        document.body.classList.add('calendar-modal-open');
    };

    if (event.target.closest('[data-manager-modal-close]')) {
        modal.hidden = true;
        document.body.classList.remove('calendar-modal-open');
        return;
    }

    const button = event.target.closest('[data-manager-detail]');
    if (button) {
        openManagerModal(button.dataset.managerDetail, button.dataset.managerTitle || '詳細');
        return;
    }

    if (event.target.closest('a, button, input, select, textarea, form, label')) {
        return;
    }

    const card = event.target.closest('[data-manager-card-detail]');
    if (card) {
        openManagerModal(card.dataset.managerCardDetail, card.dataset.managerCardTitle || '詳細');
    }
});

document.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' && event.key !== ' ') {
        return;
    }

    const card = event.target.closest('[data-manager-card-detail]');
    if (!card || event.target.closest('a, button, input, select, textarea, form, label')) {
        return;
    }

    event.preventDefault();
    const modal = document.querySelector('[data-manager-modal]');
    if (!modal) {
        return;
    }

    const detail = document.getElementById(card.dataset.managerCardDetail);
    modal.querySelector('[data-manager-modal-title]').textContent = card.dataset.managerCardTitle || '詳細';
    modal.querySelector('[data-manager-modal-body]').innerHTML = detail ? detail.innerHTML : '';
    modal.hidden = false;
    document.body.classList.add('calendar-modal-open');
});

document.addEventListener('DOMContentLoaded', function () {
    const modal = document.querySelector('[data-manager-modal]');
    const initialDetailId = <?php echo json_encode($initialManagerModalDetail, JSON_UNESCAPED_UNICODE); ?>;
    const initialTitle = <?php echo json_encode($initialManagerModalTitle, JSON_UNESCAPED_UNICODE); ?>;

    if (!modal || !initialDetailId) {
        return;
    }

    const detail = document.getElementById(initialDetailId);
    modal.querySelector('[data-manager-modal-title]').textContent = initialTitle || '詳細';
    modal.querySelector('[data-manager-modal-body]').innerHTML = detail ? detail.innerHTML : '';
    modal.hidden = false;
    document.body.classList.add('calendar-modal-open');
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
