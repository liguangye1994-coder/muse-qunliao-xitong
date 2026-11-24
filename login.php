<?php
/**
 * 登录页（login.php）
 * 用户名 + 密码，密码用 password_verify() 对 password_hash 校验。
 * 登录失败不提示“用户名错还是密码错”，只给一句通用提示。
 */
define('CHAT_APP', true);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';

start_chat_session();
if (current_user() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
    if ($username === '' || $password === '') {
        $error = '请填写用户名和密码。';
    } else {
        try {
            $pdo = db_pdo();
            $stmt = $pdo->prepare("SELECT id, username, password_hash, role FROM users WHERE username = :u LIMIT 1");
            $stmt->execute(array(':u' => $username));
            $row = $stmt->fetch();
            if ($row && password_verify($password, $row['password_hash'])) {
                login_user($row);
                header('Location: index.php');
                exit;
            }
            $error = '用户名或密码不对。';
        } catch (Exception $e) {
            $error = '数据库未配置或连不上，请联系管理员。';
        }
    }
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>登录 - <?php echo h(app_config_get('site_name', '群聊')); ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">
  <div class="card" style="max-width:420px;margin:60px auto;">
    <h1 style="font-size:20px;margin-top:0;">登录群聊</h1>
    <?php if ($error !== ''): ?><p class="err"><?php echo h($error); ?></p><?php endif; ?>
    <form method="post" action="login.php">
      <label>用户名</label>
      <input type="text" name="username" required maxlength="64" autocomplete="username">
      <label>密码</label>
      <input type="password" name="password" required autocomplete="current-password">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <input type="submit" value="登录">
    </form>
    <p class="hint">没有账号请找管理员（管理员）添加，本站不开放注册。</p>
  </div>
</div>
</body>
</html>
