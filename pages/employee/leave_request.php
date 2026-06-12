<?php
/**
 * 休み申請画面（従業員用）
 *
 * TODO: 送信内容を leave_requests テーブルへ登録し、代勤候補抽出処理を呼び出す予定
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('employee');

$pageTitle = '休み申請';
$basePath  = '../../public/';

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    休みたいシフトを選択し、理由を入力して申請してください。
</p>

<form class="section" action="leave_request.php" method="post">
    <div class="form-group">
        <label for="shift">対象シフト</label>
        <select id="shift" name="shift_id">
            <option value="">選択してください</option>
            <option value="1">2026-06-14 17:00-22:00</option>
            <option value="2">2026-06-15 17:00-22:00</option>
            <option value="3">2026-06-18 18:00-23:00</option>
        </select>
    </div>
    <div class="form-group">
        <label for="reason">申請理由</label>
        <textarea id="reason" name="reason" rows="4" placeholder="例: 通院のため"></textarea>
    </div>
    <button type="submit" class="btn" disabled title="申請処理は今後実装予定です">申請する（仮）</button>
</form>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
