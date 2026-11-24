<?php
/**
 * lib/push.php —— 实时推送（WebSocket）通知。
 * 发消息 / Muse 回帖 / 审核后，调 push_notify() 通知推送服务（Node.js + ws，
 * 见 push-server/push-server.js），推送服务再把消息广播给连着 WebSocket 的
 * 浏览器，群里秒显示，不用等 3 秒轮询。
 * 推送是“尽力而为”：推送服务挂了、连不上、超时，都不影响发消息本身
 * （前端还有轮询兜底，最多晚 30 秒）。所以这里绝不抛错、不阻塞调用方。
 * 配置（config.local.php 里可填，不填就用默认）：
 *   'push_host' => '127.0.0.1'，'push_port' => 8282
 * PHP 7.4+ 兼容。
 */
if (!defined('CHAT_APP')) {
    define('CHAT_APP', true);
}

function push_server_addr()
{
    $host = '127.0.0.1';
    $port = 8282;
    if (function_exists('app_config_get')) {
        $h = app_config_get('push_host', '');
        if (is_string($h) && $h !== '') {
            $host = $h;
        }
        $p = (int)app_config_get('push_port', 0);
        if ($p > 0) {
            $port = $p;
        }
    }
    return array($host, $port);
}

/**
 * 通知推送服务广播一个事件。
 * $payload：已经拼好的广播体，例如：
 *   array('type' => 'message', 'room_id' => 1, 'message' => $row)
 *   array('type' => 'review', 'room_id' => 1, 'message_id' => 5, 'review_status' => 'rejected')
 * 返回 true/false（只表示“发出去了”，不保证对方收到）。失败一律静默。
 */
function push_notify($payload)
{
    if (!is_array($payload) || empty($payload['room_id']) || empty($payload['type'])) {
        return false;
    }
    list($host, $port) = push_server_addr();
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        return false;
    }
    $url = 'http://' . $host . ':' . $port . '/push';
    // 优先 curl（宝塔 PHP 默认带），超时设得很短，推送服务再慢也不拖发消息。
    if (function_exists('curl_init')) {
        $ch = curl_init();
        if ($ch === false) {
            return false;
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 500);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1000);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res !== false;
    }
    // 没 curl 就用 file_get_contents（同样设 1 秒超时）。
    $ctx = stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => 1,
            'ignore_errors' => true,
        ),
    ));
    $res = @file_get_contents($url, false, $ctx);
    return $res !== false;
}

/**
 * 按 id 取一条消息的完整行（字段和 fetch.php 返回的一致，方便前端直接渲染），
 * 另带 room_id（推送要按房间路由）。取不到返回 null。
 */
function push_message_by_id($pdo, $messageId)
{
    try {
        $stmt = $pdo->prepare(
            "SELECT m.id, m.room_id, m.user_id, m.username, m.content, m.created_at,
                    m.is_muse_reply, m.reply_to_id, m.review_status,
                    r.username AS reply_username, r.content AS reply_content
             FROM messages m
             LEFT JOIN messages r ON r.id = m.reply_to_id
             WHERE m.id = :id LIMIT 1"
        );
        $stmt->execute(array(':id' => (int)$messageId));
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        // 数字字段转 int，和 fetch.php 的 JSON 保持一致（前端按数字比较）。
        $row['id'] = (int)$row['id'];
        $row['room_id'] = (int)$row['room_id'];
        $row['user_id'] = (int)$row['user_id'];
        $row['is_muse_reply'] = (int)$row['is_muse_reply'];
        $row['reply_to_id'] = $row['reply_to_id'] === null ? null : (int)$row['reply_to_id'];
        return $row;
    } catch (Exception $e) {
        return null;
    }
}
