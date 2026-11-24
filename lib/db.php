<?php
/**
 * lib/db.php —— 数据库访问薄封装
 * 实际连接逻辑在 config.php 的 db_pdo()，这里只做一层转发，
 * 让各页面统一 require lib/db.php 即可拿到 PDO。
 * PHP 7.4+ 兼容。
 */
if (!defined('CHAT_APP')) {
    define('CHAT_APP', true);
}
require_once __DIR__ . '/../config.php';

function get_pdo()
{
    return db_pdo();
}

/**
 * 各房间对当前用户有多少条未读消息（边栏蓝色小圆点、标签页标题未读数共用）。
 * 返回 room_id => 未读条数（0 = 已读）。未读 = 该房间里“他能看到的”消息
 * 里，id 大于他在 room_reads 里记的 last_read_id 的条数（没记过当 0）。
 * 管理员能看到 approved + pending；普通成员只能看到 approved + 自己发的 pending；
 * rejected 的不算（本来就不显示）。room_reads 或 rooms 表还没建（迁移没跑）
 * 就返回空数组（当全部已读），不让页面报错。
 * 旧版返回的是 true/false；现在返回条数，调用处按 !empty() / !! 判有无未读
 * 的写法不用改（0 为假、>0 为真）。
 * PHP 7.4+ 兼容。
 */
function rooms_unread_map($pdo, $userId, $isAdmin)
{
    $map = array();
    try {
        if ($isAdmin) {
            $stmt = $pdo->prepare("SELECT r.id AS room_id,
                                          COUNT(m.id) AS unread_count
                                   FROM rooms r
                                   LEFT JOIN room_reads rr ON rr.room_id = r.id AND rr.user_id = :uid
                                   LEFT JOIN messages m ON m.room_id = r.id
                                     AND m.id > COALESCE(rr.last_read_id, 0)
                                     AND (m.review_status = 'approved' OR m.review_status = 'pending')
                                   GROUP BY r.id");
            $stmt->execute(array(':uid' => (int)$userId));
        } else {
            $stmt = $pdo->prepare("SELECT r.id AS room_id,
                                          COUNT(m.id) AS unread_count
                                   FROM rooms r
                                   LEFT JOIN room_reads rr ON rr.room_id = r.id AND rr.user_id = :uid
                                   LEFT JOIN messages m ON m.room_id = r.id
                                     AND m.id > COALESCE(rr.last_read_id, 0)
                                     AND (m.review_status = 'approved'
                                       OR (m.review_status = 'pending' AND m.user_id = :uid2))
                                   GROUP BY r.id");
            $stmt->execute(array(':uid' => (int)$userId, ':uid2' => (int)$userId));
        }
        while ($row = $stmt->fetch()) {
            $map[(int)$row['room_id']] = (int)$row['unread_count'];
        }
    } catch (Exception $e) {
        return array();
    }
    return $map;
}

/**
 * 当前用户归档了哪些旁聊（边栏“已归档”区用）。
 * 返回 room_id => true 的集合。room_archives 表还没建（迁移没跑）
 * 就返回空集合（当作都没归档），不让页面报错。
 * PHP 7.4+ 兼容。
 */
function rooms_archived_set($pdo, $userId)
{
    $set = array();
    try {
        $stmt = $pdo->prepare("SELECT room_id FROM room_archives WHERE user_id = :uid");
        $stmt->execute(array(':uid' => (int)$userId));
        while ($row = $stmt->fetch()) {
            $set[(int)$row['room_id']] = true;
        }
    } catch (Exception $e) {
        return array();
    }
    return $set;
}
