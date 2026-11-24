<?php
/**
 * lib/auth.php —— 登录态 / 权限 / CSRF
 * PHP 7.4+ 兼容。session 只存 user_id / username / role，不存密码。
 */
if (!defined('CHAT_APP')) {
    define('CHAT_APP', true);
}
require_once __DIR__ . '/../config.php';

function start_chat_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $name = app_config_get('session_name', 'CHAT_SESSID');
    if ($name !== '') {
        session_name($name);
    }
    // 根目录部署：session 在整站有效。
    session_set_cookie_params(0, '/', '', false, true);
    session_start();
}

function current_user()
{
    start_chat_session();
    if (empty($_SESSION['chat_user_id']) || empty($_SESSION['chat_username'])) {
        return null;
    }
    return array(
        'id'       => (int)$_SESSION['chat_user_id'],
        'username' => $_SESSION['chat_username'],
        'role'     => isset($_SESSION['chat_role']) ? $_SESSION['chat_role'] : 'member',
    );
}

function require_login()
{
    $u = current_user();
    if ($u === null) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

function is_admin($u = null)
{
    if ($u === null) {
        $u = current_user();
    }
    return $u !== null && isset($u['role']) && $u['role'] === 'admin';
}

function require_admin()
{
    $u = require_login();
    if (!is_admin($u)) {
        http_response_code(403);
        echo '只有管理员能打开这个页面。';
        exit;
    }
    return $u;
}

function login_user($row)
{
    start_chat_session();
    session_regenerate_id(true);
    $_SESSION['chat_user_id'] = (int)$row['id'];
    $_SESSION['chat_username'] = $row['username'];
    $_SESSION['chat_role'] = $row['role'];
}

function logout_user()
{
    start_chat_session();
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function csrf_token()
{
    start_chat_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check($token)
{
    start_chat_session();
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], (string)$token);
}
