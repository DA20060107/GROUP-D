<?php
/**
 * 通知確認画面（従業員用）
 *
 * TODO: notifications テーブルからログイン中の従業員宛の通知を取得する予定
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('employee');

$pageTitle = '通知確認';
$basePath  = '../../public/';

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    代勤の依頼や申請結果など、自分宛の通知を確認します。
</p>

<div class="section">
    <table>
        <thead>
            <tr>
                <th>日時</th>
                <th>内容</th>
                <th>状態</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>2026-06-12 09:10</td>
                <td>2026-06-15 17:00-22:00 のシフトの代勤候補に選ばれました。</td>
                <td>未回答</td>
                <td><a class="btn btn-secondary" href="candidate_response.php">回答する</a></td>
            </tr>
            <tr>
                <td>2026-06-11 18:00</td>
                <td>2026-06-10 のシフトについて、休み申請が承認されました。</td>
                <td>確認済み</td>
                <td>-</td>
            </tr>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
