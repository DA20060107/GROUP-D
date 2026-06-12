<?php
/**
 * シフト作成・一覧確認画面（店長用）
 *
 * TODO: shifts テーブルへの登録・編集、availability（勤務可能日）の参照を実装する予定
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');

$pageTitle = 'シフト作成・一覧確認';
$basePath  = '../../public/';

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    シフトの新規作成と、登録済みシフトの一覧確認を行います。
</p>

<div class="section">
    <a class="btn" href="#">シフトを新規作成（仮）</a>
</div>

<div class="section">
    <h2>シフト一覧（サンプル表示）</h2>
    <table>
        <thead>
            <tr>
                <th>日付</th>
                <th>従業員</th>
                <th>開始時刻</th>
                <th>終了時刻</th>
                <th>状態</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>2026-06-15</td>
                <td>山田 太郎</td>
                <td>17:00</td>
                <td>22:00</td>
                <td>予定</td>
                <td><a class="btn btn-secondary" href="#">編集（仮）</a></td>
            </tr>
            <tr>
                <td>2026-06-15</td>
                <td>佐藤 花子</td>
                <td>17:00</td>
                <td>22:00</td>
                <td>休み申請中</td>
                <td><a class="btn btn-secondary" href="#">編集（仮）</a></td>
            </tr>
            <tr>
                <td>2026-06-16</td>
                <td>鈴木 次郎</td>
                <td>18:00</td>
                <td>23:00</td>
                <td>予定</td>
                <td><a class="btn btn-secondary" href="#">編集（仮）</a></td>
            </tr>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
