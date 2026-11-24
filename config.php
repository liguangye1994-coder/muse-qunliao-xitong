<?php
/**
 * 群聊站配置读取逻辑（config.php）
 *
 * 优先级：环境变量 > config.local.php > config.example.php 的占位符（仅兜底）
 * 正式部署必须有 config.local.php（由 config.example.php 复制并填好），
 * 否则 getPDO() / muse_token() 会直接报错，不会用占位符悄悄连库。
 *
 * 本文件不含任何真实密码、真实 token。
 * PHP 7.4+ 兼容：不使用 PHP 8 语法。
 */

if (!defined('CHAT_APP')) {
    define('CHAT_APP', true);
}

// ---- 读配置 ---------------------------------------------------------------
function app_config()
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $local = __DIR__ . '/config.local.php';
    if (is_file($local)) {
        $cfg = require $local;
    } else {
        // 没有本地配置时，只用环境变量；没有环境变量就用占位符，
        // 调用方（db_pdo / muse_token）会校验并报错。
        $cfg = array(
            'db_host'    => getenv('CHAT_DB_HOST')    ? getenv('CHAT_DB_HOST')    : 'localhost',
            'db_name'    => getenv('CHAT_DB_NAME')    ? getenv('CHAT_DB_NAME')    : 'groupchat',
            'db_user'    => getenv('CHAT_DB_USER')    ? getenv('CHAT_DB_USER')    : '',
            'db_pass'    => getenv('CHAT_DB_PASS')    ? getenv('CHAT_DB_PASS')    : '',
            'db_charset' => getenv('CHAT_DB_CHARSET') ? getenv('CHAT_DB_CHARSET') : 'utf8mb4',
            'muse_token' => getenv('CHAT_MUSE_TOKEN') ? getenv('CHAT_MUSE_TOKEN') : '',
            'site_name'    => '群聊',
            'session_name' => 'CHAT_SESSID',
        );
    }

    if (!is_array($cfg)) {
        $cfg = array();
    }
    return $cfg;
}

function app_config_get($key, $default = '')
{
    $cfg = app_config();
    return isset($cfg[$key]) ? $cfg[$key] : $default;
}

// ---- 数据库 ---------------------------------------------------------------
// 单例 PDO。占位符没改（db_user/db_pass 为空或仍为 CHANGE_ME 开头）时直接抛错，
// 避免用错误配置连库或把错误吞掉。
function db_pdo()
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $cfg  = app_config();
    $host = isset($cfg['db_host']) ? $cfg['db_host'] : 'localhost';
    $name = isset($cfg['db_name']) ? $cfg['db_name'] : 'groupchat';
    $user = isset($cfg['db_user']) ? $cfg['db_user'] : '';
    $pass = isset($cfg['db_pass']) ? $cfg['db_pass'] : '';
    $cs   = isset($cfg['db_charset']) ? $cfg['db_charset'] : 'utf8mb4';

    if ($user === '' || $pass === '' || strpos($pass, 'CHANGE_ME') === 0) {
        throw new Exception('数据库配置未填写：请复制 config.example.php 为 config.local.php 并填好。');
    }

    $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=' . $cs;
    $pdo = new PDO($dsn, $user, $pass, array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES  => false,
    ));
    return $pdo;
}

// ---- 助手 token 校验 --------------------------------------------------------
// token 只从服务端配置读，不从 URL 参数读（URL 会进日志）。
// 调用方传：请求头 X-Muse-Token，或 Authorization: Bearer <token>。
function muse_token_from_request()
{
    if (!empty($_SERVER['HTTP_X_MUSE_TOKEN'])) {
        return trim($_SERVER['HTTP_X_MUSE_TOKEN']);
    }
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $h = trim($_SERVER['HTTP_AUTHORIZATION']);
        if (stripos($h, 'Bearer ') === 0) {
            return trim(substr($h, 7));
        }
        return $h;
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $h = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        if (stripos($h, 'Bearer ') === 0) {
            return trim(substr($h, 7));
        }
        return $h;
    }
    return '';
}

function muse_token_valid()
{
    $cfgToken = app_config_get('muse_token', '');
    if ($cfgToken === '' || strpos($cfgToken, 'CHANGE_ME') === 0) {
        return false;
    }
    $given = muse_token_from_request();
    if ($given === '') {
        return false;
    }
    // hash_equals 防时序攻击；长度不同时 hash_equals 会返回 false。
    return hash_equals($cfgToken, $given);
}

// ---- 通用小工具 ------------------------------------------------------------
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function json_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
