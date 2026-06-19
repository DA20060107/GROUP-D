<?php
/**
 * 代勤候補抽出モード設定画面（店長用）
 *
 * - 店長が現在の代勤候補抽出モード（matching_settings.current_matching_mode）を
 *   「通常」「人員確保優先」「スキル重視」の3つから選択・保存する
 * - 保存したモードは、休み申請登録時の代勤候補抽出（processSubstituteMatching()）で使用される
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/services/substitute_matcher.php';

$pageTitle = '代勤候補抽出設定';
$basePath  = '../../public/';

$errorMessage   = '';
$successMessage = '';

// ------------------------------------------------------------
// POST処理（抽出モードの保存）
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_mode') {
    $mode = $_POST['matching_mode'] ?? '';

    if (!in_array($mode, getMatchingModes(), true)) {
        $errorMessage = '不正な抽出モードが指定されました。';
    } else {
        setCurrentMatchingMode($pdo, $mode);
        header('Location: matching_settings.php?msg=updated');
        exit;
    }
}

// ------------------------------------------------------------
// 完了メッセージ（リダイレクト後）
// ------------------------------------------------------------
if (isset($_GET['msg']) && $_GET['msg'] === 'updated') {
    $successMessage = '代勤候補抽出モードを更新しました。';
}

$currentMode = getCurrentMatchingMode($pdo);

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    休み申請時の代勤候補抽出で使用する抽出モードを設定します。設定したモードは、
    以後の休み申請に対する候補者のスコア計算に使用されます。
</p>

<div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
<div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>

<div class="section">
    <h2>現在の抽出モード</h2>
    <p class="page-description">
        現在の抽出モード：<strong><?php echo htmlspecialchars(getMatchingModeLabel($currentMode)); ?></strong>
    </p>

    <form method="post" action="matching_settings.php">
        <input type="hidden" name="action" value="update_mode">
        <table>
            <thead>
                <tr>
                    <th>選択</th>
                    <th>モード</th>
                    <th>説明</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (getMatchingModes() as $mode): ?>
                <tr>
                    <td>
                        <input type="radio" id="mode_<?php echo htmlspecialchars($mode); ?>" name="matching_mode"
                               value="<?php echo htmlspecialchars($mode); ?>"
                               <?php echo $mode === $currentMode ? 'checked' : ''; ?>>
                    </td>
                    <td>
                        <label for="mode_<?php echo htmlspecialchars($mode); ?>">
                            <?php echo htmlspecialchars(getMatchingModeLabel($mode)); ?>
                        </label>
                    </td>
                    <td><?php echo htmlspecialchars(getMatchingModeDescription($mode)); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <button type="submit" class="btn">この内容で保存する</button>
    </form>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
