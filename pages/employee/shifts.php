<?php
/**
 * シフト確認画面（従業員用）
 *
 * TODO: ログイン中の従業員自身の shifts を取得して表示する予定
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('employee');

$pageTitle = 'シフト確認';
$basePath  = '../../public/';

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    自分に割り当てられているシフトを確認できます。
</p>

<div class="section">
    <table>
        <thead>
            <tr>
                <th>日付</th>
                <th>開始時刻</th>
                <th>終了時刻</th>
                <th>状態</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>2026-06-14</td>
                <td>17:00</td>
                <td>22:00</td>
                <td>予定</td>
            </tr>
            <tr>
                <td>2026-06-15</td>
                <td>17:00</td>
                <td>22:00</td>
                <td>休み申請中</td>
            </tr>
            <tr>
                <td>2026-06-18</td>
                <td>18:00</td>
                <td>23:00</td>
                <td>予定</td>
            </tr>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
