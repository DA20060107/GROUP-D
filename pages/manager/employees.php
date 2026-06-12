<?php
/**
 * 従業員情報管理画面
 *
 * TODO: employees テーブルからのデータ取得・登録・編集・削除を実装する予定
 */

require_once __DIR__ . '/../../app/includes/auth.php';
requireRole('manager');

$pageTitle = '従業員情報管理';
$basePath  = '../../public/';

require_once __DIR__ . '/../../app/includes/header.php';
?>

<p class="page-description">
    従業員の基本情報を確認・登録・編集します。
</p>

<div class="section">
    <a class="btn" href="#">新規従業員を登録（仮）</a>
</div>

<div class="section">
    <h2>従業員一覧（サンプル表示）</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>氏名</th>
                <th>メールアドレス</th>
                <th>電話番号</th>
                <th>入社日</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td>山田 太郎</td>
                <td>yamada@example.com</td>
                <td>090-1111-2222</td>
                <td>2023-04-01</td>
                <td><a class="btn btn-secondary" href="#">編集（仮）</a></td>
            </tr>
            <tr>
                <td>2</td>
                <td>佐藤 花子</td>
                <td>sato@example.com</td>
                <td>090-3333-4444</td>
                <td>2023-06-01</td>
                <td><a class="btn btn-secondary" href="#">編集（仮）</a></td>
            </tr>
            <tr>
                <td>3</td>
                <td>鈴木 次郎</td>
                <td>suzuki@example.com</td>
                <td>090-5555-6666</td>
                <td>2024-01-10</td>
                <td><a class="btn btn-secondary" href="#">編集（仮）</a></td>
            </tr>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
