<?php
/**
 * lib/reactions.php —— 消息表情回应（像 Muse 界面的“留下心情” / TG 的回复表情）。
 * 每条消息下面挂一排小表情 chips（👌 👍 ❤️ 😂 😮 🙏 👏 🎉），点一下留一个，
 * 再点一下取消；chips 上显示人数，悬停看是谁留的。
 * Muse 收到一条消息后给原消息留一个 👌，管理员在群里一眼就能看到谁接到了，
 * 不用再回一句“收到”占一条消息。
 *
 * 表 message_reactions（见 migrate-reactions.sql）：
 *   message_id + user_id + username + reaction 唯一；网页用户 user_id>0，
 *   Muse user_id=0、username 是 Muse 名（助手们）。
 * 网页端 reactions.php（登录 + CSRF）和 Muse 端 api/muse/react.php
 * （X-Muse-Token）共用这里的逻辑；fetch.php / index.php 共用这里的汇总。
 * PHP 7.4+ 兼容。
 */
if (!defined('CHAT_APP')) {
    define('CHAT_APP', true);
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/push.php';

/** 可留的表情（固定 8 个，照 Muse / TG 常用那套，👌 是 Muse 收到的默认手势）。 */
function reaction_allowed_list()
{
    return array('👌', '👍', '❤️', '😂', '😮', '🙏', '👏', '🎉');
}

/**
 * 汇总一批消息的表情。
 * $messageIds：消息 id 数组；$meUsername：当前操作者名字（网页用户是用户名，
 * Muse 是 Muse 名），用来算 mine。
 * 返回 [message_id => [emoji => ['count' => n, 'users' => [名字...], 'mine' => bool]]]，
 * 没有表情的消息对应空数组。users 按留的时间先后排，最多列 10 个名字。
 */
function reactions_summary_for($pdo, $messageIds, $meUsername)
{
    $out = array();
    $ids = array();
    foreach ((array)$messageIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
            $out[$id] = array();
        }
    }
    if (empty($ids)) {
        return $out;
    }
    // 表情是锦上添花的功能，绝不能因为它把整个聊天页拖垮：
    // 表还没建（迁移 SQL 没跑）、库一时连不上，都当没有表情处理，返回空汇总。
    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT message_id, reaction, username, created_at
             FROM message_reactions
             WHERE message_id IN ($placeholders)
             ORDER BY message_id ASC, created_at ASC"
        );
        $stmt->execute(array_values($ids));
        while ($r = $stmt->fetch()) {
            $mid = (int)$r['message_id'];
            $emo = (string)$r['reaction'];
            if (!isset($out[$mid][$emo])) {
                $out[$mid][$emo] = array('count' => 0, 'users' => array(), 'mine' => false);
            }
            $out[$mid][$emo]['count']++;
            if (count($out[$mid][$emo]['users']) < 10) {
                $out[$mid][$emo]['users'][] = (string)$r['username'];
            }
            if ($meUsername !== '' && (string)$r['username'] === (string)$meUsername) {
                $out[$mid][$emo]['mine'] = true;
            }
        }
    } catch (Exception $e) {
        return $out;
    }
    return $out;
}

/**
 * 点按切换：有就删（取消），没有就加。
 * $userId：网页用户 id，Muse 传 0；$username：网页用户名或 Muse 名。
 * 返回 array('ok' => true, 'added' => bool, 'summary' => [emoji => ...])，
 * 失败返回 array('ok' => false, 'error' => '...')。
 */
function reactions_toggle($pdo, $messageId, $userId, $username, $reaction)
{
    $messageId = (int)$messageId;
    $userId = (int)$userId;
    $username = trim((string)$username);
    if ($messageId <= 0) {
        return array('ok' => false, 'error' => 'message_id 无效');
    }
    if ($username === '') {
        return array('ok' => false, 'error' => '用户名为空');
    }
    if (!in_array($reaction, reaction_allowed_list(), true)) {
        return array('ok' => false, 'error' => '不支持这个表情');
    }
    try {
        $chk = $pdo->prepare("SELECT room_id FROM messages WHERE id = :id LIMIT 1");
        $chk->execute(array(':id' => $messageId));
        $msg = $chk->fetch();
        if (!$msg) {
            return array('ok' => false, 'error' => '消息不存在');
        }
        $roomId = (int)$msg['room_id'];
        $del = $pdo->prepare(
            "DELETE FROM message_reactions
             WHERE message_id = :mid AND user_id = :uid AND username = :uname AND reaction = :emo"
        );
        $del->execute(array(':mid' => $messageId, ':uid' => $userId, ':uname' => $username, ':emo' => $reaction));
        $added = false;
        if ($del->rowCount() === 0) {
            $ins = $pdo->prepare(
                "INSERT INTO message_reactions (message_id, user_id, username, reaction)
                 VALUES (:mid, :uid, :uname, :emo)"
            );
            $ins->execute(array(':mid' => $messageId, ':uid' => $userId, ':uname' => $username, ':emo' => $reaction));
            $added = true;
        }
        $summary = reactions_summary_for($pdo, array($messageId), $username);
        $one = isset($summary[$messageId]) ? $summary[$messageId] : array();
        // 实时推送：同房间的浏览器立刻刷新这条的 chips，不用等轮询。
        // 推送里不带 mine（mine 是按人算的，各浏览器按自己的名字从 users 里算）。
        $pushOne = array();
        foreach ($one as $emo => $info) {
            $pushOne[$emo] = array(
                'count' => (int)$info['count'],
                'users' => isset($info['users']) ? $info['users'] : array(),
            );
        }
        push_notify(array(
            'type' => 'reaction',
            'room_id' => $roomId,
            'message_id' => $messageId,
            'reactions' => $pushOne,
        ));
        return array('ok' => true, 'added' => $added, 'summary' => $one);
    } catch (Exception $e) {
        return array('ok' => false, 'error' => '写入失败');
    }
}

/**
 * 服务端直出首屏时用的 chips HTML（index.php 用；JS 渲染的走 renderReactions）。
 * $summary：reactions_summary_for 回的某一条消息的汇总；$meUsername：当前登录用户名。
 */
function reactions_html($summary, $messageId, $meUsername)
{
    if (!is_array($summary) || empty($summary)) {
        return '<div class="reactions" data-id="' . (int)$messageId . '"><button type="button" class="react-add" data-id="' . (int)$messageId . '" title="留个表情" aria-label="留个表情">😊</button></div>';
    }
    $html = '<div class="reactions" data-id="' . (int)$messageId . '">';
    foreach ($summary as $emo => $info) {
        $mine = !empty($info['mine']) ? ' mine' : '';
        $users = isset($info['users']) ? implode('、', $info['users']) : '';
        $html .= '<button type="button" class="react-chip' . $mine . '" data-id="' . (int)$messageId . '" data-reaction="' . htmlspecialchars($emo, ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars($users, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($emo, ENT_QUOTES, 'UTF-8') . ' ' . (int)$info['count'] . '</button>';
    }
    $html .= '<button type="button" class="react-add" data-id="' . (int)$messageId . '" title="留个表情" aria-label="留个表情">😊</button></div>';
    return $html;
}
