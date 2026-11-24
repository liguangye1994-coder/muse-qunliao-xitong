<?php
/**
 * ws_auth.php —— 推送服务（Node.js）校验 WebSocket 连接是不是已登录用户。
 * 推送服务收到浏览器 WebSocket 握手时，把 Cookie 原样带过来问本接口；
 * 已登录返回 {"ok":true,...}，没登录返回 401。
 * 只读、不改任何东西，不含 token、不走 CSRF。
 * PHP 7.4+ 兼容。
 */
define('CHAT_APP', true);
require_once __DIR__ . '/lib/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user = current_user();
if ($user === null) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => '未登录'), JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode(array('ok' => true, 'user_id' => (int)$user['id'], 'username' => $user['username']), JSON_UNESCAPED_UNICODE);
