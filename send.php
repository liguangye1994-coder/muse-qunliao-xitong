<?php
/**
 * 发消息接口（send.php）—— 仅登录用户，POST + CSRF。
 * 用户消息一律 status=pending、is_muse_reply=0；
 * 能不能指挥助手不在这里判，在 api/muse/inbox.php 按 can_command 过滤。
 * 发信息权限：只有 admin 和被 管理员 点过“允许指挥”的成员（can_command=1）
 * 能发信息；没被允许指挥的普通成员发不了信息（直接拒绝，不进待审核队列）。
 * 所有成员都能看到消息（fetch.php 里成员也能看到待审核的），所以不存在
 * “发了别人看不到”的情况。能发的信息直接 approved，不用再逐条审核；
 * 助手回帖直接 approved。
 */
define('CHAT_APP', true);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/push.php';

header('Content-Type: application/json; charset=utf-8');
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

$content = isset($_POST['content']) ? trim($_POST['content']) : '';
if ($content === '') {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => '内容为空'), JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($content, 'UTF-8') > 5000) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => '内容太长，最多 5000 字'), JSON_UNESCAPED_UNICODE);
    exit;
}

// 回复功能：可选 reply_to_id，须是已存在的消息 id，不存在就当没传（不报错、不断发送）。
$replyToId = isset($_POST['reply_to_id']) ? (int)$_POST['reply_to_id'] : 0;
if ($replyToId < 0) $replyToId = 0;
// 旁聊房间：可选 room_id，默认 1（主群）；不存在的房间号当 1 处理。
$roomId = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 1;
if ($roomId < 1) $roomId = 1;

try {
    $pdo = db_pdo();
    if ($replyToId > 0) {
        $chk = $pdo->prepare("SELECT id FROM messages WHERE id = :id LIMIT 1");
        $chk->execute(array(':id' => $replyToId));
        if (!$chk->fetch()) $replyToId = 0;
    }
    $rch = $pdo->prepare("SELECT id FROM rooms WHERE id = :id LIMIT 1");
    $rch->execute(array(':id' => $roomId));
    if (!$rch->fetch()) $roomId = 1;
    // 发信息权限：只有 admin 和被 管理员 点过“允许指挥”的成员
    // （can_command=1）能发信息；没被允许指挥的普通成员发不了信息，
    // 直接拒绝，不进待审核队列。所有成员都能看到消息（fetch.php 里
    // 成员也能看到待审核的），所以不存在“发了别人看不到”的情况。
    // 注意：can_command 每次发消息都现查数据库（不在 session 里存），
    // 这样 管理员 在管理页改了权限，下一条消息立刻生效，不用对方重新登录。
    $canSend = false;
    if (isset($user['role']) && $user['role'] === 'admin') {
        $canSend = true;
    } else {
        $cc = $pdo->prepare("SELECT can_command FROM users WHERE id = :id LIMIT 1");
        $cc->execute(array(':id' => $user['id']));
        $ccRow = $cc->fetch();
        if ($ccRow && !empty($ccRow['can_command'])) {
            $canSend = true;
        }
    }
    if (!$canSend) {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'error' => '你还没有发信息的权限，请联系管理员开通'), JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 能发的信息直接通过（免审核），所有成员都能看到。
    $reviewStatus = 'approved';
    $stmt = $pdo->prepare("INSERT INTO messages (room_id, user_id, username, content, status, is_muse_reply, reply_to_id, review_status)
                           VALUES (:room, :uid, :uname, :content, 'pending', 0, :reply_to, :review)");
    $stmt->execute(array(
        ':room' => $roomId,
        ':uid' => $user['id'],
        ':uname' => $user['username'],
        ':content' => $content,
        ':reply_to' => $replyToId > 0 ? $replyToId : null,
        ':review' => $reviewStatus,
    ));
    $newId = (int)$pdo->lastInsertId();
    // 实时推送：写库后通知推送服务，群里秒显示；推送失败不影响发消息。
    $pushRow = push_message_by_id($pdo, $newId);
    if ($pushRow) {
        push_notify(array('type' => 'message', 'room_id' => (int)$pushRow['room_id'], 'message' => $pushRow));
    }
    echo json_encode(array('ok' => true, 'id' => $newId, 'review_status' => $reviewStatus), JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => '写入失败'), JSON_UNESCAPED_UNICODE);
}
