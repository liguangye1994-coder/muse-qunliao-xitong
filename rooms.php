<?php
/**
 * 旁聊（房间）接口（rooms.php）—— 仅登录用户。
 *   GET  rooms.php                              → 列出所有房间（id、名字、最后一条消息摘要+时间）
 *   POST rooms.php  action=create（或不传）      → 新建一个旁聊（POST name + csrf_token，名字 1–20 字）
 *   POST rooms.php  action=rename               → 给房间改名（POST room_id + name，名字 1–20 字）
 *   POST rooms.php  action=delete               → 删除一个旁聊（POST room_id）
 *   POST rooms.php  action=archive             → 归档一个旁聊（POST room_id，
 *                                                 只影响自己的边栏，任何登录用户都能做；
 *                                                 主群 id=1 不能归档）
 *   POST rooms.php  action=unarchive           → 取消归档（POST room_id，
 *                                                 同样只影响自己的边栏，任何登录用户都能做）
 *   POST rooms.php  action=set_owner            → 设 / 清房间负责人（POST room_id + muse_owner，
 *                                                 名字须在配置的 muse_names 里，传空即清除；
 *                                                 负责人房间的新消息会叫醒对应的 Muse，哪怕没 @）
 * 房间就是一个旁聊：主群 id=1 叫“群聊”，再按项目开旁聊（做视频/生图/代码各一个）。
 * 任何登录用户都能新建房间；改名、删除和设负责人只有管理员能做（删是破坏性操作）。
 * 主群（id=1）可以改名、不能删除，也不要设负责人——主群只靠 @ / 回帖叫醒。
 * 删除一个旁聊会连它里面的全部消息一起删掉（删前前端会再确认一次），删完不可恢复。
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

try {
    $pdo = db_pdo();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_check(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
            http_response_code(403);
            echo json_encode(array('ok' => false, 'error' => 'CSRF 校验失败，请刷新重试'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $action = isset($_POST['action']) ? trim((string)$_POST['action']) : 'create';
        $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';

        // 归档 / 取消归档：任何登录用户都能做，只影响自己的边栏（各归各的，
        // 不动房间本身、不删消息、不影响别人）。主群（id=1）不能归档。
        if ($action === 'archive' || $action === 'unarchive') {
            $roomId = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
            if ($roomId < 1) {
                http_response_code(400);
                echo json_encode(array('ok' => false, 'error' => '房间不存在'), JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($roomId === 1) {
                http_response_code(400);
                echo json_encode(array('ok' => false, 'error' => '主群不能归档'), JSON_UNESCAPED_UNICODE);
                exit;
            }
            $chk = $pdo->prepare("SELECT id FROM rooms WHERE id = :id LIMIT 1");
            $chk->execute(array(':id' => $roomId));
            if (!$chk->fetch()) {
                http_response_code(404);
                echo json_encode(array('ok' => false, 'error' => '房间不存在'), JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($action === 'archive') {
                $stmt = $pdo->prepare("INSERT IGNORE INTO room_archives (user_id, room_id) VALUES (:uid, :room)");
                $stmt->execute(array(':uid' => (int)$user['id'], ':room' => $roomId));
            } else {
                $stmt = $pdo->prepare("DELETE FROM room_archives WHERE user_id = :uid AND room_id = :room");
                $stmt->execute(array(':uid' => (int)$user['id'], ':room' => $roomId));
            }
            echo json_encode(array('ok' => true, 'id' => $roomId, 'archived' => ($action === 'archive')), JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 改名 / 删除 / 设负责人：只有管理员能做。新建：任何登录用户都能建。
        if ($action === 'rename' || $action === 'delete' || $action === 'set_owner') {
            if (!is_admin($user)) {
                http_response_code(403);
                echo json_encode(array('ok' => false, 'error' => '只有管理员能改名 / 删除旁聊'), JSON_UNESCAPED_UNICODE);
                exit;
            }
            $roomId = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
            if ($roomId < 1) {
                http_response_code(400);
                echo json_encode(array('ok' => false, 'error' => '房间不存在'), JSON_UNESCAPED_UNICODE);
                exit;
            }
            $chk = $pdo->prepare("SELECT id, name FROM rooms WHERE id = :id LIMIT 1");
            $chk->execute(array(':id' => $roomId));
            $room = $chk->fetch();
            if (!$room) {
                http_response_code(404);
                echo json_encode(array('ok' => false, 'error' => '房间不存在'), JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($action === 'rename') {
                if ($name === '') {
                    http_response_code(400);
                    echo json_encode(array('ok' => false, 'error' => '名字为空'), JSON_UNESCAPED_UNICODE);
                    exit;
                }
                if (mb_strlen($name, 'UTF-8') > 20) {
                    http_response_code(400);
                    echo json_encode(array('ok' => false, 'error' => '名字太长，最多 20 字'), JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $upd = $pdo->prepare("UPDATE rooms SET name = :name WHERE id = :id");
                $upd->execute(array(':name' => $name, ':id' => $roomId));
                echo json_encode(array('ok' => true, 'id' => $roomId, 'name' => $name), JSON_UNESCAPED_UNICODE);
                exit;
            }
            // 设负责人：muse_owner 记到 rooms 表，wake_check 读它决定叫醒谁；
            // 传空即清除。主群（id=1）不要设负责人，主群只靠 @ / 回帖叫醒。
            if ($action === 'set_owner') {
                if ($roomId === 1) {
                    http_response_code(400);
                    echo json_encode(array('ok' => false, 'error' => '主群不要设负责人'), JSON_UNESCAPED_UNICODE);
                    exit;
                }
                $owner = isset($_POST['muse_owner']) ? trim((string)$_POST['muse_owner']) : '';
                if ($owner !== '') {
                    $museNames = app_config_get('muse_names', array('助手'));
                    if (!in_array($owner, $museNames, true)) {
                        http_response_code(400);
                        echo json_encode(array('ok' => false, 'error' => '负责人不在 Muse 名单里'), JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                }
                $upd = $pdo->prepare("UPDATE rooms SET muse_owner = :owner WHERE id = :id");
                $upd->execute(array(':owner' => ($owner === '' ? null : $owner), ':id' => $roomId));
                echo json_encode(array('ok' => true, 'id' => $roomId, 'muse_owner' => $owner), JSON_UNESCAPED_UNICODE);
                exit;
            }
            // 删除：主群（id=1）不能删；旁聊连它里面的全部消息一起删，事务里做，要么全删要么全留。
            if ($roomId === 1) {
                http_response_code(400);
                echo json_encode(array('ok' => false, 'error' => '主群不能删除'), JSON_UNESCAPED_UNICODE);
                exit;
            }
            $pdo->beginTransaction();
            $del1 = $pdo->prepare("DELETE FROM messages WHERE room_id = :id");
            $del1->execute(array(':id' => $roomId));
            $del2 = $pdo->prepare("DELETE FROM rooms WHERE id = :id");
            $del2->execute(array(':id' => $roomId));
            $pdo->commit();
            echo json_encode(array('ok' => true, 'id' => $roomId), JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($name === '') {
            http_response_code(400);
            echo json_encode(array('ok' => false, 'error' => '名字为空'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (mb_strlen($name, 'UTF-8') > 20) {
            http_response_code(400);
            echo json_encode(array('ok' => false, 'error' => '名字太长，最多 20 字'), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO rooms (name, created_by) VALUES (:name, :uid)");
        $stmt->execute(array(':name' => $name, ':uid' => (int)$user['id']));
        echo json_encode(array('ok' => true, 'id' => (int)$pdo->lastInsertId(), 'name' => $name), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // GET：房间列表，按 id 升序（主群 id=1 在最上面）；每间带最后一条消息的摘要和时间，
    // 再加 unread（这间有没有你还没看的消息，有就边栏显示蓝色小圆点）
    // 和 archived（这间是不是你归档的，归档的收进边栏“已归档”区）。
    $stmt = $pdo->query("SELECT r.id, r.name,
                                (SELECT m.content FROM messages m WHERE m.room_id = r.id ORDER BY m.id DESC LIMIT 1) AS last_content,
                                (SELECT m.created_at FROM messages m WHERE m.room_id = r.id ORDER BY m.id DESC LIMIT 1) AS last_at
                         FROM rooms r ORDER BY r.id ASC LIMIT 100");
    $rooms = $stmt ? $stmt->fetchAll() : array();
    $unreadMap = rooms_unread_map($pdo, (int)$user['id'], is_admin($user));
    $archivedSet = rooms_archived_set($pdo, (int)$user['id']);
    foreach ($rooms as &$rm) {
        $lc = isset($rm['last_content']) ? (string)$rm['last_content'] : '';
        $lc = preg_split('/\r\n|\r|\n/', $lc, 2);
        $lc = $lc[0];
        if (mb_strlen($lc, 'UTF-8') > 40) $lc = mb_substr($lc, 0, 40, 'UTF-8') . '…';
        $rm['last_preview'] = $lc;
        $rm['unread'] = !empty($unreadMap[(int)$rm['id']]);
        $rm['archived'] = !empty($archivedSet[(int)$rm['id']]);
        unset($rm['last_content']);
    }
    unset($rm);
    echo json_encode(array('ok' => true, 'rooms' => $rooms), JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => '读取失败'), JSON_UNESCAPED_UNICODE);
}
