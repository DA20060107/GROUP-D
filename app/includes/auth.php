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

const SESSION_INACTIVITY_LIMIT_SECONDS = 1800; // 30分

/**
 * ログイン画面URLへタイムアウト通知用のクエリを付与する
 */
function addSessionTimeoutQuery(string $loginUrl): string
{
    $separator = strpos($loginUrl, '?') !== false ? '&' : '?';
    return $loginUrl . $separator . 'timeout=1';
}

/**
 * ログインセッションを破棄する
 */
function clearLoginSession(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

/**
 * 30分無操作によるタイムアウトを確認する
 *
 * @param string|null $loginUrl 指定時はタイムアウト後にログイン画面へリダイレクトする
 * @return bool タイムアウトした場合 true
 */
function checkSessionTimeout(?string $loginUrl = null): bool
{
    if (!isset($_SESSION['user'])) {
        return false;
    }

    $now = time();
    $lastActivity = isset($_SESSION['last_activity'])
        ? (int) $_SESSION['last_activity']
        : $now;

    if (($now - $lastActivity) >= SESSION_INACTIVITY_LIMIT_SECONDS) {
        clearLoginSession();
        $GLOBALS['session_timeout_detected'] = true;

        if ($loginUrl !== null) {
            header('Location: ' . addSessionTimeoutQuery($loginUrl));
            exit;
        }

        return true;
    }

    // ページ遷移があったタイミングを「最終操作」として更新する
    $_SESSION['last_activity'] = $now;
    return false;
}

/**
 * 直前の処理で無操作タイムアウトを検知したか
 */
function wasSessionTimeoutDetected(): bool
{
    return !empty($GLOBALS['session_timeout_detected']);
}

/**
 * 現在ログイン中のユーザー情報を取得する
 * @return array|null ログインしていない場合は null
 */
function currentUser()
{
    if (checkSessionTimeout(null)) {
        return null;
    }

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
    checkSessionTimeout($loginUrl);

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
