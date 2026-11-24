<?php
/**
 * 群内直接审核接口（review.php）—— 仅管理员（管理员）。
 * 群聊页里管理员看到待审核消息时点“通过”或“不通过”，前端调本接口。
 *   POST，application/x-www-form-urlencoded
 *   参数：message_id，action=approve|reject，csrf_token
 * 返回 JSON：{"ok": true} 或 {"ok": false, "error": "..."}。
 * 只审当前还是 pending 的用户消息；助手回帖本来就是 approved，不进队列。
 * PHP 7.4+ 兼容。
 */
define('CHAT_APP', true);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/push.php';

header('Content-Type: application/json; charset=utf-8');

$me = current_user();
if ($me === null) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => '未登录'), JSON_UNESCAPED_UNICODE);
    exit;
}
if (!is_admin($me)) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'error' => '只有管理员能审核'), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => '只支持 POST'), JSON_UNESCAPED_UNICODE);
    exit;
}
if (!csrf_check(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'error' => 'CSRF 校验失败，请刷新重试'), JSON_UNESCAPED_UNICODE);
    exit;
}

$mid = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
$action = isset($_POST['action']) ? $_POST['action'] : '';
if ($mid <= 0 || ($action !== 'approve' && $action !== 'reject')) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => '参数不对'), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db_pdo();
    $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
    $stmt = $pdo->prepare("UPDATE messages SET review_status = :r
                           WHERE id = :id AND review_status = 'pending' AND is_muse_reply = 0");
    $stmt->execute(array(':r' => $newStatus, ':id' => $mid));
    if ($stmt->rowCount() > 0) {
        // 实时推送：审核结果立刻同步到各在线浏览器（不通过的当场消失，通过的去掉待审核标记）。
        try {
            $rch = $pdo->prepare("SELECT room_id FROM messages WHERE id = :id LIMIT 1");
            $rch->execute(array(':id' => $mid));
            $rrow = $rch->fetch();
            if ($rrow) {
                push_notify(array('type' => 'review', 'room_id' => (int)$rrow['room_id'], 'message_id' => $mid, 'review_status' => $newStatus));
            }
        } catch (Exception $pe) {
            // 推送失败不影响审核结果
        }
        echo json_encode(array('ok' => true, 'review_status' => $newStatus), JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => '该消息不存在或已审过'), JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => '审核失败'), JSON_UNESCAPED_UNICODE);
}
