<?php
/**
 * 标已读接口（mark_read.php）—— 仅登录用户。
 *   POST mark_read.php  room_id=<房间 id> & last_id=<已读到哪条消息 id> & csrf_token=...
 * 把当前用户在该房间的 last_read_id 更新到 last_id（只往前推、不往后退，
 * 免得旧数据把已读状态顶回去）。没有记录就新建一条。
 * 前端在切进一个房间、或在当前房间看到新消息（贴到底部）时调它，
 * 边栏那间的蓝色小圆点就消失。房间不存在时当主群（id=1）处理，
 * last_id 非法（<0）时当 0。
 * PHP 7.4+ 兼容。
 */
define('CHAT_APP', true);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$user = current_user();
if ($user === null) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => '未登录'), JSON_UNESCAPED_UNICODE);
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
$roomId = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 1;
if ($roomId < 1) $roomId = 1;
$lastId = isset($_POST['last_id']) ? (int)$_POST['last_id'] : 0;
if ($lastId < 0) $lastId = 0;

try {
    $pdo = db_pdo();
    // 房间不存在就当主群，不报错（前端切房间时房间 id 都是真实的，这里只是兜底）。
    $rch = $pdo->prepare("SELECT id FROM rooms WHERE id = :id LIMIT 1");
    $rch->execute(array(':id' => $roomId));
    if (!$rch->fetch()) $roomId = 1;
    // 只往前推：新值比旧值小就不写，免得旧数据把已读状态顶回去。
    $stmt = $pdo->prepare("INSERT INTO room_reads (user_id, room_id, last_read_id) VALUES (:uid, :room, :last)
                           ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))");
    $stmt->execute(array(':uid' => (int)$user['id'], ':room' => $roomId, ':last' => $lastId));
    echo json_encode(array('ok' => true, 'room_id' => $roomId, 'last_read_id' => $lastId), JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => '写入失败'), JSON_UNESCAPED_UNICODE);
}
