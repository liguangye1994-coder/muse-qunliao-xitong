<?php
/**
 * 群聊站 hook 唤醒检测接口（公开接口，不用 token）——通用版，所有 Muse 共用一份
 *   GET /api/muse/wake_check.php?since_id=123&muse=助手
 *
 * 给各 Muse 的 hook 轻量检测脚本用的：每分钟问一句“有没有需要我处理的新东西”，
 * 有才叫醒 agent，没消息就不花 token。替代原来每分钟跑一次 agent 轮询
 * （一天 1440 次，绝大多数是空跑）。
 *
 * 用法：问的时候带 ?muse=自己的名字（须在配置的 muse_names 里）；不带默认助手，
 * 老 hook 不带参数也能继续用。服务器上只放这一份文件，谁用就传自己的名字，
 * 不用每人一份接口。
 *
 * 只返回数字和布尔，不返回任何消息内容、用户名：
 *   {"ok": true, "has_new": bool, "max_id": int, "pending": int}
 *   has_new：有没有需要叫醒的；max_id：命中条件的新消息里最大的 id（没有为 0）；
 *   pending：还没提醒过的待审核消息条数。
 *
 * 叫醒条件（和 agent 轮询会处理的几件事一一对应；muse 参数只影响下面三处）：
 *   1. 有 @这个 Muse 的新消息：inbox.php 同款范围（用户命令：can_command=1、
 *      status=pending、review_status='approved'；或 Muse 回帖 is_muse_reply=1、
 *      review_status='approved'），且 id > since_id，且内容含“@名字”。
 *      兜底（2026-11-18 修）：多人 @ 广播若被提前标 done（比如回帖方名单不全），
 *      10 分钟内、且这个 Muse 还没回过的已 done 消息也算叫醒——只看“我回没回”，
 *      不只看 status，避免漏叫。
 *   2. 负责人房间有新消息：房间的负责人在 `rooms` 表的 `muse_owner` 列里记着，
 *      wake_check 直接读库——以后新建房间、换负责人都不用改代码。
 *      比如某房间的负责人是助手 A，那个房间里有关项目的事都要跟进，
 *      哪怕没 @助手 A。
 *      没设负责人的房间不走这条，只靠 @ / 回帖叫醒。
 *   3. 有人回的是这个 Muse 的消息：新消息的 reply_to_id 指到的那条是这个
 *      Muse 发的（is_muse_reply=1 且 username=名字），没写 @ 也默认当成
 *      @这个 Muse——否则光回帖不 @ 就检查不到。同样是同款 inbox 范围、id > since_id。
 *   4. 有还没提醒过的待审核消息（review_status='pending' 且 is_muse_reply=0
 *      且 reminded=0）。只计数、不标 reminded——标 reminded 是 agent 读 pending
 *      接口时做的事，这里标了 agent 就再也看不到这条待审核了。
 *      这条所有 Muse 共用：谁先轮询到谁发提醒。
 *
 * 安全：本接口故意不鉴权、不返回内容，只给“有没有”这个信号，所以公开也没事。
 * 命中条件只看内容里有没有“@名字”、room_id 和 reply_to_id 指到谁，不认领归属——
 * 归属判断仍由 agent 按内容、@、上下文做，接口只负责叫醒。
 * PHP 7.4+ 兼容。
 */
define('CHAT_APP', true);
require_once __DIR__ . '/../../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_out(array('ok' => false, 'error' => '只支持 GET'), 405);
}

$sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
if ($sinceId < 0) $sinceId = 0;

// 哪个 Muse 在问：?muse=名字；不传或不在名单里时，默认取名单里的第一个
// （安装向导写进 config.local.php 的 muse_names 的第一位；老 hook 不带参数也能继续用）。
// 白名单读配置的 muse_names（唯一来源），只认名单里的名字。
// 加新 Muse 只改 config.local.php 的 muse_names，不用改这里。
$muse = isset($_GET['muse']) ? trim($_GET['muse']) : '';
$allowed = app_config_get('muse_names', array('助手'));
if (!is_array($allowed) || empty($allowed)) {
    $allowed = array('助手');
}
if ($muse === '' || !in_array($muse, $allowed, true)) $muse = $allowed[0];

try {
    $pdo = db_pdo();
    // 负责人房间不写死：rooms 表的 muse_owner 列记着每间房的负责人是谁，
    // 这里直接读库——以后新建房间、换负责人都不用改代码，没设负责人的房间不走这条。
    $roomCond = ' OR m.room_id IN (SELECT id FROM rooms WHERE muse_owner = :muse2)';
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS c, COALESCE(MAX(m.id), 0) AS max_id
         FROM messages m
         LEFT JOIN users u ON u.id = m.user_id
         WHERE m.id > :since
           AND (
             (m.status = 'pending'
              AND m.is_muse_reply = 0
              AND m.review_status = 'approved'
              AND u.can_command = 1)
             OR (m.is_muse_reply = 1 AND m.review_status = 'approved')
             OR (m.status = 'done'
              AND m.is_muse_reply = 0
              AND m.review_status = 'approved'
              AND u.can_command = 1
              AND m.created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
              AND NOT EXISTS (SELECT 1 FROM messages r
                              WHERE r.reply_to_id = m.id
                                AND r.is_muse_reply = 1
                                AND r.username = :muse3))
           )
           AND (m.content LIKE :mention" . $roomCond . "
                OR EXISTS (SELECT 1 FROM messages p
                           WHERE p.id = m.reply_to_id
                             AND p.is_muse_reply = 1
                             AND p.username = :muse))"
    );
    $stmt->execute(array(':since' => $sinceId, ':mention' => '%@' . $muse . '%', ':muse' => $muse, ':muse2' => $muse, ':muse3' => $muse));
    $row = $stmt->fetch();
    $hitCount = (int)$row['c'];
    $maxId = (int)$row['max_id'];

    $stmt2 = $pdo->prepare(
        "SELECT COUNT(*) AS p FROM messages
         WHERE review_status = 'pending'
           AND is_muse_reply = 0
           AND reminded = 0"
    );
    $stmt2->execute();
    $row2 = $stmt2->fetch();
    $pending = (int)$row2['p'];

    json_out(array(
        'ok' => true,
        'has_new' => ($hitCount > 0 || $pending > 0),
        'max_id' => $maxId,
        'pending' => $pending,
    ));
} catch (Exception $e) {
    json_out(array('ok' => false, 'error' => '读取失败'), 500);
}
