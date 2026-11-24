<?php
/**
 * 助手回帖接口
 *   POST /api/muse/reply.php
 *   Header: X-Muse-Token: <token>  或  Authorization: Bearer <token>
 *   Body (JSON): {"content": "……", "reply_to_id": 123, "name": "助手"}
 *
 * 行为：
 *   1) 校验 token（同 inbox.php，不放 URL、不记日志）；
 *   2) 在 messages 里插一条回帖：username 由 name 字段定（默认'助手'），
 *      user_id=0，is_muse_reply=1，status='done'，review_status='approved'
 *      （回帖直接通过审核，群里直接显示，不进审核队列）；
 *      房间跟原消息走：回帖的 room_id 就是 reply_to_id 那条消息的 room_id，
 *      用户在哪个旁聊 @ 的 Muse，回帖就出现在哪个旁聊；不带 reply_to_id 时
 *      默认主群（room_id=1），也可以显式传 room_id 指定房间。
 *   3) 若传了 reply_to_id，原消息不是立刻标 done：多 Muse 广播（@了好几个）
 *      时，第一个回帖就标 done 会让其他被 @ 的 Muse 还没轮询到就看不到这条
 *      ——各 Muse 要各自判断这条消息是否与自己有关，不能光看“有人回了”。
 *      所以只在所有被 @ 的 Muse 都回了、或原消息已超 10 分钟时，才标 done；
 *      没 @ 任何人时默认只等名单第一个（助手）。被 @ 的 Muse 各自用自己的
 *      水位去重，不会重复回同一条。
 *   4) reply_to_id 不存在时返回 400，不插回帖。
 * 多 Muse（不只两个，以后三四个都行）：name 只认配置里的名单
 * （config.local.php 的 muse_names，默认助手），不在名单里的
 * 按名单第一个算，防止有人冒用他人名字发帖。再加 Muse 时只改配置
 * 里加个名字就行，不用改代码、不用重新打包；输入框 @ 列表也读同一处。
 * 建议助手侧：查完网站、拿到信息后再调本接口，不要先回“收到”占位。
 * PHP 7.4+ 兼容。
 */
define('CHAT_APP', true);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/push.php';

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
$content = isset($in['content']) ? trim((string)$in['content']) : '';
$replyToId = isset($in['reply_to_id']) ? (int)$in['reply_to_id'] : 0;
// 多 Muse：发帖名字只认配置里的名单，不在里面的按名单第一个算，防止冒用他人名字。
$museNames = app_config_get('muse_names', array('助手'));
if (!is_array($museNames) || empty($museNames)) {
    $museNames = array('助手');
}
$sender = isset($in['name']) ? trim((string)$in['name']) : $museNames[0];
if (!in_array($sender, $museNames, true)) {
    $sender = $museNames[0];
}

if ($content === '') {
    json_out(array('ok' => false, 'error' => 'content 为空'), 400);
}
if (mb_strlen($content, 'UTF-8') > 5000) {
    json_out(array('ok' => false, 'error' => 'content 太长，最多 5000 字'), 400);
}

try {
    $pdo = db_pdo();
    $pdo->beginTransaction();

    // 回帖写进哪个房间：优先跟 reply_to_id 原消息的房间；没带 reply_to_id 时
    // 用请求里显式传的 room_id（默认 1 主群），不存在的房间号当 1 处理。
    $roomId = isset($in['room_id']) ? (int)$in['room_id'] : 1;
    if ($roomId < 1) $roomId = 1;
    if ($replyToId > 0) {
        $chk = $pdo->prepare("SELECT id, room_id FROM messages WHERE id = :id LIMIT 1");
        $chk->execute(array(':id' => $replyToId));
        $origChk = $chk->fetch();
        if (!$origChk) {
            $pdo->rollBack();
            json_out(array('ok' => false, 'error' => 'reply_to_id 不存在'), 400);
        }
        $roomId = (int)$origChk['room_id'];
    } else {
        $rch = $pdo->prepare("SELECT id FROM rooms WHERE id = :id LIMIT 1");
        $rch->execute(array(':id' => $roomId));
        if (!$rch->fetch()) $roomId = 1;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO messages (room_id, user_id, username, content, status, is_muse_reply, reply_to_id, review_status)
         VALUES (:room, 0, :sender, :content, 'done', 1, :reply_to, 'approved')"
    );
    $stmt->execute(array(
        ':room'     => $roomId,
        ':sender'   => $sender,
        ':content'  => $content,
        ':reply_to' => $replyToId > 0 ? $replyToId : null,
    ));
    $newId = (int)$pdo->lastInsertId();

    if ($replyToId > 0) {
        // 多 Muse 广播：原消息只在所有被 @ 的 Muse 都回了、或已超 10 分钟时才标 done。
        // 没 @ 任何人时默认只等名单第一个。回帖记录本身（reply_to_id + username）
        // 就是“谁回过了”的账，不用新表。
        $markDone = true;
        $orig = $pdo->prepare("SELECT content, created_at FROM messages WHERE id = :id LIMIT 1");
        $orig->execute(array(':id' => $replyToId));
        $origRow = $orig->fetch();
        if ($origRow) {
            $mentioned = array();
            foreach ($museNames as $nm) {
                if (mb_strpos((string)$origRow['content'], '@' . $nm) !== false) {
                    $mentioned[] = $nm;
                }
            }
            if (empty($mentioned)) {
                $mentioned = array($museNames[0]);
            }
            $age = time() - strtotime((string)$origRow['created_at']);
            if ($age < 600) {
                $rep = $pdo->prepare("SELECT DISTINCT username FROM messages WHERE reply_to_id = :id AND is_muse_reply = 1");
                $rep->execute(array(':id' => $replyToId));
                $replied = array();
                while ($r = $rep->fetch()) {
                    $replied[] = (string)$r['username'];
                }
                foreach ($mentioned as $nm) {
                    if (!in_array($nm, $replied, true)) {
                        $markDone = false;
                        break;
                    }
                }
            }
            // 超 10 分钟：不管谁还没回都标 done，免得离线 Muse 一直拖着不放。
        }
        if ($markDone) {
            $upd = $pdo->prepare("UPDATE messages SET status = 'done' WHERE id = :id");
            $upd->execute(array(':id' => $replyToId));
        }
    }

    $pdo->commit();
    // 实时推送：回帖写库后通知推送服务，群里秒显示；推送失败不影响回帖。
    $pushRow = push_message_by_id($pdo, $newId);
    if ($pushRow) {
        push_notify(array('type' => 'message', 'room_id' => (int)$pushRow['room_id'], 'message' => $pushRow));
    }
    json_out(array('ok' => true, 'id' => $newId));
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_out(array('ok' => false, 'error' => '写入失败'), 500);
}
