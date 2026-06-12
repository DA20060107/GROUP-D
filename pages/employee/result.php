<?php
/**
 * 承認結果確認画面（従業員用）
 *
 * TODO: approvals テーブルを参照し、自分に関係する承認結果を取得する予定
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('employee');

$pageTitle = '承認結果確認';
$basePath  = '../../public/';

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    休み申請や代勤に関する承認結果を確認できます。
</p>

<div class="section">
    <table>
        <thead>
            <tr>
                <th>日時</th>
                <th>申請内容</th>
                <th>結果</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>2026-06-11 18:00</td>
                <td>2026-06-10 のシフトの休み申請</td>
                <td>承認</td>
            </tr>
            <tr>
                <td>2026-06-09 12:00</td>
                <td>2026-06-08 のシフトの代勤対応</td>
                <td>承認</td>
            </tr>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
