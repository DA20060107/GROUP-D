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
// ------------------------------------------------------------
function leaveRequestStatusLabel(?string $status): string
{
    $labels = [
        'pending'      => '受付中',
        'matching'     => '候補者回答待ち',
        'no_candidate' => '候補者なし',
        'approved'     => '承認済み',
        'rejected'     => '却下済み',
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
    ];
    return $classes[$status] ?? 'badge-inactive';
}

// ------------------------------------------------------------
// substitute_candidates.status
//   proposed : 未回答
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
        'substituted'     => '代勤反映済み',
        'cancelled'       => 'キャンセル',
    ];
    return $labels[$status] ?? (string) $status;
}

function shiftStatusBadgeClass(?string $status): string
{
    $classes = [
        'scheduled'       => 'badge-active',
        'leave_requested' => 'badge-warning',
        'substituted'     => 'badge-success',
        'cancelled'       => 'badge-inactive',
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
    ];
    return $labels[$type] ?? (string) $type;
}

// ------------------------------------------------------------
// バッジHTMLの生成（ラベルはHTMLエスケープして出力）
// ------------------------------------------------------------
function renderStatusBadge(string $label, string $badgeClass): string
{
    return '<span class="badge ' . htmlspecialchars($badgeClass) . '">'
        . htmlspecialchars($label) . '</span>';
}
