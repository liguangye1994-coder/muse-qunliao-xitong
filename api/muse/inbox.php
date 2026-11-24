<?php
/**
 * 助手轮询取消息接口
 *   GET /api/muse/inbox.php?since_id=123
 *
 * 返回两类新消息（按 id 升序，最多 50 条），响应里每条都带 is_muse_reply
 * 区分种类，调用方自己按名字过滤掉自己的回帖：
 *   A. 用户命令：can_command=1 的用户发的、status=pending、不是 Muse 回帖、
 *      且 review_status='approved' 的消息。先审后发：没过 管理员 审核的
 *      消息（review_status='pending'/'rejected'）不会被取走执行。默认只有
 *      管理员 的 can_command=1，其他成员的消息不返回——他们只能聊天，
 *      不能指挥 Muse，除非 管理员 在管理页亲口指定某人允许指挥，
 *      那时不用改代码。
 *   B. Muse 回帖：is_muse_reply=1 且 review_status='approved' 的消息，
 *      不论是谁发的——这是两个 Muse 互相交接的通道（2026-11-13 加）。
 *      以前只返回 A 类，助手的回帖永远进不了助手的收件箱，助手收不到
 *      助手发给它的东西，反过来也一样。现在 B 类也返回，交接办法是：
 *      发给对方的内容用回帖发、开头写“@对方名字”（例如“@助手 ……”），
 *      对方轮询时看到 is_muse_reply=1 且开头 @ 自己，就收下处理。
 *      回帖本身 status 已是 done，取走不标 done，靠各方自己的水位去重。
 *      注意：自己的回帖也会返回，调用方必须按 username 过滤掉自己的；
 *      收到交接不要在群里回“收到”（会来回刷出新回帖），有事直接办，
 *      需要告诉用户时回自己的聊天汇报。
 *
 * 认证：请求头 X-Muse-Token 或 Authorization: Bearer <token>，
 * token 在服务端 config.local.php 里，不放 URL、不放前端 JS。
 * 取走不等于处理完：助手处理完一条 A 类消息，要调 reply.php 回帖，
 * reply.php 会把原消息标 done；没回帖的下次还会再返回，避免丢消息。
 * 旁聊房间：返回所有房间的消息，每条带 room_id 和 room_name（1=主群“群聊”，
 * 其他是各旁聊的名字，做视频/生图/代码……），Muse 不用再去查 rooms 表、
 * 不用逐个房间去识别——收件箱直接告诉你这条消息在哪个旁聊。
 * Muse 回帖时 reply.php 按原消息的 room_id 把回帖写进同一个房间，
 * 用户在哪个旁聊 @ 的 Muse，回帖就出现在哪一个旁聊里。
 *
 * 摘要模式（2026-11-19 加，解水位积压）：GET 加 summary=1 时只回摘要行，
 * 不回全文——每条只有 id、room_id、room_name、user_id、username、created_at、
 * is_muse_reply、preview（内容前 200 字）、len（全文长度）、mentions_you
 * （内容里有没有“@这个 Muse”，1/0；muse 名用 ?muse=传，白名单读 muse_names，
 * 不传默认助手）、reply_to_name（这条回的是谁的消息的用户名，没有回帖时为
 * NULL）、reply_to_you（这条是不是回这个 Muse 的消息，1/0；口径与
 * wake_check.php 的叫醒条件 3 一字不差：被回的那条是这个 Muse 发的回帖
 * is_muse_reply=1 且 username=名字）。worker 先拿摘要分流（只看预览就能
 * 判断归不归自己），只对相关/看不清的那几条再用 inbox <id-1> 单独取全文，
 * 明显无关的直接跳过、不取全文，积压 86 条也不用逐条读全文。len<=200 时预览
 * 就是全文，不用再取。分流铁律（2026-11-19 882 漏判的教训）：取全文的触发
 * 条件是 mentions_you=1 或 reply_to_you=1 或消息在自己负责的房间里，
 * 绝不能只凭 mentions_you=0 就跳过——回帖没 @ 也默认 @ 被回的人，这是
 * 2026-11-14 用户亲口定的规矩，摘要行必须把这个信号带上，worker 才判得到。
 * 没传 summary 时行为和原来一字不差，老客户端不受影响。
 * PHP 7.4+ 兼容。
 */
define('CHAT_APP', true);
require_once __DIR__ . '/../../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_out(array('ok' => false, 'error' => '只支持 GET'), 405);
}
if (!muse_token_valid()) {
    json_out(array('ok' => false, 'error' => 'token 无效'), 401);
}

$sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
if ($sinceId < 0) $sinceId = 0;

// 摘要模式：只回摘要行，不回全文（见文件头注释）。
$summary = isset($_GET['summary']) && $_GET['summary'] === '1';
// 哪个 Muse 在问摘要：?muse=名字；只用于 mentions_you 这一列，不传或不在名单里时默认取名单里的第一个。
// 白名单读配置的 muse_names（唯一来源），只认名单里的名字。
$muse = isset($_GET['muse']) ? trim($_GET['muse']) : '';
$allowed = app_config_get('muse_names', array('助手'));
if (!is_array($allowed) || empty($allowed)) {
    $allowed = array('助手');
}
if ($muse === '' || !in_array($muse, $allowed, true)) $muse = $allowed[0];

try {
    $pdo = db_pdo();
    if ($summary) {
        $stmt = $pdo->prepare(
            "SELECT m.id, m.room_id, r.name AS room_name, m.user_id, m.username, m.created_at, m.is_muse_reply,
                    SUBSTRING(m.content, 1, 200) AS preview, CHAR_LENGTH(m.content) AS len,
                    (m.content LIKE :mention) AS mentions_you,
                    p.username AS reply_to_name,
                    EXISTS (SELECT 1 FROM messages pp
                            WHERE pp.id = m.reply_to_id
                              AND pp.is_muse_reply = 1
                              AND pp.username = :muse_reply) AS reply_to_you
             FROM messages m
             LEFT JOIN rooms r ON r.id = m.room_id
             LEFT JOIN users u ON u.id = m.user_id
             LEFT JOIN messages p ON p.id = m.reply_to_id
             WHERE m.id > :since
               AND (
                 (m.status = 'pending'
                  AND m.is_muse_reply = 0
                  AND m.review_status = 'approved'
                  AND u.can_command = 1)
                 OR (m.is_muse_reply = 1 AND m.review_status = 'approved')
               )
             ORDER BY m.id ASC
             LIMIT 50"
        );
        $stmt->execute(array(':since' => $sinceId, ':mention' => '%@' . $muse . '%', ':muse_reply' => $muse));
        json_out(array('ok' => true, 'summary' => true, 'messages' => $stmt->fetchAll()));
    }
    $stmt = $pdo->prepare(
        "SELECT m.id, m.room_id, r.name AS room_name, m.user_id, m.username, m.content, m.created_at, m.is_muse_reply
         FROM messages m
         LEFT JOIN rooms r ON r.id = m.room_id
         LEFT JOIN users u ON u.id = m.user_id
         WHERE m.id > :since
           AND (
             (m.status = 'pending'
              AND m.is_muse_reply = 0
              AND m.review_status = 'approved'
              AND u.can_command = 1)
             OR (m.is_muse_reply = 1 AND m.review_status = 'approved')
           )
         ORDER BY m.id ASC
         LIMIT 50"
    );
    $stmt->execute(array(':since' => $sinceId));
    json_out(array('ok' => true, 'messages' => $stmt->fetchAll()));
} catch (Exception $e) {
    json_out(array('ok' => false, 'error' => '读取失败'), 500);
}
