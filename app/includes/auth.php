<?php
/**
 * 認証・権限チェック用関数
 *
 * ログイン処理（public/login.php）で $_SESSION['user'] に以下の形式の配列を格納する。
 *
 *   $_SESSION['user'] = [
 *       'id'          => 1,
 *       'username'    => 'manager01',
 *       'role'        => 'manager', // 'manager' or 'employee'
 *       'employee_id' => null,      // employeeの場合は employees.id
 *       'name'        => '店長 太郎',
 *   ];
 *
 * このファイルは pages/manager/*.php, pages/employee/*.php（プロジェクトルートから2階層下）
 * から require される想定。requireLogin() / requireRole() のデフォルトの遷移先パスは
 * その階層を基準にしている。
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * 現在ログイン中のユーザー情報を取得する
 * @return array|null ログインしていない場合は null
 */
function currentUser()
{
    return $_SESSION['user'] ?? null;
}

/**
 * ログイン必須の画面で呼び出す
 * 未ログインの場合はログイン画面へリダイレクトする
 *
 * @param string $loginUrl ログイン画面へのパス（呼び出し元からの相対パス）
 */
function requireLogin($loginUrl = '../../public/login.php')
{
    if (currentUser() === null) {
        header('Location: ' . $loginUrl);
        exit;
    }
}

/**
 * 指定したロールでのログインを必須とする
 *
 * ログインしていない場合はログイン画面へ、
 * ログイン済みだがロールが異なる場合は自分のロール用メニューへ戻す。
 *
 * @param string $role 'manager' または 'employee'
 * @param string $loginUrl ログイン画面へのパス（呼び出し元からの相対パス）
 */
function requireRole($role, $loginUrl = '../../public/login.php')
{
    requireLogin($loginUrl);

    $user = currentUser();
    if ($user['role'] !== $role) {
        if ($user['role'] === 'manager') {
            header('Location: ../manager/menu.php');
        } else {
            header('Location: ../employee/menu.php');
        }
        exit;
    }
}

/**
 * 店長としてログインしているか
 * @return bool
 */
function isManager()
{
    $user = currentUser();
    return $user !== null && $user['role'] === 'manager';
}

/**
 * 従業員としてログインしているか
 * @return bool
 */
function isEmployee()
{
    $user = currentUser();
    return $user !== null && $user['role'] === 'employee';
}
