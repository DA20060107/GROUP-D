<?php
/**
 * 代勤候補の手動再抽出（店長用・POST処理専用）
 *
 * - 店長が no_candidate / replacement_pending の休み申請に対して、
 *   代勤候補を手動で再抽出する
 * - 画面表示は行わず、処理後は承認画面（approvals.php）へリダイレクトする
 *
 * 状態別の扱い:
 *   no_candidate        : 候補者が見つかれば matching に戻す。見つからなければ no_candidate を維持
 *   replacement_pending : 候補者の有無にかかわらず replacement_pending を維持
 *                         （新しい代勤者は店長承認時に確定するため）
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');
require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/services/substitute_matcher.php';

// POST以外はそのまま承認画面へ戻す
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'rematch') {
    header('Location: approvals.php');
    exit;
}

$leaveRequestId = (int) ($_POST['leave_request_id'] ?? 0);

if ($leaveRequestId <= 0) {
    header('Location: approvals.php?msg=rematch_error');
    exit;
}

$pdo->beginTransaction();
try {
    // 対象の休み申請とシフトを行ロックして取得する
    $stmt = $pdo->prepare(
        'SELECT lr.id, lr.status AS leave_status, s.id AS shift_id, s.employee_id AS current_shift_employee_id
         FROM leave_requests lr
         JOIN shifts s ON s.id = lr.shift_id
         WHERE lr.id = :id
         FOR UPDATE'
    );
    $stmt->execute(['id' => $leaveRequestId]);
    $target = $stmt->fetch();

    if ($target === false) {
        $pdo->rollBack();
        header('Location: approvals.php?msg=rematch_error');
        exit;
    }

    // 再抽出できるのは no_candidate / replacement_pending のみ
    if (!in_array($target['leave_status'], ['no_candidate', 'replacement_pending'], true)) {
        $pdo->rollBack();
        header('Location: approvals.php?msg=rematch_invalid#lr-' . $leaveRequestId);
        exit;
    }

    // 現在のシフト担当者を除外する
    //   no_candidate        : 休み申請者本人（= 現在の担当者）。retry 内でも除外されるが念のため
    //   replacement_pending : キャンセルした代勤者（シフト担当者のまま残っている）
    $excludeIds = [(int) $target['current_shift_employee_id']];

    $result = retrySubstituteMatching($pdo, $leaveRequestId, $excludeIds, 'manual');

    // no_candidate で候補者が見つかった場合のみ matching に戻す。
    // replacement_pending は候補者の有無にかかわらず状態を維持する。
    if ($target['leave_status'] === 'no_candidate' && !$result['no_candidate']) {
        $pdo->prepare("UPDATE leave_requests SET status = 'matching' WHERE id = :id")
            ->execute(['id' => $leaveRequestId]);
    }

    $pdo->commit();

    $msg = $result['no_candidate'] ? 'rematch_none' : 'rematch_found';
    header('Location: approvals.php?msg=' . $msg . '#lr-' . $leaveRequestId);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}
