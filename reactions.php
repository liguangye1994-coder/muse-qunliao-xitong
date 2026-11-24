<?php
/**
 * 消息表情回应接口（reactions.php）—— 仅登录用户。
 *   POST reactions.php  message_id=<消息 id> & reaction=<表情> & csrf_token=<...>
 * 点一下留一个表情，再点一下取消（切换）。表情只认固定 8 个
 * （👌 👍 ❤️ 😂 😮 🙏 👏 🎉），其他的直接拒绝。
 * 成功返回 {"ok":true, "added":true/false, "message_id":N, "reactions":{...}}，
 * reactions 是这条消息最新的汇总（和 fetch.php 里的一样）。
 * 写库后经 lib/push.php 推一条 type=reaction 的 WebSocket 事件，
 * 同房间的浏览器立刻刷新这条的 chips，不用等轮询。
 * PHP 7.4+ 兼容。
 */
define('CHAT_APP', true);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/reactions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => '只支持 POST'), JSON_UNESCAPED_UNICODE);
    exit;
}
$user = current_user();
if ($user === null) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => '未登录'), JSON_UNESCAPED_UNICODE);
    exit;
}
if (!csrf_check(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'error' => 'csrf 校验失败，请刷新页面再试'), JSON_UNESCAPED_UNICODE);
    exit;
}

$messageId = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
$reaction = isset($_POST['reaction']) ? trim((string)$_POST['reaction']) : '';

try {
    $pdo = db_pdo();
    $res = reactions_toggle($pdo, $messageId, (int)$user['id'], (string)$user['username'], $reaction);
    if (empty($res['ok'])) {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => $res['error']), JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(array(
        'ok' => true,
        'added' => !empty($res['added']),
        'message_id' => $messageId,
        'reactions' => $res['summary'],
    ), JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => '写入失败'), JSON_UNESCAPED_UNICODE);
}
