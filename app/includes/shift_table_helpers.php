<?php
/**
 * シフト表表示用の状態判定ヘルパー。
 *
 * 店長側・従業員側のシフト表で状態判定がずれると表示漏れが起きるため、
 * 「どの色で表示するか」「休み申請者行へ補助表示するか」をここで一元管理する。
 */

require_once __DIR__ . '/status_labels.php';

/**
 * シフト表上で「代勤者再調整中」として扱うべき状態か判定する。
 *
 * 手動代勤登録後の代勤者キャンセルでは、元シフトが leave_approved のまま
 * leave_requests.status だけ replacement_pending になる場合がある。
 * そのため shifts.status だけでなく leave_requests.status も必ず見る。
 */
function isShiftTableReplacementPending(array $shift): bool
{
    return ($shift['status'] ?? null) === 'replacement_pending'
        || ($shift['leave_status'] ?? null) === 'replacement_pending';
}

/**
 * シフト表に表示するメインイベントの状態を返す。
 *
 * @return array{status_label:string,badge_class:string,event_class:string,is_replacement_pending:bool}
 */
function buildShiftTableDisplayState(
    array $shift,
    string $viewerRole,
    bool $hasPendingCancellation = false,
    bool $isCurrentShiftEmployee = false,
    bool $isLeaveRequester = false
): array {
    $shiftStatus = (string) ($shift['status'] ?? '');
    $leaveStatus = $shift['leave_status'] ?? null;

    $statusLabel = shiftStatusLabel($shiftStatus);
    $badgeClass = shiftStatusBadgeClass($shiftStatus);
    $eventClass = 'calendar-event-shift';
    $isReplacementPending = isShiftTableReplacementPending($shift);

    if ($isReplacementPending) {
        $statusLabel = '代勤者再調整中';
        $badgeClass = 'badge-warning';
        $eventClass = 'calendar-event-warning';
    } elseif ($viewerRole === 'manager' && $leaveStatus === 'no_candidate') {
        $statusLabel = '候補者なし';
        $badgeClass = 'badge-warning';
        $eventClass = 'calendar-event-no-candidate';
    } elseif ($leaveStatus === 'no_candidate') {
        $statusLabel = '申請中・店長確認待ち';
        $badgeClass = 'badge-warning';
        $eventClass = 'calendar-event-warning';
    } elseif ($hasPendingCancellation) {
        $statusLabel = 'キャンセル申請中・店長確認待ち';
        $badgeClass = 'badge-warning';
        $eventClass = 'calendar-event-warning';
    } elseif (
        $shiftStatus === 'leave_requested'
        || in_array($leaveStatus, ['pending', 'matching'], true)
    ) {
        $statusLabel = '申請中・店長確認待ち';
        $badgeClass = 'badge-warning';
        $eventClass = 'calendar-event-warning';
    } elseif ($shiftStatus === 'leave_approved') {
        $statusLabel = '休み承認済み';
        $badgeClass = 'badge-success';
        $eventClass = 'calendar-event-leave-approved';
    } elseif ($shiftStatus === 'substituted') {
        if ($viewerRole === 'employee' && $isCurrentShiftEmployee && !$isLeaveRequester) {
            $statusLabel = '代勤シフト（あなたが担当）';
        } elseif ($viewerRole === 'employee') {
            $statusLabel = '代勤シフト';
        }
        $badgeClass = 'badge-success';
        $eventClass = 'calendar-event-substituted';
    }

    return [
        'status_label'           => $statusLabel,
        'badge_class'            => $badgeClass,
        'event_class'            => $eventClass,
        'is_replacement_pending' => $isReplacementPending,
    ];
}

/**
 * 代勤反映済み・代勤者再調整中のシフトを、休み申請者本人の行にも表示すべきか判定する。
 */
function shouldShowRequesterLeaveTableEvent(array $shift): bool
{
    $isSubstitutedApproved = ($shift['status'] ?? null) === 'substituted'
        && ($shift['leave_status'] ?? null) === 'approved';

    return (
        ($isSubstitutedApproved || isShiftTableReplacementPending($shift))
        && ($shift['related_leave_request_id'] ?? null) === null
        && ($shift['requester_employee_id'] ?? null) !== null
        && (int) $shift['requester_employee_id'] !== (int) ($shift['employee_id'] ?? 0)
    );
}

/**
 * 休み申請者本人の行に補助表示するイベントの状態を返す。
 *
 * @return array{status_label:string,badge_class:string,event_class:string,is_replacement_pending:bool}
 */
function buildShiftTableRequesterEventState(
    array $shift,
    string $viewerRole,
    bool $hasPendingCancellation = false
): array {
    $isReplacementPending = isShiftTableReplacementPending($shift);

    if ($isReplacementPending) {
        return [
            'status_label'           => '代勤者再調整中',
            'badge_class'            => 'badge-warning',
            'event_class'            => 'calendar-event-warning',
            'is_replacement_pending' => true,
        ];
    }

    if ($hasPendingCancellation) {
        return [
            'status_label'           => 'キャンセル申請中・店長確認待ち',
            'badge_class'            => 'badge-warning',
            'event_class'            => 'calendar-event-warning',
            'is_replacement_pending' => false,
        ];
    }

    return [
        'status_label'           => $viewerRole === 'employee' ? '休み承認済み（代勤者が担当中）' : '休み承認済み',
        'badge_class'            => 'badge-success',
        'event_class'            => 'calendar-event-leave-approved',
        'is_replacement_pending' => false,
    ];
}
