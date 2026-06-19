<?php
/**
 * 代勤提案への応答画面（従業員用）
 *
 * - ログイン中の従業員本人に届いた代勤提案（substitute_candidates）のみを表示・回答できる
 * - 回答は response（available/unavailable）として substitute_candidates.status に保存する
 *   status: proposed=未回答, accepted=代勤可能と回答済み, declined=代勤不可と回答済み
 * - 「代勤可能」と回答した場合、店長へ確認用通知を作成する（substitute_matcher.php）
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('employee');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/services/substitute_matcher.php';
require_once __DIR__ . '/../../app/includes/status_labels.php';

$pageTitle = '代勤提案への応答';
$basePath  = '../../public/';

$user       = currentUser();
$employeeId = (int) $user['employee_id'];

$errorMessage   = '';
$successMessage = '';

// ------------------------------------------------------------
// POST処理（代勤可否の回答）
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'respond') {
    $candidateId = (int) ($_POST['candidate_id'] ?? 0);
    $response    = $_POST['response'] ?? '';

    if (!in_array($response, ['available', 'unavailable'], true)) {
        $errorMessage = '不正な回答内容です。';
    } else {
        $newStatus = ($response === 'available') ? 'accepted' : 'declined';

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT sc.id, sc.status AS candidate_status, lr.status AS leave_status
                 FROM substitute_candidates sc
                 JOIN leave_requests lr ON lr.id = sc.leave_request_id
                 WHERE sc.id = :id AND sc.candidate_employee_id = :employee_id
                 FOR UPDATE'
            );
            $stmt->execute([
                'id'          => $candidateId,
                'employee_id' => $employeeId,
            ]);
            $responseTarget = $stmt->fetch();

            if ($responseTarget === false) {
                $pdo->rollBack();
                $errorMessage = 'この代勤提案は見つかりません。';
            } elseif ($responseTarget['leave_status'] === 'cancelled') {
                $pdo->rollBack();
                $errorMessage = 'この代勤依頼は、休み申請がキャンセルされたため回答できません。';
            } elseif (
                !in_array($responseTarget['leave_status'], ['matching', 'no_candidate'], true)
                || $responseTarget['candidate_status'] !== 'proposed'
            ) {
                $pdo->rollBack();
                $errorMessage = 'この代勤提案は既に回答済みか、現在は回答できません。';
            } else {
                $pdo->prepare(
                    'UPDATE substitute_candidates
                     SET status = :status, responded_at = NOW()
                     WHERE id = :id'
                )->execute([
                    'status' => $newStatus,
                    'id'     => $candidateId,
                ]);

                if ($response === 'available') {
                    createCandidateAvailableNotification($pdo, $candidateId);
                }
                $pdo->commit();

                header('Location: candidate_response.php?candidate_id=' . $candidateId . '&msg=responded&result=' . $response);
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
if (isset($_GET['msg']) && $_GET['msg'] === 'responded') {
    $result = $_GET['result'] ?? '';
    if ($result === 'available') {
        $successMessage = '「代勤可能」で回答しました。店長に通知しました。';
    } elseif ($result === 'unavailable') {
        $successMessage = '「代勤不可」で回答しました。';
    } else {
        $successMessage = '回答を保存しました。';
    }
}

// ------------------------------------------------------------
// 対象の代勤提案を取得
//
// candidate_employee_id を条件に含めることで、URLのIDを改ざんしても
// 他人の代勤提案を閲覧・回答できないようにする。
// ------------------------------------------------------------
$candidateId = (int) ($_GET['candidate_id'] ?? 0);
$candidate   = null;

if ($candidateId > 0) {
    $stmt = $pdo->prepare(
        'SELECT sc.id, sc.status, sc.match_reason, sc.responded_at,
                lr.id AS leave_request_id, lr.reason AS leave_reason, lr.status AS leave_status,
                req.name AS requester_name,
                s.shift_date, s.start_time, s.end_time, s.position
         FROM substitute_candidates sc
         JOIN leave_requests lr ON lr.id = sc.leave_request_id
         JOIN shifts s ON s.id = lr.shift_id
         JOIN employees req ON req.id = lr.employee_id
         WHERE sc.id = :candidate_id AND sc.candidate_employee_id = :employee_id'
    );
    $stmt->execute(['candidate_id' => $candidateId, 'employee_id' => $employeeId]);
    $candidate = $stmt->fetch();
}

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    以下のシフトについて、代勤として対応できるかご回答ください。
</p>

<div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
<div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>

<?php if ($candidateId <= 0 || $candidate === false || $candidate === null): ?>

<div class="section">
    <p class="page-description">指定された代勤提案が見つかりません。すでに対応済みか、URLが正しくない可能性があります。</p>
    <a class="btn btn-secondary" href="notifications.php">通知確認に戻る</a>
</div>

<?php else: ?>

<div class="section">
    <table>
        <tbody>
            <tr>
                <th>休み申請者</th>
                <td><?php echo htmlspecialchars($candidate['requester_name']); ?></td>
            </tr>
            <tr>
                <th>勤務日</th>
                <td><?php echo htmlspecialchars($candidate['shift_date']); ?></td>
            </tr>
            <tr>
                <th>開始時刻</th>
                <td><?php echo htmlspecialchars(substr($candidate['start_time'], 0, 5)); ?></td>
            </tr>
            <tr>
                <th>終了時刻</th>
                <td><?php echo htmlspecialchars(substr($candidate['end_time'], 0, 5)); ?></td>
            </tr>
            <tr>
                <th>担当業務・ポジション</th>
                <td><?php echo htmlspecialchars($candidate['position'] ?? ''); ?></td>
            </tr>
            <tr>
                <th>申請理由</th>
                <td><?php echo htmlspecialchars($candidate['leave_reason'] ?? ''); ?></td>
            </tr>
            <?php if (!empty($candidate['match_reason'])): ?>
            <tr>
                <th>マッチ理由</th>
                <td><?php echo htmlspecialchars($candidate['match_reason']); ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <th>回答状況</th>
                <td><?php echo renderStatusBadge(candidateStatusLabel($candidate['status']), candidateStatusBadgeClass($candidate['status'])); ?></td>
            </tr>
        </tbody>
    </table>
</div>

<div class="section">
    <?php if ($candidate['leave_status'] === 'cancelled'): ?>
    <p class="page-description">この代勤依頼は、休み申請がキャンセルされたため回答できません。</p>
    <?php elseif ($candidate['status'] === 'expired'): ?>
    <p class="page-description">この代勤依頼は無効になっているため、回答は不要です。</p>
    <?php elseif (
        $candidate['status'] === 'proposed'
        && in_array($candidate['leave_status'], ['matching', 'no_candidate'], true)
    ): ?>
    <form method="post" action="candidate_response.php" style="display:inline;">
        <input type="hidden" name="action" value="respond">
        <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate['id']; ?>">
        <input type="hidden" name="response" value="available">
        <button type="submit" class="btn">代勤可能</button>
    </form>
    <form method="post" action="candidate_response.php" style="display:inline;">
        <input type="hidden" name="action" value="respond">
        <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate['id']; ?>">
        <input type="hidden" name="response" value="unavailable">
        <button type="submit" class="btn btn-secondary">代勤不可</button>
    </form>
    <?php elseif (in_array($candidate['status'], ['accepted', 'declined'], true)): ?>
    <p class="page-description">この提案には既に回答済みです（再回答はできません）。</p>
    <?php else: ?>
    <p class="page-description">この代勤依頼は既に処理済みのため、回答できません。</p>
    <?php endif; ?>
</div>

<div class="section">
    <a class="btn btn-secondary" href="notifications.php">通知確認に戻る</a>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
