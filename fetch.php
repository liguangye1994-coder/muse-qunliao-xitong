<?php
/**
 * 拉新消息接口（fetch.php）—— 仅登录用户，GET ?since_id=...
 * 可见范围：所有成员都能看到消息——返回 review_status='approved' 和
 * 'pending' 的消息（待审核的也不对成员隐藏，带 review_status 字段，
 * 前端标“待审核”）；别人的 rejected 不返回，只有自己发的 rejected
 * 才返回（仅自己可见）。管理员看全部（approved + pending + rejected）。
 * 群内直接审核：管理员还能看到所有人的 pending 消息，前端给每条
 * pending 消息显示“通过 / 不通过”按钮，点后调 review.php；点“不通过”
 * 后那条消息普通成员就看不到了（只有发信人自己还能看到）。
 * 返回本群消息（最多 100 条，按 id 升序）：为让刚通过审核的旧消息也能被
 * 在线用户看到，取 id > since_id - 100 的窗口（而不只是 id > since_id），
 * 前端按 data-id 去重，已显示过的不重复追加。
 * 另外：窗口内已“不通过”的消息也返回（管理员看全部、成员只看自己的），
 * 前端收到后把页上还挂着的那条待审核行删掉——否则在管理页点了不通过，
 * 群聊页里那条还会一直挂着“待审核”，再点就报“已审过”，看着像卡住。
 * 往上翻加载更早的消息：GET ?before_id=...，取 id < before_id 的最近 50 条
 * （同上，按 id 升序返回，前端插到列表最前面）；和轮询分开，轮询只往后看
 * 新消息，这里只往前看旧消息。首屏只拉最近 50 条、页上最多留 200 条，
 * 都是照 Muse 聊天界面的做法，消息再多也不会把页面撑卡。
 * 旁聊房间：GET ?room_id=...（默认 1 = 主群），所有查询都按房间过滤；
 * 切房间时前端调 ?room_id=N&initial=1，取该房间最近 50 条（按 id 升序返回），
 * 不用整页刷新。
 * 前端每 3 秒调一次；不含 token，不返回密码等敏感字段。
 */
define('CHAT_APP', true);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/reactions.php';

// 给一批消息行挂上 reactions 汇总（表情 chips 用；没有表情的是空数组）。
function attach_reactions($pdo, $rows, $meUsername)
{
    if (empty($rows)) {
        return $rows;
    }
    $ids = array();
    foreach ($rows as $r) {
        $ids[] = (int)$r['id'];
    }
    $map = reactions_summary_for($pdo, $ids, (string)$meUsername);
    foreach ($rows as &$r) {
        $r['reactions'] = isset($map[(int)$r['id']]) ? $map[(int)$r['id']] : array();
    }
    return $rows;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$user = current_user();
if ($user === null) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'error' => '未登录'), JSON_UNESCAPED_UNICODE);
    exit;
}

$sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
if ($sinceId < 0) $sinceId = 0;
$beforeId = isset($_GET['before_id']) ? (int)$_GET['before_id'] : 0;
if ($beforeId < 0) $beforeId = 0;
$roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 1;
if ($roomId < 1) $roomId = 1;
$initial = isset($_GET['initial']) ? (int)$_GET['initial'] : 0;

try {
    $pdo = db_pdo();
    $isAdmin = is_admin($user);
    // 切房间（initial=1）：取该房间最近 50 条，按 id 升序返回，前端整列表重绘。
    if ($initial > 0) {
        if ($isAdmin) {
            $stmt = $pdo->prepare("SELECT m.id, m.user_id, m.username, m.content, m.created_at, m.is_muse_reply, m.reply_to_id, m.review_status,
                                          r.username AS reply_username, r.content AS reply_content
                                   FROM messages m
                                   LEFT JOIN messages r ON r.id = m.reply_to_id
                                   WHERE m.room_id = :room
                                     AND (m.review_status = 'approved' OR m.review_status = 'pending' OR m.review_status = 'rejected')
                                   ORDER BY m.id DESC LIMIT 50");
            $stmt->execute(array(':room' => $roomId));
        } else {
            $stmt = $pdo->prepare("SELECT m.id, m.user_id, m.username, m.content, m.created_at, m.is_muse_reply, m.reply_to_id, m.review_status,
                                          r.username AS reply_username, r.content AS reply_content
                                   FROM messages m
                                   LEFT JOIN messages r ON r.id = m.reply_to_id
                                   WHERE m.room_id = :room
                                     AND (m.review_status = 'approved'
                                       OR (m.review_status = 'pending')
                                       OR (m.review_status = 'rejected' AND m.user_id = :uid))
                                   ORDER BY m.id DESC LIMIT 50");
            $stmt->execute(array(':room' => $roomId, ':uid' => (int)$user['id']));
        }
        $rows = attach_reactions($pdo, array_reverse($stmt->fetchAll()), $user['username']);
        // 切房间时也回一份 rooms_unread（除当前房间外），边栏蓝点立刻刷新，不用等下一轮轮询。
        $unreadMap = rooms_unread_map($pdo, (int)$user['id'], $isAdmin);
        unset($unreadMap[$roomId]);
        echo json_encode(array('ok' => true, 'is_admin' => $isAdmin, 'me' => (int)$user['id'], 'room_id' => $roomId, 'rooms_unread' => $unreadMap, 'messages' => $rows), JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 往上翻加载更早的消息：取 id < before_id 的最近 50 条，按 id 升序返回，
    // 前端插到列表最前面；可见范围和轮询一致（管理员看全部，成员看通过的+
    // 自己待审核的/被拒的）。返回后前端按需把页面下方的多余消息收掉。
    if ($beforeId > 0) {
        if ($isAdmin) {
            $stmt = $pdo->prepare("SELECT m.id, m.user_id, m.username, m.content, m.created_at, m.is_muse_reply, m.reply_to_id, m.review_status,
                                          r.username AS reply_username, r.content AS reply_content
                                   FROM messages m
                                   LEFT JOIN messages r ON r.id = m.reply_to_id
                                   WHERE m.room_id = :room AND m.id < :before
                                     AND (m.review_status = 'approved' OR m.review_status = 'pending' OR m.review_status = 'rejected')
                                   ORDER BY m.id DESC LIMIT 50");
            $stmt->execute(array(':room' => $roomId, ':before' => $beforeId));
        } else {
            $stmt = $pdo->prepare("SELECT m.id, m.user_id, m.username, m.content, m.created_at, m.is_muse_reply, m.reply_to_id, m.review_status,
                                          r.username AS reply_username, r.content AS reply_content
                                   FROM messages m
                                   LEFT JOIN messages r ON r.id = m.reply_to_id
                                   WHERE m.room_id = :room AND m.id < :before
                                     AND (m.review_status = 'approved'
                                       OR (m.review_status = 'pending')
                                       OR (m.review_status = 'rejected' AND m.user_id = :uid))
                                   ORDER BY m.id DESC LIMIT 50");
            $stmt->execute(array(':room' => $roomId, ':before' => $beforeId, ':uid' => (int)$user['id']));
        }
        $rows = attach_reactions($pdo, array_reverse($stmt->fetchAll()), $user['username']);
        echo json_encode(array('ok' => true, 'is_admin' => $isAdmin, 'me' => (int)$user['id'], 'room_id' => $roomId, 'messages' => $rows), JSON_UNESCAPED_UNICODE);
        exit;
    }
    $window = $sinceId > 100 ? $sinceId - 100 : 0;
    if ($isAdmin) {
        // 管理员在群聊页直接审核：能看到所有人的 pending 消息；
        // 已不通过的也返回，让前端把页上残留的那行删掉（见头注释）。
        $stmt = $pdo->prepare("SELECT m.id, m.user_id, m.username, m.content, m.created_at, m.is_muse_reply, m.reply_to_id, m.review_status,
                                      r.username AS reply_username, r.content AS reply_content
                               FROM messages m
                               LEFT JOIN messages r ON r.id = m.reply_to_id
                               WHERE m.room_id = :room AND m.id > :window
                                 AND (m.review_status = 'approved' OR m.review_status = 'pending' OR m.review_status = 'rejected')
                               ORDER BY m.id ASC LIMIT 100");
        $stmt->execute(array(':room' => $roomId, ':window' => $window));
    } else {
        $stmt = $pdo->prepare("SELECT m.id, m.user_id, m.username, m.content, m.created_at, m.is_muse_reply, m.reply_to_id, m.review_status,
                                      r.username AS reply_username, r.content AS reply_content
                               FROM messages m
                               LEFT JOIN messages r ON r.id = m.reply_to_id
                               WHERE m.room_id = :room AND m.id > :window
                                 AND (m.review_status = 'approved'
                                   OR (m.review_status = 'pending')
                                   OR (m.review_status = 'rejected' AND m.user_id = :uid))
                               ORDER BY m.id ASC LIMIT 100");
        $stmt->execute(array(':room' => $roomId, ':window' => $window, ':uid' => (int)$user['id']));
    }
    // me：当前登录用户的 id，前端靠它判断哪条是自己发的（自己的消息靠右气泡）。
    // rooms_unread：各房间有没有你还没看的消息，前端按它刷新边栏的蓝色小圆点。
    // 当前房间不算未读（你正在看它），前端收到后也会把它标已读。
    $unreadMap = rooms_unread_map($pdo, (int)$user['id'], $isAdmin);
    unset($unreadMap[$roomId]);
    echo json_encode(array('ok' => true, 'is_admin' => $isAdmin, 'me' => (int)$user['id'], 'room_id' => $roomId, 'rooms_unread' => $unreadMap, 'messages' => attach_reactions($pdo, $stmt->fetchAll(), $user['username'])), JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => '读取失败'), JSON_UNESCAPED_UNICODE);
}
