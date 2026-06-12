<?php
/**
 * 通知確認画面（店長用）
 *
 * TODO: notifications テーブルからログインユーザー宛の通知を取得する予定
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');

$pageTitle = '通知確認';
$basePath  = '../../public/';

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    休み申請や代勤回答など、店長宛の通知を確認します。
</p>

<div class="section">
    <table>
        <thead>
            <tr>
                <th>日時</th>
                <th>種別</th>
                <th>内容</th>
                <th>状態</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>2026-06-12 09:00</td>
                <td>休み申請</td>
                <td>佐藤 花子さんから 2026-06-15 のシフトの休み申請がありました。</td>
                <td>未確認</td>
            </tr>
            <tr>
                <td>2026-06-12 10:30</td>
                <td>代勤回答</td>
                <td>鈴木 次郎さんが代勤を「対応可能」で回答しました。</td>
                <td>確認済み</td>
            </tr>
        </tbody>
    </table>
</div>

<div class="section">
    <a class="btn" href="approvals.php">承認画面へ</a>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
