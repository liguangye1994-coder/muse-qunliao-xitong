<?php
/**
 * Muse 留表情接口
 *   POST /api/muse/react.php
 *   Header: X-Muse-Token: <token>  或  Authorization: Bearer <token>
 *   Body (JSON): {"message_id": 123, "reaction": "👌", "name": "助手"}
 *
 * 行为：
 *   1) 校验 token（同 reply.php，不放 URL、不记日志）；
 *   2) 给 message_id 那条消息留一个表情：点一下留，再点一下取消（切换）。
 *      表情只认固定 8 个（👌 👍 ❤️ 😂 😮 🙏 👏 🎉），其他的直接拒绝；
 *   3) name 只认配置里的名单（config.local.php 的 muse_names，默认助手），
 *      不在名单里的按名单第一个算，防止有人冒用他人名字留表情；
 *   4) 写库后推一条 type=reaction 的 WebSocket 事件，同房间的浏览器立刻刷新。
 *
 * 用途：Muse 收到一条归自己的消息后，先给原消息留一个 👌（groupchat.py react <id> 👌），
 * 管理员在群里一眼就能看到谁接到了，不用再回一句“收到”占一条消息。
 * PHP 7.4+ 兼容。
 */
define('CHAT_APP', true);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/reactions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(array('ok' => false, 'error' => '只支持 POST'), 405);
}
if (!muse_token_valid()) {
    json_out(array('ok' => false, 'error' => 'token 无效'), 401);
}

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) {
    $in = $_POST;
}
$messageId = isset($in['message_id']) ? (int)$in['message_id'] : 0;
$reaction = isset($in['reaction']) ? trim((string)$in['reaction']) : '';
$museNames = app_config_get('muse_names', array('助手'));
if (!is_array($museNames) || empty($museNames)) {
    $museNames = array('助手');
}
$sender = isset($in['name']) ? trim((string)$in['name']) : $museNames[0];
if (!in_array($sender, $museNames, true)) {
    $sender = $museNames[0];
}

try {
    $pdo = db_pdo();
    $res = reactions_toggle($pdo, $messageId, 0, $sender, $reaction);
    if (empty($res['ok'])) {
        json_out(array('ok' => false, 'error' => $res['error']), 400);
    }
    json_out(array(
        'ok' => true,
        'added' => !empty($res['added']),
        'message_id' => $messageId,
        'reactions' => $res['summary'],
    ));
} catch (Exception $e) {
    json_out(array('ok' => false, 'error' => '写入失败'), 500);
}
