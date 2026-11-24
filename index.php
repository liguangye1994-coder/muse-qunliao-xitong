<?php
/**
 * 群聊首页（你的域名/chat/index.php）
 * 未登录 -> 跳 login.php；已登录 -> 显示本群消息 + 发消息框。
 * 只显示本群 messages 表的内容，不拉其他站 / 其他群。
 * 先审后发：只显示 review_status='approved' 的消息，加上自己发的
 * pending 消息（标“待审核，仅自己可见”）；别人的 pending 和 rejected 不显示。
 * 群内直接审核：管理员还能看到所有人的 pending 消息，每条下面有
 * “通过 / 不通过”按钮，点后调 review.php，不用再去管理页。
 * 旁聊（房间）：左边栏是会话列表，像 Muse 聊天界面那样——最上面是主群
 * （id=1，叫“群聊”），下面是按项目开的旁聊（做视频/生图/代码各一个）。
 * 点边栏里的会话就切到那个对话，不刷新整页（app.js 调 fetch.php?initial=1）。
 * ?room=N 可直接打开指定房间，不存在或不传就默认主群。房间用 rooms.php 新建。
 * 输入框钉在页面最底下：整页是占满视口的 flex，消息区滚动、输入区不动。
 * PHP 7.4+ 兼容。
 */
define('CHAT_APP', true);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/reactions.php';

// 文件名里常有中文：tmpfile.link 直链把原文件名放在路径里（…/转刀游戏-….zip、
// …/伽罗玩法-复刻-10秒竖屏.mp4），以前链接匹配排除中文，URL 在第一个汉字处
// 就被截断——后缀丢了，图片/视频不出预览，点开还是断链。所以 URL 里允许汉字，
// 全角标点和空格仍不算链接的一部分；匹配完再把末尾粘上的中文说明剥下来。
// 图片 / 视频 inline 预览（点图灯箱放大）；文件链接只显示文件名，点文件名
// 直接下载；普通网页链接仍显示整条地址。只在渲染时做，数据库里存的还是原文。
// （与 assets/app.js 里的 linkify / fileNameOf 保持一致，改一边要改另一边。）
function file_name_of_link($url) {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $path = (string)parse_url($url, PHP_URL_PATH);
    $isFileHost = (bool)preg_match('/(^|\.)tmpfile\.link$/', $host) || (bool)preg_match('/(^|\.)tempfilestorage\.link$/', $host);
    $isFileExt = (bool)preg_match('/\.(zip|rar|7z|tar|gz|apk|pdf|doc|docx|xls|xlsx|ppt|pptx|txt|md|epub|mobi|exe|dmg|iso|mp3|wav|ogg|flac)$/i', $path);
    if (!$isFileHost && !$isFileExt) return '';
    $seg = substr($path, (int)strrpos($path, '/') + 1);
    $seg = str_replace('&amp;', '&', $seg);
    $seg = rawurldecode($seg);
    if ($seg === '') return '';
    if (!$isFileHost && strpos($seg, '.') === false) return '';
    return $seg;
}
function render_chat_content($text) {
    $s = htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    return preg_replace_callback('#https?://[^\s<>"\'\x{2000}-\x{206F}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]+#iu', function ($m) {
        $url = $m[0];
        $trail = '';
        if (preg_match('/^(.*?)([。，、；：！？）】》.,!?;:)\]}]+)$/u', $url, $mm)) {
            $url = $mm[1];
            $trail = $mm[2];
        }
        if ($url === '') return $m[0];
        $path = strtolower((string)parse_url($url, PHP_URL_PATH));
        // 末尾粘了中文说明、且路径没有已知文件后缀时，把末尾那串汉字剥下来还给正文。
        if (!preg_match('/\.(jpg|jpeg|png|gif|webp|bmp|mp4|webm|mov|ogv|zip|rar|7z|tar|gz|apk|pdf|doc|docx|xls|xlsx|ppt|pptx|txt|md|epub|mobi|exe|dmg|iso|mp3|wav|ogg|flac)$/', $path)) {
            if (preg_match('/^(.*?)([\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]+)$/u', $url, $cm) && strlen($cm[1]) > 10) {
                $url = $cm[1];
                $trail = $cm[2] . $trail;
                $path = strtolower((string)parse_url($url, PHP_URL_PATH));
            }
        }
        if (preg_match('/\.(jpg|jpeg|png|gif|webp|bmp)$/', $path)) {
            return '<a href="' . $url . '" target="_blank" rel="noopener"><img src="' . $url . '" alt="图片" loading="lazy"></a>' . $trail;
        }
        if (preg_match('/\.(mp4|webm|mov|ogv)$/', $path)) {
            return '<span class="media-wrap"><video controls preload="metadata" src="' . $url . '"></video><button type="button" class="media-expand" title="放大" aria-label="放大视频">⛶</button></span>' . $trail;
        }
        $fname = file_name_of_link($url);
        if ($fname !== '') {
            $fnameEsc = htmlspecialchars($fname, ENT_QUOTES, 'UTF-8');
            return '<a class="file-link" href="' . $url . '" target="_blank" rel="noopener" download="' . $fnameEsc . '" title="' . $url . '">📎 ' . $fnameEsc . '</a>' . $trail;
        }
        return '<a href="' . $url . '" target="_blank" rel="noopener">' . $url . '</a>' . $trail;
    }, $s);
}

// 边栏一条旁聊（主列表和“已归档”列表共用）：头像首字 + 名字 + 最后一条摘要 + 时间，
// 有未读消息（且不是正在看的这间）时左上角挂蓝色小圆点。
// $isArchived 为真时菜单里是“取消归档”，否则是“归档”；主群（id=1）不能归档，
// 所以主群的菜单里没有归档项。归档/取消归档人人能点（只影响自己的边栏）；
// 改名/删除只有管理员，普通成员的菜单里只有归档一项。
function room_row_html($rm, $currentRoomId, $unreadMap, $isAdmin, $isArchived) {
    $id = (int)$rm['id'];
    $name = (string)$rm['name'];
    $html = '<li class="room-row">';
    $html .= '<button type="button" class="side-item room-item' . ($id === $currentRoomId ? ' active' : '') . '" data-room-id="' . $id . '" data-room-name="' . h($name) . '">';
    if (!empty($unreadMap[$id]) && $id !== $currentRoomId) {
        $html .= '<span class="unread-dot" aria-label="有未读消息"></span>';
    }
    $html .= '<span class="side-avatar">' . h(mb_substr($name, 0, 1, 'UTF-8')) . '</span>';
    $html .= '<span class="side-text"><span class="side-name">' . h($name) . '</span>';
    if ($rm['last_preview'] !== '') {
        $html .= '<span class="side-preview">' . h($rm['last_preview']) . '</span>';
    }
    $html .= '</span>';
    if ($rm['last_time'] !== '') {
        $html .= '<span class="side-time">' . h($rm['last_time']) . '</span>';
    }
    $html .= '</button>';
    $menuItems = '';
    if ($id !== 1) {
        if ($isArchived) {
            $menuItems .= '<button type="button" data-act="unarchive" data-room-id="' . $id . '" data-room-name="' . h($name) . '">📤 取消归档</button>';
        } else {
            $menuItems .= '<button type="button" data-act="archive" data-room-id="' . $id . '" data-room-name="' . h($name) . '">📥 归档</button>';
        }
    }
    if ($isAdmin) {
        $menuItems .= '<button type="button" data-act="rename" data-room-id="' . $id . '" data-room-name="' . h($name) . '">✎ 改名</button>';
        if ($id !== 1) {
            $menuItems .= '<button type="button" data-act="delete" data-room-id="' . $id . '" data-room-name="' . h($name) . '">🗑 删除</button>';
        }
    }
    if ($menuItems !== '') {
        $html .= '<button type="button" class="room-menu-btn" data-room-id="' . $id . '" aria-label="管理“' . h($name) . '”">⋯</button>';
        $html .= '<div class="room-menu" style="display:none;">' . $menuItems . '</div>';
    }
    $html .= '</li>';
    return $html;
}

// 群聊页不缓存：手机浏览器会把昨晚的页面快照留着，刷新也不拉新消息，
// 看到的最后一条就一直停在昨晚。no-store 强制每次都向服务器取最新页。
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$user = require_login();
$pdo = db_pdo();
$isAdmin = is_admin($user);

// 当前房间：?room=N，不存在或不传默认主群（id=1）。
$roomId = isset($_GET['room']) ? (int)$_GET['room'] : 1;
if ($roomId < 1) $roomId = 1;
$roomName = '群聊';
try {
    $rst = $pdo->prepare("SELECT name FROM rooms WHERE id = :id LIMIT 1");
    $rst->execute(array(':id' => $roomId));
    $rrow = $rst->fetch();
    if ($rrow) {
        $roomName = (string)$rrow['name'];
    } else {
        $roomId = 1;
        $roomName = '群聊';
    }
} catch (Exception $e) { /* rooms 表没建好（迁移 SQL 没跑）就当只有主群 */ }
// 边栏会话列表：全部房间，每间带最后一条消息的摘要和时间，按 id 升序（主群在最上面）。
$rooms = array();
try {
    $lst = $pdo->query("SELECT r.id, r.name,
                               (SELECT m.content FROM messages m WHERE m.room_id = r.id ORDER BY m.id DESC LIMIT 1) AS last_content,
                               (SELECT m.created_at FROM messages m WHERE m.room_id = r.id ORDER BY m.id DESC LIMIT 1) AS last_at
                        FROM rooms r ORDER BY r.id ASC LIMIT 100");
    $rooms = $lst ? $lst->fetchAll() : array();
    if (empty($rooms)) {
        $rooms = array(array('id' => 1, 'name' => '群聊', 'last_content' => '', 'last_at' => null));
    }
} catch (Exception $e) {
    $rooms = array(array('id' => 1, 'name' => '群聊', 'last_content' => '', 'last_at' => null));
}
foreach ($rooms as &$rm) {
    $lc = isset($rm['last_content']) ? (string)$rm['last_content'] : '';
    $lc = preg_split('/\r\n|\r|\n/', $lc, 2);
    $lc = $lc[0];
    if (mb_strlen($lc, 'UTF-8') > 30) $lc = mb_substr($lc, 0, 30, 'UTF-8') . '…';
    $rm['last_preview'] = $lc;
    $rm['last_time'] = '';
    if (!empty($rm['last_at'])) {
        $rm['last_time'] = date('m-d H:i', strtotime((string)$rm['last_at']));
    }
    unset($rm['last_content'], $rm['last_at']);
}
unset($rm);
// 未读蓝点：哪间有你还没看的消息，那间左上角显示蓝色小圆点（像 Muse 那样）。
// 正在看的这间不显示（你正在看它，打开页面后前端会把它标已读）。
// rooms_unread_map 回的是每间的未读条数（0 = 已读），边栏按 !empty() 判蓝点，
// 标签页标题按下面 $unreadTotal 挂总数。
$unreadMap = rooms_unread_map($pdo, (int)$user['id'], $isAdmin);
// 归档：各归各的——你归档的旁聊只从你自己的边栏收进“已归档”区，不影响别人。
$archivedSet = rooms_archived_set($pdo, (int)$user['id']);
$roomsActive = array();
$roomsArchived = array();
foreach ($rooms as $rm) {
    if (!empty($archivedSet[(int)$rm['id']])) {
        $roomsArchived[] = $rm;
    } else {
        $roomsActive[] = $rm;
    }
}
// 标签页标题的未读总数：只算没归档的房间（归档的只在“已归档”区里亮蓝点），
// 正在看的这间不算。
$unreadTotal = 0;
foreach ($unreadMap as $rid => $cnt) {
    if ((int)$rid === $roomId) continue;
    if (!empty($archivedSet[(int)$rid])) continue;
    $unreadTotal += (int)$cnt;
}
$archivedUnread = false;
foreach ($roomsArchived as $rm) {
    if (!empty($unreadMap[(int)$rm['id']])) { $archivedUnread = true; break; }
}

// 首次打开拉当前房间最近 50 条（照 Muse 聊天界面的做法：先看最近的，往上翻到顶
// 再加载更早的 50 条；页上最多留 200 条，旧的自动从页面上收掉、不是删数据）。
// 之后靠 assets/app.js 每 3 秒拉增量。管理员首屏也能看到所有人的 pending 消息，直接在群里审。
if ($isAdmin) {
    $stmt = $pdo->prepare("SELECT m.id, m.user_id, m.username, m.content, m.created_at, m.is_muse_reply, m.reply_to_id, m.review_status,
                                  r.username AS reply_username, r.content AS reply_content
                           FROM messages m
                           LEFT JOIN messages r ON r.id = m.reply_to_id
                           WHERE m.room_id = :room
                             AND (m.review_status = 'approved' OR m.review_status = 'pending')
                           ORDER BY m.id DESC LIMIT 50");
    $stmt->execute(array(':room' => $roomId));
} else {
    $stmt = $pdo->prepare("SELECT m.id, m.user_id, m.username, m.content, m.created_at, m.is_muse_reply, m.reply_to_id, m.review_status,
                                  r.username AS reply_username, r.content AS reply_content
                           FROM messages m
                           LEFT JOIN messages r ON r.id = m.reply_to_id
                           WHERE m.room_id = :room
                             AND (m.review_status = 'approved'
                               OR (m.review_status = 'pending' AND m.user_id = :uid))
                           ORDER BY m.id DESC LIMIT 50");
    $stmt->execute(array(':room' => $roomId, ':uid' => (int)$user['id']));
}
$rows = array_reverse($stmt->fetchAll());
// 首屏消息挂上表情汇总（chips 用；没有表情的是空数组）。
$reactMap = array();
if (!empty($rows)) {
    $reactIds = array();
    foreach ($rows as $r) {
        $reactIds[] = (int)$r['id'];
    }
    $reactMap = reactions_summary_for($pdo, $reactIds, (string)$user['username']);
}
$lastId = 0;
foreach ($rows as $r) {
    if ((int)$r['id'] > $lastId) $lastId = (int)$r['id'];
}
$csrf = csrf_token();
// @ 列表的名单：和回帖白名单读同一处配置（config.local.php 的 muse_names）。
$museNames = app_config_get('muse_names', array('助手'));
if (!is_array($museNames) || empty($museNames)) {
    $museNames = array('助手');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h(app_config_get('site_name', '群聊')); ?> - 群聊</title>
<link rel="stylesheet" href="assets/style.css?v=<?php echo @filemtime(__DIR__ . '/assets/style.css'); ?>">
</head>
<body>
<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="side-head"><span class="side-title"><?php echo h(app_config_get('site_name', '群聊')); ?></span><button id="side-close" type="button" aria-label="收起边栏">×</button></div>
    <button id="new-room-btn" type="button">＋ 新建旁聊</button>
    <ul class="side-list room-list">
      <?php foreach ($roomsActive as $rm): ?>
      <?php echo room_row_html($rm, $roomId, $unreadMap, $isAdmin, false); ?>
      <?php endforeach; ?>
    </ul>
    <div class="archived-sec" id="archived-sec"<?php if (empty($roomsArchived)) echo ' style="display:none;"'; ?>>
      <button type="button" class="archived-toggle" id="archived-toggle" aria-expanded="false"><span class="archived-caret">▸</span><span>已归档</span><span class="archived-count" id="archived-count"><?php if (!empty($roomsArchived)) echo '（' . count($roomsArchived) . '）'; ?></span><span class="unread-dot archived-dot" id="archived-dot"<?php if (!$archivedUnread) echo ' style="display:none;"'; ?> aria-label="已归档的旁聊有未读消息"></span></button>
      <ul class="side-list archived-list" id="archived-list" style="display:none;">
        <?php foreach ($roomsArchived as $rm): ?>
        <?php echo room_row_html($rm, $roomId, $unreadMap, $isAdmin, true); ?>
        <?php endforeach; ?>
      </ul>
    </div>
  </aside>
  <div id="side-backdrop"></div>
  <div class="wrap main">
  <header>
    <button id="side-toggle" type="button" aria-label="菜单">☰</button>
    <h1 id="room-title"><?php echo h($roomName); ?></h1>
    <div>
      <span><?php echo h($user['username']); ?><?php if ($user['role'] === 'admin') echo '（管理员）'; ?></span>
      <?php if ($user['role'] === 'admin'): ?><a href="admin.php">管理用户</a><?php endif; ?>
      <a href="logout.php">退出</a>
    </div>
  </header>

  <div class="card chat-card">
    <div id="msg-list" data-last-id="<?php echo $lastId; ?>" data-is-admin="<?php echo $isAdmin ? '1' : '0'; ?>" data-me-id="<?php echo (int)$user['id']; ?>" data-me-name="<?php echo h($user['username']); ?>" data-room-id="<?php echo $roomId; ?>" data-unread-total="<?php echo $unreadTotal; ?>">
      <div id="older-status" style="display:none;"></div>
      <?php foreach ($rows as $m): ?>
      <?php $isPending = (isset($m['review_status']) && $m['review_status'] === 'pending'); ?>
      <?php $canReview = $isAdmin && $isPending && empty($m['is_muse_reply']); ?>
      <?php $isOwn = ((int)$m['user_id'] === (int)$user['id']); ?>
      <?php $avatarChar = !empty($m['is_muse_reply']) ? mb_substr((string)$m['username'], -1, 1, 'UTF-8') : mb_substr((string)$m['username'], 0, 1, 'UTF-8'); ?>
      <?php $replySnippet = ''; if (!empty($m['reply_to_id']) && isset($m['reply_username'])) { $rc = preg_split('/\r\n|\r|\n/', (string)$m['reply_content'], 2); $rc = $rc[0]; if (mb_strlen($rc, 'UTF-8') > 60) $rc = mb_substr($rc, 0, 60, 'UTF-8') . '…'; $replySnippet = $rc; } ?>
      <div class="msg<?php if (!empty($m['is_muse_reply'])) echo ' muse'; ?><?php if ($isPending) echo ' pending'; ?><?php if ($isOwn) echo ' own'; ?>" data-id="<?php echo (int)$m['id']; ?>">
        <div class="msg-row">
          <div class="avatar"><?php echo h($avatarChar); ?></div>
          <div class="msg-content">
            <div class="meta"><b><?php echo h($m['username']); ?></b> · <?php echo h($m['created_at']); ?><?php if ($isPending) echo ' · <span class="review-tag">待审核' . ($canReview ? '' : '，仅自己可见') . '</span>'; ?> <button type="button" class="reply-btn" data-id="<?php echo (int)$m['id']; ?>" data-user="<?php echo h($m['username']); ?>">↩ 回复</button></div>
            <?php if ($replySnippet !== '' || !empty($m['reply_to_id'])): ?><div class="quote">↩ <?php echo h((string)$m['reply_username']); ?>：<?php echo h($replySnippet); ?></div><?php endif; ?>
            <div class="body"><?php echo render_chat_content($m['content']); ?></div>
            <?php echo reactions_html(isset($reactMap[(int)$m['id']]) ? $reactMap[(int)$m['id']] : array(), (int)$m['id'], (string)$user['username']); ?>
          </div>
        </div>
        <?php if ($canReview): ?>
        <div class="review-actions">
          <button type="button" data-review="approve" data-id="<?php echo (int)$m['id']; ?>">通过</button>
          <button type="button" class="danger" data-review="reject" data-id="<?php echo (int)$m['id']; ?>">不通过</button>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php if (empty($rows)): ?><p class="hint">群里还没有消息，发第一条吧。</p><?php endif; ?>
    </div>

    <div id="reply-bar" style="display:none;"><span id="reply-bar-text"></span><button type="button" id="reply-cancel" aria-label="取消回复">×</button></div>
    <div id="attach-bar" style="display:none;"></div>
    <div id="drop-overlay">松开上传文件</div>
    <form id="send-form" class="send-row" data-csrf="<?php echo h($csrf); ?>" data-room-id="<?php echo $roomId; ?>">
      <button id="new-msg-pill" type="button" style="display:none;">↓ 有新消息</button>
      <button id="upload-btn" type="button" aria-label="上传文件" title="上传文件">＋</button>
      <button id="mention-btn" type="button" aria-label="@ Muse" title="@ Muse">@</button>
      <textarea id="msg-input" rows="1" maxlength="5000" placeholder="发消息到“<?php echo h($roomName); ?>”…" autocomplete="off"></textarea>
      <button type="submit" aria-label="发送">↑</button>
      <input type="file" id="file-input" hidden multiple>
    </form>
  </div>
  </div>
</div>
<script>var MUSE_NAMES = <?php echo json_encode($museNames, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="assets/app.js?v=<?php echo @filemtime(__DIR__ . '/assets/app.js'); ?>"></script>
</body>
</html>
