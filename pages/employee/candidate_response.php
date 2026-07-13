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
                'SELECT sc.id, sc.leave_request_id, sc.status AS candidate_status, sc.notified_at, lr.status AS leave_status
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
            } elseif (!in_array($responseTarget['leave_status'], ['matching', 'no_candidate', 'replacement_pending'], true)) {
                $pdo->rollBack();
                $errorMessage = 'この代勤提案は現在回答できません。';
            } elseif ($responseTarget['notified_at'] === null) {
                $pdo->rollBack();
                $errorMessage = 'この代勤提案はまだ通知されていないため、回答できません。';
            } elseif (
                $response === 'available'
                && $responseTarget['candidate_status'] !== 'proposed'
            ) {
                $pdo->rollBack();
                $errorMessage = 'この代勤提案は既に回答済みです。';
            } elseif (
                $response === 'unavailable'
                && !in_array($responseTarget['candidate_status'], ['proposed', 'accepted'], true)
            ) {
                $pdo->rollBack();
                $errorMessage = 'この代勤提案は既に処理済みです。';
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
                } else {
                    // 代勤不可回答、または店長承認前の代勤可能回答キャンセル時は、辞退者を除外して再抽出する。
                    $leaveRequestId = (int) $responseTarget['leave_request_id'];
                    $retryResult = retrySubstituteMatching($pdo, $leaveRequestId, [$employeeId], 'candidate_declined');

                    if ($retryResult['no_candidate']) {
                        $pdo->prepare("UPDATE leave_requests SET status = 'no_candidate' WHERE id = :id AND status IN ('matching', 'no_candidate')")
                            ->execute(['id' => $leaveRequestId]);
                    } elseif ($responseTarget['leave_status'] === 'no_candidate') {
                        $pdo->prepare("UPDATE leave_requests SET status = 'matching' WHERE id = :id")
                            ->execute(['id' => $leaveRequestId]);
                    }
                }
                $pdo->commit();

                $result = ($response === 'unavailable' && $responseTarget['candidate_status'] === 'accepted')
                    ? 'cancelled_before_approval'
                    : $response;
                header('Location: candidate_response.php?candidate_id=' . $candidateId . '&msg=responded&result=' . $result);
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
    } elseif ($result === 'cancelled_before_approval') {
        $successMessage = '店長承認前の代勤可能回答をキャンセルしました。別の代勤候補を再抽出しました。';
    } elseif ($result === 'unavailable') {
        $successMessage = '「代勤不可」で回答しました。別の代勤候補を再抽出しました。';
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
         WHERE sc.id = :candidate_id
           AND sc.candidate_employee_id = :employee_id
           AND sc.notified_at IS NOT NULL'
    );
    $stmt->execute(['candidate_id' => $candidateId, 'employee_id' => $employeeId]);
    $candidate = $stmt->fetch();
}

// この画面は通知確認画面から遷移してくるため、「戻る」先は通知確認画面にする
$backUrl = 'notifications.php';

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
    <article class="candidate-response-card">
        <div class="manager-work-card-header">
            <div>
                <h2><?php echo htmlspecialchars($candidate['shift_date']); ?> の代勤依頼</h2>
                <p class="approval-shift-summary">
                    <?php echo htmlspecialchars(substr($candidate['start_time'], 0, 5)); ?>〜<?php echo htmlspecialchars(substr($candidate['end_time'], 0, 5)); ?>
                    <?php if (!empty($candidate['position'])): ?>
                        ・<?php echo htmlspecialchars($candidate['position']); ?>
                    <?php endif; ?>
                </p>
            </div>
            <?php echo renderStatusBadge(candidateStatusLabel($candidate['status']), candidateStatusBadgeClass($candidate['status'])); ?>
        </div>

        <div class="manager-detail-grid">
            <div><span>休み申請者</span><strong><?php echo htmlspecialchars($candidate['requester_name']); ?></strong></div>
            <div><span>勤務日</span><strong><?php echo htmlspecialchars($candidate['shift_date']); ?></strong></div>
            <div><span>時間</span><strong><?php echo htmlspecialchars(substr($candidate['start_time'], 0, 5)); ?>〜<?php echo htmlspecialchars(substr($candidate['end_time'], 0, 5)); ?></strong></div>
            <div><span>担当業務</span><strong><?php echo htmlspecialchars($candidate['position'] ?? '-'); ?></strong></div>
        </div>

        <div class="candidate-response-note">
            <span>申請理由</span>
            <p><?php echo nl2br(htmlspecialchars($candidate['leave_reason'] ?? '-')); ?></p>
        </div>

        <?php if (!empty($candidate['match_reason'])): ?>
        <div class="candidate-response-note">
            <span>マッチ理由</span>
            <p><?php echo htmlspecialchars($candidate['match_reason']); ?></p>
        </div>
        <?php endif; ?>

        <div class="manager-card-actions">
    <?php if ($candidate['leave_status'] === 'cancelled'): ?>
            <p class="page-description">この代勤依頼は、休み申請がキャンセルされたため回答できません。</p>
    <?php elseif ($candidate['status'] === 'expired'): ?>
            <p class="page-description">この代勤依頼は無効になっているため、回答は不要です。</p>
    <?php elseif (
        $candidate['status'] === 'proposed'
        && in_array($candidate['leave_status'], ['matching', 'no_candidate', 'replacement_pending'], true)
    ): ?>
            <form method="post" action="candidate_response.php">
                <input type="hidden" name="action" value="respond">
                <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate['id']; ?>">
                <input type="hidden" name="response" value="available">
                <button type="submit" class="btn">代勤可能</button>
            </form>
            <form method="post" action="candidate_response.php">
                <input type="hidden" name="action" value="respond">
                <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate['id']; ?>">
                <input type="hidden" name="response" value="unavailable">
                <button type="submit" class="btn btn-secondary">代勤不可</button>
            </form>
    <?php elseif (
        $candidate['status'] === 'accepted'
        && in_array($candidate['leave_status'], ['matching', 'no_candidate', 'replacement_pending'], true)
    ): ?>
            <p class="page-description">代勤可能で回答済みです。店長が承認する前であればキャンセルできます。</p>
            <form method="post" action="candidate_response.php" onsubmit="return confirm('店長承認前の代勤可能回答をキャンセルします。よろしいですか？');">
                <input type="hidden" name="action" value="respond">
                <input type="hidden" name="candidate_id" value="<?php echo (int) $candidate['id']; ?>">
                <input type="hidden" name="response" value="unavailable">
                <button type="submit" class="btn btn-secondary">代勤をキャンセル</button>
            </form>
    <?php elseif (in_array($candidate['status'], ['accepted', 'declined'], true)): ?>
            <p class="page-description">この提案には既に回答済みです（再回答はできません）。</p>
    <?php else: ?>
            <p class="page-description">この代勤依頼は既に処理済みのため、回答できません。</p>
    <?php endif; ?>
        </div>
    </article>
</div>

<div class="section">
    <a class="btn btn-secondary" href="notifications.php">通知確認に戻る</a>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
