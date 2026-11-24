<?php
/**
 * 管理员页（admin.php）—— 仅管理员。
 * 加用户 / 删用户 / 改某人能不能指挥助手（can_command）。
 * “允许指挥”就是发信息的开关：只有被点过“允许指挥”的人（和 admin）
 * 能发信息；没被点过的人发不了信息（send.php 直接拒绝，不进待审核队列）。
 * 所有成员都能看到消息（fetch.php 里成员也能看到待审核的），所以不存在
 * “发了别人看不到”的情况。
 * 审核队列：列出 review_status='pending' 的老消息（新发的消息不会再进这里，
 * 因为没被允许指挥的人发不出去、被允许的人直接通过）；点“不通过”可以把
 * 某条消息从普通成员眼前隐藏（只有发信人自己还能看到）。
 * 每条可“通过”（改 approved，在群里显示）或“不通过”（改 rejected，不显示）。
 * 管理员自己的消息在发送时已是 approved，不会进队列，所以队列里没有自己的消息可审。
 * 保护：不能删自己，不能删管理员账号；新用户密码用 password_hash() 存。
 */
define('CHAT_APP', true);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/muse_names.php';

$me = require_admin();
$pdo = db_pdo();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
        $err = 'CSRF 校验失败，请刷新重试。';
    } else {
        $action = isset($_POST['action']) ? $_POST['action'] : '';
        if ($action === 'add') {
            $username = isset($_POST['username']) ? trim($_POST['username']) : '';
            $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
            $role = (isset($_POST['role']) && $_POST['role'] === 'admin') ? 'admin' : 'member';
            // 只有管理员亲口指定时才勾这个；默认不勾（member 默认不能指挥）。
            $canCommand = !empty($_POST['can_command']) ? 1 : 0;
            if ($username === '' || $password === '' || mb_strlen($username, 'UTF-8') > 64) {
                $err = '用户名和密码都要填，用户名最多 64 字。';
            } elseif (mb_strlen($password, 'UTF-8') < 6) {
                $err = '密码至少 6 位。';
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, can_command)
                                           VALUES (:u, :p, :r, :c)");
                    $stmt->execute(array(
                        ':u' => $username,
                        ':p' => password_hash($password, PASSWORD_DEFAULT),
                        ':r' => $role,
                        ':c' => $canCommand,
                    ));
                    $msg = '已添加用户 ' . $username . '。';
                } catch (PDOException $e) {
                    $err = '添加失败：用户名可能已存在。';
                }
            }
        } elseif ($action === 'delete') {
            $uid = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            if ($uid === (int)$me['id']) {
                $err = '不能删自己。';
            } else {
                $stmt = $pdo->prepare("SELECT username, role FROM users WHERE id = :id LIMIT 1");
                $stmt->execute(array(':id' => $uid));
                $row = $stmt->fetch();
                if (!$row) {
                    $err = '用户不存在。';
                } elseif ($row['role'] === 'admin') {
                    $err = '不能删管理员账号。';
                } else {
                    $pdo->prepare("DELETE FROM users WHERE id = :id")->execute(array(':id' => $uid));
                    $msg = '已删除用户 ' . $row['username'] . '（其历史消息保留）。';
                }
            }
        } elseif ($action === 'toggle_command') {
            $uid = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            $val = !empty($_POST['can_command']) ? 1 : 0;
            if ($uid === (int)$me['id']) {
                $err = '管理员的指挥权不用在这里改，默认就是能指挥。';
            } else {
                $pdo->prepare("UPDATE users SET can_command = :c WHERE id = :id")
                    ->execute(array(':c' => $val, ':id' => $uid));
                $msg = '已更新该用户的指挥权限。';
            }
        } elseif ($action === 'muse_add' || $action === 'muse_delete' || $action === 'muse_default') {
            // ＠ 名单（群里的 Muse）：存在 config.local.php 的 muse_names 里，
            // 回帖白名单、@ 候选、房间负责人校验都读这一处，改完立即生效。
            $names = muse_names_read();
            if ($action === 'muse_add') {
                $newName = isset($_POST['muse_name']) ? trim($_POST['muse_name']) : '';
                $check = muse_name_check($newName);
                if ($check !== '') {
                    $err = $check;
                } elseif (in_array($newName, $names, true)) {
                    $err = '名单里已经有“' . $newName . '”了。';
                } else {
                    $names[] = $newName;
                    $res = muse_names_write($names, $pdo);
                    if (!empty($res['ok'])) {
                        $msg = '已把“' . $newName . '”加进 ＠ 名单，群里现在就能 @ 他。';
                    } else {
                        $err = $res['error'];
                    }
                }
            } elseif ($action === 'muse_delete') {
                $delName = isset($_POST['muse_name']) ? trim($_POST['muse_name']) : '';
                $names = array_values(array_filter($names, function ($n) use ($delName) {
                    return $n !== $delName;
                }));
                $res = muse_names_write($names, $pdo);
                if (!empty($res['ok'])) {
                    $msg = '已把“' . $delName . '”从 ＠ 名单去掉。';
                } else {
                    $err = $res['error'];
                }
            } else {
                // 设为默认：挪到名单第一个。名单第一个是默认 Muse
                // （没 @ 任何人时默认等他回、名字不在名单里时也按他算）。
                $defName = isset($_POST['muse_name']) ? trim($_POST['muse_name']) : '';
                if (!in_array($defName, $names, true)) {
                    $err = '名单里没有“' . $defName . '”。';
                } else {
                    $names = array_values(array_filter($names, function ($n) use ($defName) {
                        return $n !== $defName;
                    }));
                    array_unshift($names, $defName);
                    $res = muse_names_write($names, $pdo);
                    if (!empty($res['ok'])) {
                        $msg = '已把“' . $defName . '”设为默认 Muse（名单第一个）。';
                    } else {
                        $err = $res['error'];
                    }
                }
            }
        } elseif ($action === 'review_approve' || $action === 'review_reject') {
            // 先审后发：通过改 approved（群里显示），不通过改 rejected（不显示）。
            // 只审当前还是 pending 的用户消息；助手回帖本来就是 approved，不进队列。
            $mid = isset($_POST['message_id']) ? (int)$_POST['message_id'] : 0;
            $newStatus = ($action === 'review_approve') ? 'approved' : 'rejected';
            $stmt = $pdo->prepare("UPDATE messages SET review_status = :r
                                   WHERE id = :id AND review_status = 'pending' AND is_muse_reply = 0");
            $stmt->execute(array(':r' => $newStatus, ':id' => $mid));
            if ($stmt->rowCount() > 0) {
                $msg = ($newStatus === 'approved') ? '已通过该消息，群里现在可以看到。' : '已不通过该消息，群里不会显示。';
            } else {
                $err = '该消息不存在或已审过。';
            }
        }
    }
}

$users = $pdo->query("SELECT id, username, role, can_command, created_at FROM users ORDER BY id ASC")->fetchAll();
// 先审后发审核队列：待审核的成员消息（管理员 自己的消息已是 approved，不进队列）。
$pending = $pdo->query("SELECT id, user_id, username, content, created_at FROM messages
                        WHERE review_status = 'pending' AND is_muse_reply = 0
                        ORDER BY id ASC")->fetchAll();
// ＠ 名单（群里的 Muse）：每次都重新读文件，刚保存完显示的就是新名单。
$museNames = muse_names_read();
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>管理用户 - <?php echo h(app_config_get('site_name', '群聊')); ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">
  <header>
    <h1>管理用户</h1>
    <div><a href="index.php">回群聊</a><a href="logout.php">退出</a></div>
  </header>
  <?php if ($msg !== ''): ?><p style="color:green;"><?php echo h($msg); ?></p><?php endif; ?>
  <?php if ($err !== ''): ?><p class="err"><?php echo h($err); ?></p><?php endif; ?>

  <div class="card">
    <h2 style="margin-top:0;font-size:16px;">待审核消息（先审后发）</h2>
    <p class="hint">这里是还没处理的老待审核消息（新发的消息不会再进这里：没被允许指挥的人发不出去，被允许的人直接通过）。普通成员现在也能看到待审核的消息；点“不通过”可以把某条消息从普通成员眼前隐藏（只有发信人自己还能看到），点“通过”只是把它正式标为通过。</p>
    <?php if (empty($pending)): ?>
      <p class="hint">当前没有待审核的消息。</p>
    <?php else: ?>
      <table>
        <tr><th>用户</th><th>内容</th><th>时间</th><th>操作</th></tr>
        <?php foreach ($pending as $pm): ?>
        <tr>
          <td><?php echo h($pm['username']); ?></td>
          <td><?php echo h($pm['content']); ?></td>
          <td><?php echo h($pm['created_at']); ?></td>
          <td>
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="action" value="review_approve">
              <input type="hidden" name="message_id" value="<?php echo (int)$pm['id']; ?>">
              <button type="submit" style="padding:4px 10px;">通过</button>
            </form>
            <form method="post" style="display:inline;" onsubmit="return confirm('确定不通过这条消息吗？群里不会显示它。');">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="action" value="review_reject">
              <input type="hidden" name="message_id" value="<?php echo (int)$pm['id']; ?>">
              <button type="submit" class="danger" style="padding:4px 10px;">不通过</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <div class="card" style="margin-top:12px;">
    <h2 style="margin-top:0;font-size:16px;">＠ 名单（群里的 Muse）</h2>
    <p class="hint">输入框 @ 出来的名单、回帖白名单、房间负责人校验都读这里。加新人不用再进宝塔改文件，在这里加就行，改完立即生效。名单第一个是默认 Muse（没 @ 任何人时默认等他回）。</p>
    <table>
      <tr><th>名字</th><th>默认</th><th>操作</th></tr>
      <?php foreach ($museNames as $i => $mn): ?>
      <tr>
        <td><?php echo h($mn); ?></td>
        <td><?php echo $i === 0 ? '是' : '否'; ?></td>
        <td>
          <?php if ($i !== 0): ?>
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="muse_default">
            <input type="hidden" name="muse_name" value="<?php echo h($mn); ?>">
            <button type="submit" style="padding:4px 10px;">设为默认</button>
          </form>
          <?php endif; ?>
          <form method="post" style="display:inline;" onsubmit="return confirm('确定从 ＠ 名单去掉 <?php echo h($mn); ?> 吗？他就收不到 @ 了，历史回帖不受影响。');">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="muse_delete">
            <input type="hidden" name="muse_name" value="<?php echo h($mn); ?>">
            <button type="submit" class="danger" style="padding:4px 10px;">去掉</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <form method="post" style="margin-top:10px;">
      <label>加一个 Muse（名字 1–20 字，不能含空格、@、%、_、\，不能和注册用户名重名）</label>
      <input type="text" name="muse_name" required maxlength="20" placeholder="比如：助手">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <input type="hidden" name="action" value="muse_add">
      <input type="submit" value="加进 ＠ 名单">
    </form>
  </div>

  <div class="card" style="margin-top:12px;">
    <h2 style="margin-top:0;font-size:16px;">现有用户</h2>
    <table>
      <tr><th>用户名</th><th>角色</th><th>能指挥助手</th><th>创建时间</th><th>操作</th></tr>
      <?php foreach ($users as $u): ?>
      <tr>
        <td><?php echo h($u['username']); ?></td>
        <td><?php echo h($u['role']); ?></td>
        <td><?php echo !empty($u['can_command']) ? '是' : '否'; ?></td>
        <td><?php echo h($u['created_at']); ?></td>
        <td>
          <?php if ((int)$u['id'] !== (int)$me['id'] && $u['role'] !== 'admin'): ?>
          <form method="post" style="display:inline;" onsubmit="return confirm('确定删除 <?php echo h($u['username']); ?> 吗？');">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
            <button type="submit" class="danger" style="padding:4px 10px;">删除</button>
          </form>
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="toggle_command">
            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
            <input type="hidden" name="can_command" value="<?php echo empty($u['can_command']) ? '1' : '0'; ?>">
            <button type="submit" style="padding:4px 10px;background:#666;"><?php echo empty($u['can_command']) ? '允许指挥' : '取消指挥'; ?></button>
          </form>
          <?php else: ?><span class="hint">—</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <p class="hint">默认只有管理员能指挥助手。“允许指挥”只有在管理员亲口指定某人时才点，不要随便开。点过“允许指挥”的人才能发信息（也能指挥助手）；没点过的人发不了信息，但能看到群里的所有消息。取消指挥后，他就发不了信息了。</p>
  </div>

  <div class="card" style="margin-top:12px;">
    <h2 style="margin-top:0;font-size:16px;">添加用户</h2>
    <form method="post">
      <label>用户名</label>
      <input type="text" name="username" required maxlength="64">
      <label>初始密码（至少 6 位，让对方登录后自己改）</label>
      <input type="password" name="password" required>
      <label>角色</label>
      <select name="role"><option value="member">普通成员</option><option value="admin">管理员</option></select>
      <label><input type="checkbox" name="can_command" value="1" style="width:auto;"> 允许指挥助手（默认不勾；只有 管理员 亲口指定时才勾；勾了以后这个人才能发信息，也能指挥助手；不勾的人发不了信息，但能看到群里的所有消息）</label><br><br>
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <input type="hidden" name="action" value="add">
      <input type="submit" value="添加">
    </form>
  </div>
</div>
</body>
</html>
