<?php
/**
 * 代勤提案への応答画面（従業員用）
 *
 * TODO: substitute_candidates テーブルの該当レコードの status を更新する予定
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('employee');

$pageTitle = '代勤提案への応答';
$basePath  = '../../public/';

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    以下のシフトについて、代勤として対応できるかご回答ください。
</p>

<div class="section">
    <table>
        <thead>
            <tr>
                <th>日付</th>
                <th>開始時刻</th>
                <th>終了時刻</th>
                <th>申請者</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>2026-06-15</td>
                <td>17:00</td>
                <td>22:00</td>
                <td>佐藤 花子</td>
            </tr>
        </tbody>
    </table>
</div>

<div class="section">
    <a class="btn" href="#">対応可能（仮）</a>
    <a class="btn btn-secondary" href="#">対応不可（仮）</a>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
