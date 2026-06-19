<?php
/**
 * 承認後キャンセル申請サービス
 *
 * 現在は、休み申請者本人が「承認済みの休み申請を取り消して出勤へ戻る」
 * requester_after_approval のみを扱う。
 * 将来の代勤者側キャンセルは request_type=substitute_after_approval として
 * 同じテーブルへ追加できるよう、申請種別を分離して保存する。
 */

require_once __DIR__ . '/substitute_matcher.php';

const CANCELLATION_TYPE_REQUESTER_AFTER_APPROVAL = 'requester_after_approval';

/**
 * 店長全員へ通知を作成する
 */
function insertNotificationForManagers(
    PDO $pdo,
    string $type,
    string $title,
    string $message,
    int $leaveRequestId
): void {
    $stmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, type, title, message, is_read, related_leave_request_id)
         SELECT id, :type, :title, :message, 0, :leave_request_id
         FROM users
         WHERE role = 'manager'"
    );
    $stmt->execute([
        'type'             => $type,
        'title'            => $title,
        'message'          => $message,
        'leave_request_id' => $leaveRequestId,
    ]);
}

/**
 * 休み申請者本人が、承認済み休み申請のキャンセル申請を作成する
 *
 * leave_requests 行をロックしてから pending の重複を確認するため、
 * 同時送信でも同じ休み申請に pending が複数作られない。
 *
 * @return string created / not_found / not_eligible / already_pending
 */
function createAfterApprovalCancellationRequest(
    PDO $pdo,
    int $leaveRequestId,
    int $employeeId,
    ?string $reason
): string {
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT lr.id, lr.status AS leave_status, lr.employee_id AS requester_employee_id,
                    s.id AS shift_id, s.employee_id AS current_shift_employee_id,
                    s.status AS shift_status, s.shift_date, s.start_time, s.end_time
             FROM leave_requests lr
             JOIN shifts s ON s.id = lr.shift_id
             WHERE lr.id = :leave_request_id AND lr.employee_id = :employee_id
             FOR UPDATE'
        );
        $stmt->execute([
            'leave_request_id' => $leaveRequestId,
            'employee_id'      => $employeeId,
        ]);
        $target = $stmt->fetch();

        if ($target === false) {
            $pdo->rollBack();
            return 'not_found';
        }

        if (
            $target['leave_status'] !== 'approved'
            || $target['shift_status'] !== 'substituted'
            || (int) $target['current_shift_employee_id'] === $employeeId
        ) {
            $pdo->rollBack();
            return 'not_eligible';
        }

        $stmt = $pdo->prepare(
            "SELECT id
             FROM cancellation_requests
             WHERE leave_request_id = :leave_request_id
               AND request_type = :request_type
               AND status = 'pending'
             LIMIT 1"
        );
        $stmt->execute([
            'leave_request_id' => $leaveRequestId,
            'request_type'     => CANCELLATION_TYPE_REQUESTER_AFTER_APPROVAL,
        ]);

        if ($stmt->fetch() !== false) {
            $pdo->rollBack();
            return 'already_pending';
        }

        $stmt = $pdo->prepare(
            "INSERT INTO cancellation_requests
                (leave_request_id, request_type, requested_by_employee_id, reason, status)
             VALUES
                (:leave_request_id, :request_type, :employee_id, :reason, 'pending')"
        );
        $stmt->execute([
            'leave_request_id' => $leaveRequestId,
            'request_type'     => CANCELLATION_TYPE_REQUESTER_AFTER_APPROVAL,
            'employee_id'      => $employeeId,
            'reason'           => $reason !== null && $reason !== '' ? $reason : null,
        ]);

        $shiftDateLabel = date('n月j日', strtotime($target['shift_date']));
        $message = sprintf(
            '%sさんから、%sの%s〜%sの承認済み休み申請についてキャンセル申請が届いています。',
            currentEmployeeName($pdo, $employeeId),
            $shiftDateLabel,
            substr($target['start_time'], 0, 5),
            substr($target['end_time'], 0, 5)
        );
        insertNotificationForManagers(
            $pdo,
            'after_approval_cancel_requested',
            '承認後キャンセル申請が届いています',
            $message,
            $leaveRequestId
        );

        $pdo->commit();
        return 'created';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * 従業員名を取得する
 */
function currentEmployeeName(PDO $pdo, int $employeeId): string
{
    $stmt = $pdo->prepare('SELECT name FROM employees WHERE id = :id');
    $stmt->execute(['id' => $employeeId]);
    $name = $stmt->fetchColumn();

    return $name === false ? '従業員' : (string) $name;
}

/**
 * 店長が承認後キャンセル申請を承認または却下する
 *
 * @param string $decision approved または rejected
 * @return string approved / rejected / not_found / already_decided / invalid_type / not_eligible
 */
function decideAfterApprovalCancellationRequest(
    PDO $pdo,
    int $cancellationRequestId,
    int $managerUserId,
    string $decision
): string {
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        return 'invalid_type';
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT *
             FROM cancellation_requests
             WHERE id = :id
             FOR UPDATE'
        );
        $stmt->execute(['id' => $cancellationRequestId]);
        $request = $stmt->fetch();

        if ($request === false) {
            $pdo->rollBack();
            return 'not_found';
        }
        if ($request['status'] !== 'pending') {
            $pdo->rollBack();
            return 'already_decided';
        }
        if ($request['request_type'] !== CANCELLATION_TYPE_REQUESTER_AFTER_APPROVAL) {
            $pdo->rollBack();
            return 'invalid_type';
        }

        $stmt = $pdo->prepare(
            'SELECT lr.id, lr.employee_id AS requester_employee_id, lr.status AS leave_status,
                    s.id AS shift_id, s.employee_id AS current_shift_employee_id,
                    s.status AS shift_status, s.shift_date, s.start_time, s.end_time
             FROM leave_requests lr
             JOIN shifts s ON s.id = lr.shift_id
             WHERE lr.id = :leave_request_id
             FOR UPDATE'
        );
        $stmt->execute(['leave_request_id' => $request['leave_request_id']]);
        $target = $stmt->fetch();

        if ($target === false) {
            $pdo->rollBack();
            return 'not_found';
        }

        $shiftDateLabel = date('n月j日', strtotime($target['shift_date']));
        $startLabel = substr($target['start_time'], 0, 5);
        $endLabel = substr($target['end_time'], 0, 5);

        if ($decision === 'approved') {
            if (
                $target['leave_status'] !== 'approved'
                || $target['shift_status'] !== 'substituted'
                || (int) $target['current_shift_employee_id'] === (int) $target['requester_employee_id']
                || (int) $request['requested_by_employee_id'] !== (int) $target['requester_employee_id']
            ) {
                $pdo->rollBack();
                return 'not_eligible';
            }

            $substituteEmployeeId = (int) $target['current_shift_employee_id'];

            $pdo->prepare(
                "UPDATE shifts
                 SET employee_id = :employee_id, status = 'scheduled'
                 WHERE id = :shift_id"
            )->execute([
                'employee_id' => $target['requester_employee_id'],
                'shift_id'    => $target['shift_id'],
            ]);

            $pdo->prepare(
                "UPDATE leave_requests
                 SET status = 'cancelled_after_approval'
                 WHERE id = :id"
            )->execute(['id' => $target['id']]);

            $pdo->prepare(
                "UPDATE cancellation_requests
                 SET status = 'approved', decided_by_user_id = :manager_id, decided_at = NOW()
                 WHERE id = :id"
            )->execute([
                'manager_id' => $managerUserId,
                'id'         => $cancellationRequestId,
            ]);

            insertNotificationForEmployee(
                $pdo,
                (int) $target['requester_employee_id'],
                'after_approval_cancel_approved',
                '休み申請のキャンセルが承認されました',
                sprintf(
                    '%sの%s〜%sの休み申請キャンセルが承認されました。対象シフトの担当者はあなたに戻りました。',
                    $shiftDateLabel,
                    $startLabel,
                    $endLabel
                ),
                (int) $target['id']
            );
            insertNotificationForEmployee(
                $pdo,
                $substituteEmployeeId,
                'after_approval_cancel_approved',
                '代勤予定がキャンセルされました',
                sprintf(
                    '%sの%s〜%sの代勤予定は、休み申請者のキャンセルが承認されたため取り消されました。',
                    $shiftDateLabel,
                    $startLabel,
                    $endLabel
                ),
                (int) $target['id']
            );

            $pdo->commit();
            return 'approved';
        }

        $pdo->prepare(
            "UPDATE cancellation_requests
             SET status = 'rejected', decided_by_user_id = :manager_id, decided_at = NOW()
             WHERE id = :id"
        )->execute([
            'manager_id' => $managerUserId,
            'id'         => $cancellationRequestId,
        ]);

        insertNotificationForEmployee(
            $pdo,
            (int) $target['requester_employee_id'],
            'after_approval_cancel_rejected',
            '休み申請のキャンセルが却下されました',
            sprintf(
                '%sの%s〜%sの休み申請キャンセルは却下されました。現在の代勤予定は維持されます。',
                $shiftDateLabel,
                $startLabel,
                $endLabel
            ),
            (int) $target['id']
        );

        $pdo->commit();
        return 'rejected';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
