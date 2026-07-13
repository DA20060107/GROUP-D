<?php
/**
 * 状態（status）・通知種別の日本語表示ヘルパー
 *
 * 各画面で英語のstatus値をそのまま表示せず、ここで定義した日本語ラベルと
 * バッジ用CSSクラスを使うことで、表示の一貫性を保つ。
 *
 * 使い方:
 *   require_once __DIR__ . '/../../app/includes/status_labels.php';
 *   echo renderStatusBadge(leaveRequestStatusLabel($s), leaveRequestStatusBadgeClass($s));
 */

// ------------------------------------------------------------
// leave_requests.status
//   pending      : 受付中（登録直後の一時状態。通常はすぐ matching/no_candidate に遷移）
//   matching     : 候補者回答待ち
//   no_candidate : 候補者なし（店長の手動対応・確認待ち）
//   approved     : 承認済み
//   rejected     : 却下済み
//   cancelled    : 申請者本人によるキャンセル済み
//   cancelled_after_approval : 店長承認後キャンセル済み
// ------------------------------------------------------------
function leaveRequestStatusLabel(?string $status): string
{
    $labels = [
        'pending'      => '受付中',
        'matching'     => '候補者回答待ち',
        'no_candidate' => '候補者なし',
        'approved'     => '承認済み',
        'rejected'     => '却下済み',
        'cancelled'    => 'キャンセル済み',
        'cancelled_after_approval' => '承認後キャンセル済み',
        'replacement_pending'      => '代勤者再調整中',
    ];
    return $labels[$status] ?? (string) $status;
}

function leaveRequestStatusBadgeClass(?string $status): string
{
    $classes = [
        'pending'      => 'badge-inactive',
        'matching'     => 'badge-active',
        'no_candidate' => 'badge-warning',
        'approved'     => 'badge-success',
        'rejected'     => 'badge-danger',
        'cancelled'    => 'badge-inactive',
        'cancelled_after_approval' => 'badge-inactive',
        'replacement_pending'      => 'badge-warning',
    ];
    return $classes[$status] ?? 'badge-inactive';
}

// ------------------------------------------------------------
// substitute_candidates.status
//   proposed : 未回答（未通知の候補は、notified_at を見て画面側で分岐する）
//   accepted : 代勤可能
//   declined : 代勤不可
//   expired  : 無効
// ------------------------------------------------------------
function candidateStatusLabel(?string $status): string
{
    $labels = [
        'proposed' => '未回答',
        'accepted' => '代勤可能',
        'declined' => '代勤不可',
        'expired'  => '無効',
    ];
    return $labels[$status] ?? (string) $status;
}

function candidateStatusBadgeClass(?string $status): string
{
    $classes = [
        'proposed' => 'badge-active',
        'accepted' => 'badge-success',
        'declined' => 'badge-danger',
        'expired'  => 'badge-inactive',
    ];
    return $classes[$status] ?? 'badge-inactive';
}

// ------------------------------------------------------------
// shifts.status
//   scheduled       : 予定
//   leave_requested : 休み申請中
//   substituted     : 代勤反映済み
//   cancelled       : キャンセル
// ------------------------------------------------------------
function shiftStatusLabel(?string $status): string
{
    $labels = [
        'scheduled'       => '予定',
        'leave_requested' => '休み申請中',
        'leave_approved'  => '休み承認済み',
        'substituted'     => '代勤反映済み',
        'cancelled'       => 'キャンセル',
        'replacement_pending' => '代勤者再調整中',
    ];
    return $labels[$status] ?? (string) $status;
}

function shiftStatusBadgeClass(?string $status): string
{
    $classes = [
        'scheduled'       => 'badge-active',
        'leave_requested' => 'badge-warning',
        'leave_approved'  => 'badge-success',
        'substituted'     => 'badge-success',
        'cancelled'       => 'badge-inactive',
        'replacement_pending' => 'badge-warning',
    ];
    return $classes[$status] ?? 'badge-inactive';
}

// ------------------------------------------------------------
// notifications.type
// ------------------------------------------------------------
function notificationTypeLabel(?string $type): string
{
    $labels = [
        'leave_request'       => '休み申請',
        'substitute_request'  => '代勤依頼',
        'candidate_offer'     => '代勤回答',
        'candidate_available' => '代勤可能回答',
        'no_candidate'        => '候補者なし',
        'approval_result'     => '承認結果',
        'leave_request_cancelled' => '申請キャンセル',
        'after_approval_cancel_requested' => '承認後キャンセル申請',
        'after_approval_cancel_approved'  => '承認後キャンセル承認',
        'after_approval_cancel_rejected'  => '承認後キャンセル却下',
        'substitute_cancel_requested'     => '代勤キャンセル申請',
        'substitute_cancel_approved'      => '代勤キャンセル承認',
        'substitute_cancel_rejected'      => '代勤キャンセル却下',
        'replacement_pending'             => '代勤者再調整中',
        'rematch_no_candidate'            => '再抽出候補者なし',
    ];
    return $labels[$type] ?? (string) $type;
}

// ------------------------------------------------------------
// cancellation_requests.request_type / status
// ------------------------------------------------------------
function cancellationRequestTypeLabel(?string $requestType): string
{
    $labels = [
        'requester_after_approval' => '休み申請者による承認後キャンセル',
        'substitute_after_approval' => '代勤者による承認後キャンセル',
    ];
    return $labels[$requestType] ?? (string) $requestType;
}

function cancellationRequestStatusLabel(?string $status): string
{
    $labels = [
        'pending'  => 'キャンセル申請中',
        'approved' => 'キャンセル承認済み',
        'rejected' => 'キャンセル却下済み',
    ];
    return $labels[$status] ?? (string) $status;
}

function cancellationRequestStatusBadgeClass(?string $status): string
{
    $classes = [
        'pending'  => 'badge-warning',
        'approved' => 'badge-success',
        'rejected' => 'badge-danger',
    ];
    return $classes[$status] ?? 'badge-inactive';
}

// ------------------------------------------------------------
// employees.skill_level（1〜5）
// ------------------------------------------------------------
function skillLevelLabel(?int $level): string
{
    $labels = [
        1 => '1：未経験に近い',
        2 => '2：補助可能',
        3 => '3：通常業務可能',
        4 => '4：安定して対応可能',
        5 => '5：熟練',
    ];
    return $labels[$level] ?? (string) $level;
}

/** スキルレベル選択フォーム用の一覧（[level => 表示ラベル]） */
function skillLevelOptions(): array
{
    return [
        1 => skillLevelLabel(1),
        2 => skillLevelLabel(2),
        3 => skillLevelLabel(3),
        4 => skillLevelLabel(4),
        5 => skillLevelLabel(5),
    ];
}

// ------------------------------------------------------------
// バッジHTMLの生成（ラベルはHTMLエスケープして出力）
// ------------------------------------------------------------
function renderStatusBadge(string $label, string $badgeClass): string
{
    return '<span class="badge ' . htmlspecialchars($badgeClass) . '">'
        . htmlspecialchars($label) . '</span>';
}
