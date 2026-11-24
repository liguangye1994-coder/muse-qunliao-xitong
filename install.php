<?php
/**
 * 群聊站安装向导 install.php
 *
 * 用法：把本仓库传到网站根目录（PHP 7.4+、MySQL 5.7+/8.0），浏览器打开
 * https://你的域名/install.php，按步骤走完即可。
 *
 * 步骤：0 环境检查 → 1 数据库 → 2 管理员账号 → 3 AI 助手
 *       → 4 唤醒模式 → 5 确认 → 6 完成。
 *
 * 装完请立即删除本文件（留着会被别人重装覆盖配置）。
 * 本文件不写任何真实密码、真实 token；Muse token 由向导现场生成，只在成功页显示一次。
 * PHP 7.4+ 兼容。
 */
session_start();

// ---------- 下载接口：装好后下载已填好站点地址的 hook 脚本 ----------
// 只允许下载白名单里的文件，且只有装好（config.local.php 存在）后才放行。
$DOWNLOADS = array(
    'hook-wake-check.sh'      => 'muse/hook-wake-check.sh',
    'hook-watchdog-a.sh'      => 'muse/hook-watchdog-a.sh',
    'hook-watchdog-b.sh'      => 'muse/hook-watchdog-b.sh',
    'hook-assign-followup.py' => 'muse/hook-assign-followup.py',
    'hook-assign-followup.sh' => 'muse/hook-assign-followup.sh',
    'cron-monitor.md'         => 'muse/cron-monitor.md',
);
if (isset($_GET['download'])) {
    $dl = (string)$_GET['download'];
    if (!isset($DOWNLOADS[$dl]) || !is_file(__DIR__ . '/config.local.php')) {
        http_response_code(404);
        exit('not found');
    }
    $path = __DIR__ . '/' . $DOWNLOADS[$dl];
    if (!is_file($path)) {
        http_response_code(404);
        exit('not found');
    }
    $content = file_get_contents($path);
    // 把占位域名换成当前站点的真实地址。
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $siteUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
    $content = str_replace('https://你的域名', $siteUrl, $content);
    $content = str_replace('你的域名', $_SERVER['HTTP_HOST'], $content);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $dl . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

// ---------- 已安装保护 ----------
if (is_file(__DIR__ . '/config.local.php')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>已安装</title></head><body style="font-family:sans-serif;max-width:640px;margin:40px auto;padding:0 16px;line-height:1.7;">';
    echo '<h2>本站已经安装过了</h2><p>config.local.php 已存在。为防止重装覆盖配置，本向导不再运行。</p><p>要重新安装，请先删除服务器上的 config.local.php，再刷新本页。</p></body></html>';
    exit;
}

// ---------- 小工具 ----------
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function site_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'];
}

// 把 SQL 文件按语句切开（本站 SQL 没有触发器/存储过程，语句以 ";\n" 结尾）。
function split_sql($sql) {
    $lines = explode("\n", $sql);
    $kept = array();
    foreach ($lines as $ln) {
        if (strpos(ltrim($ln), '--') === 0) continue;
        $kept[] = $ln;
    }
    $sql = implode("\n", $kept);
    $parts = explode(";\n", $sql);
    $out = array();
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $out[] = $p . ';';
    }
    return $out;
}

// 递归复制目录（跳过指定项）。
function copy_tree($src, $dst, $skipNames) {
    if (!is_dir($dst)) mkdir($dst, 0755, true);
    $items = scandir($src);
    if ($items === false) return;
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        if (in_array($it, $skipNames, true)) continue;
        $s = $src . '/' . $it;
        $d = $dst . '/' . $it;
        if (is_dir($s)) {
            copy_tree($s, $d, $skipNames);
        } else {
            copy($s, $d);
        }
    }
}

// 名字规则：1–20 字，字母/数字/-/中文，不含空格/@/%/_/\ 等（和站内 ＠ 名单规则一致）。
function valid_name($s) {
    if (!is_string($s)) return false;
    $len = mb_strlen($s, 'UTF-8');
    if ($len < 1 || $len > 20) return false;
    return (bool)preg_match('/^[A-Za-z0-9\-\x{4e00}-\x{9fa5}]+$/u', $s);
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 0;
if ($step < 0 || $step > 6) $step = 0;
$err = '';
$steps = array('环境检查', '数据库', '管理员账号', 'AI 助手', '唤醒模式', '确认', '完成');

function step_bar($cur, $steps) {
    $out = '<ol class="steps">';
    foreach ($steps as $i => $name) {
        $cls = ($i === $cur) ? ' class="cur"' : (($i < $cur) ? ' class="done"' : '');
        $out .= '<li' . $cls . '>' . ($i + 1) . '. ' . h($name) . '</li>';
    }
    $out .= '</ol>';
    return $out;
}

function page_head($title) {
    return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . ' - 群聊站安装向导</title><style>'
        . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;max-width:720px;margin:0 auto;padding:24px 16px 64px;color:#222;line-height:1.7;background:#fff}'
        . 'h1{font-size:22px;margin:8px 0 4px}h2{font-size:18px;margin:24px 0 8px}.sub{color:#666;margin:0 0 16px}'
        . '.steps{list-style:none;display:flex;flex-wrap:wrap;gap:6px;padding:0;margin:16px 0}.steps li{font-size:13px;color:#888;background:#f2f2f2;border-radius:999px;padding:4px 10px}.steps li.cur{background:#1677ff;color:#fff}.steps li.done{background:#e6f4ff;color:#1677ff}'
        . 'label{display:block;margin:14px 0 4px;font-weight:600}input[type=text],input[type=password],input[type=number],select{width:100%;box-sizing:border-box;padding:10px 12px;font-size:15px;border:1px solid #ccc;border-radius:8px}'
        . '.hint{color:#666;font-size:13px;margin:4px 0 0}.err{background:#fff1f0;border:1px solid #ffa39e;color:#cf1322;border-radius:8px;padding:10px 14px;margin:12px 0}'
        . '.ok{background:#f6ffed;border:1px solid #b7eb8f;color:#389e0d;border-radius:8px;padding:10px 14px;margin:12px 0}'
        . '.btn{display:inline-block;margin-top:20px;padding:11px 26px;font-size:16px;border:0;border-radius:8px;background:#1677ff;color:#fff;cursor:pointer}.btn.sec{background:#f0f0f0;color:#222}'
        . '.card{border:1px solid #ddd;border-radius:12px;padding:16px;margin:14px 0;cursor:pointer}.card.sel{border-color:#1677ff;box-shadow:0 0 0 2px #1677ff22}.card h3{margin:0 0 6px;font-size:16px}.card p{margin:4px 0;font-size:14px}.card .pro{color:#389e0d}.card .con{color:#cf1322}'
        . 'table{border-collapse:collapse;width:100%;margin:12px 0}td,th{border:1px solid #ddd;padding:8px 10px;text-align:left;font-size:14px}th{background:#fafafa}'
        . '.token{font-family:monospace;background:#f5f5f5;border:1px solid #ddd;border-radius:8px;padding:10px;word-break:break-all;font-size:14px}'
        . '.warn{background:#fffbe6;border:1px solid #ffe58f;border-radius:8px;padding:10px 14px;margin:12px 0}'
        . '</style></head><body>';
}

function page_foot() { return '</body></html>'; }

// ================= 第 0 步：环境检查 =================
if ($step === 0) {
    $checks = array();
    $checks[] = array('PHP 版本 >= 7.4（当前 ' . PHP_VERSION . '）', version_compare(PHP_VERSION, '7.4.0', '>='));
    $checks[] = array('扩展 pdo_mysql（连 MySQL）', extension_loaded('pdo_mysql'));
    $checks[] = array('扩展 mbstring（中文名字校验）', extension_loaded('mbstring'));
    $checks[] = array('扩展 json', extension_loaded('json'));
    $checks[] = array('函数 random_bytes（生成 token）', function_exists('random_bytes'));
    $checks[] = array('函数 password_hash（存管理员密码）', function_exists('password_hash'));
    $checks[] = array('网站根目录可写（写 config.local.php、建 chat/ 目录）', is_writable(__DIR__));
    $sqlFiles = array('sql/schema.sql', 'sql/migrate-rooms.sql', 'sql/migrate-rooms-owner.sql', 'sql/migrate-room-reads.sql', 'sql/migrations/001-add-reminded.sql', 'sql/migrate-room-archives.sql', 'sql/migrate-reactions.sql');
    $sqlOk = true;
    foreach ($sqlFiles as $f) { if (!is_file(__DIR__ . '/' . $f)) $sqlOk = false; }
    $checks[] = array('SQL 文件齐全（sql/ 目录）', $sqlOk);
    $allOk = true;
    foreach ($checks as $c) { if (!$c[1]) $allOk = false; }
    echo page_head('第 1 步：环境检查');
    echo '<h1>群聊站安装向导</h1><p class="sub">把网站传到服务器后，按步骤走完就能用。先检查环境，全部通过再继续。</p>';
    echo step_bar(0, $steps);
    echo '<table><tr><th>检查项</th><th>结果</th></tr>';
    foreach ($checks as $c) {
        echo '<tr><td>' . h($c[0]) . '</td><td>' . ($c[1] ? '✅ 通过' : '❌ 未通过') . '</td></tr>';
    }
    echo '</table>';
    if ($allOk) {
        echo '<div class="ok">环境全部通过，可以继续。</div>';
        echo '<a class="btn" href="?step=1">下一步：数据库 →</a>';
    } else {
        echo '<div class="err">有检查项未通过：请先在服务器上装好 PHP 7.4+、MySQL、对应扩展，并确认网站目录可写，再刷新本页。</div>';
    }
    echo page_foot();
    exit;
}

// ================= 第 1 步：数据库 =================
if ($step === 1) {
    $db = isset($_SESSION['install']['db']) ? $_SESSION['install']['db'] : array('host' => 'localhost', 'name' => 'groupchat', 'user' => '', 'pass' => '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $db = array(
            'host' => trim((string)(isset($_POST['db_host']) ? $_POST['db_host'] : 'localhost')),
            'name' => trim((string)(isset($_POST['db_name']) ? $_POST['db_name'] : '')),
            'user' => trim((string)(isset($_POST['db_user']) ? $_POST['db_user'] : '')),
            'pass' => (string)(isset($_POST['db_pass']) ? $_POST['db_pass'] : ''),
        );
        if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') {
            $err = '数据库地址、库名、用户名都要填。';
        } elseif (!preg_match('/^[A-Za-z0-9_]+$/', $db['name'])) {
            $err = '库名只允许字母、数字、下划线。';
        } else {
            try {
                $pdo = new PDO('mysql:host=' . $db['host'] . ';charset=utf8mb4', $db['user'], $db['pass'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
                $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $db['name']) . '` DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci');
                $_SESSION['install']['db'] = $db;
                header('Location: ?step=2');
                exit;
            } catch (Exception $e) {
                $err = '连不上数据库：' . $e->getMessage() . '。请检查地址、用户名、密码，以及该用户有没有建库权限。';
            }
        }
    }
    echo page_head('第 2 步：数据库');
    echo '<h1>第 2 步：数据库</h1><p class="sub">在宝塔面板（或 MySQL 里）先建好数据库用户，向导会帮你建库、建表。不用手工导 SQL。</p>';
    echo step_bar(1, $steps);
    if ($err !== '') echo '<div class="err">' . h($err) . '</div>';
    echo '<form method="post">'
        . '<label>数据库地址</label><input type="text" name="db_host" value="' . h($db['host']) . '" required><p class="hint">一般填 localhost（数据库和网站在同一台服务器时）。</p>'
        . '<label>库名</label><input type="text" name="db_name" value="' . h($db['name']) . '" required><p class="hint">不存在会自动创建；只允许字母、数字、下划线。</p>'
        . '<label>数据库用户名</label><input type="text" name="db_user" value="' . h($db['user']) . '" required>'
        . '<label>数据库密码</label><input type="password" name="db_pass" value="' . h($db['pass']) . '" required>'
        . '<br><button class="btn" type="submit">测试连接，下一步 →</button></form>';
    echo '<p><a href="?step=0">← 上一步</a></p>';
    echo page_foot();
    exit;
}

// ================= 第 2 步：管理员账号 =================
if ($step === 2) {
    if (!isset($_SESSION['install']['db'])) { header('Location: ?step=1'); exit; }
    $adminUser = isset($_SESSION['install']['admin_user']) ? $_SESSION['install']['admin_user'] : 'admin';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $adminUser = trim((string)(isset($_POST['admin_user']) ? $_POST['admin_user'] : ''));
        $p1 = (string)(isset($_POST['admin_pass']) ? $_POST['admin_pass'] : '');
        $p2 = (string)(isset($_POST['admin_pass2']) ? $_POST['admin_pass2'] : '');
        if (!valid_name($adminUser)) {
            $err = '管理员用户名不合法：1–20 字，字母/数字/-/中文，不含空格和 @、%、_、/、\\ 等符号。';
        } elseif (mb_strlen($p1, 'UTF-8') < 6) {
            $err = '密码至少 6 位，建议 8 位以上。';
        } elseif ($p1 !== $p2) {
            $err = '两次输入的密码不一致。';
        } else {
            $_SESSION['install']['admin_user'] = $adminUser;
            $_SESSION['install']['admin_pass'] = $p1;
            header('Location: ?step=3');
            exit;
        }
    }
    echo page_head('第 3 步：管理员账号');
    echo '<h1>第 3 步：管理员账号</h1><p class="sub">这个账号是网站的管理员：能加人、能审核、能改“允许指挥”和 AI 名单。用户名以后在管理后台能看到，密码请记好。</p>';
    echo step_bar(2, $steps);
    if ($err !== '') echo '<div class="err">' . h($err) . '</div>';
    echo '<form method="post">'
        . '<label>管理员用户名</label><input type="text" name="admin_user" value="' . h($adminUser) . '" required maxlength="20"><p class="hint">1–20 字，字母/数字/-/中文。不要和下面 AI 的名字重复。</p>'
        . '<label>密码</label><input type="password" name="admin_pass" required><p class="hint">至少 6 位，建议 8 位以上，字母+数字。</p>'
        . '<label>再输一次密码</label><input type="password" name="admin_pass2" required>'
        . '<br><button class="btn" type="submit">下一步：AI 助手 →</button></form>';
    echo '<p><a href="?step=1">← 上一步</a></p>';
    echo page_foot();
    exit;
}

// ================= 第 3 步：AI 助手 =================
if ($step === 3) {
    if (!isset($_SESSION['install']['admin_user'])) { header('Location: ?step=2'); exit; }
    $names = isset($_SESSION['install']['muse_names']) ? $_SESSION['install']['muse_names'] : array('助手');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = isset($_POST['muse_names']) ? $_POST['muse_names'] : array();
        $names = array();
        if (is_array($raw)) {
            foreach ($raw as $n) {
                $n = trim((string)$n);
                if ($n !== '') $names[] = $n;
            }
        }
        $adminUser = $_SESSION['install']['admin_user'];
        $bad = '';
        if (count($names) < 1) $bad = '至少要有一个 AI 助手。';
        elseif (count($names) > 10) $bad = '最多 10 个 AI 助手。';
        else {
            foreach ($names as $n) {
                if (!valid_name($n)) { $bad = '名字“' . $n . '”不合法：1–20 字，字母/数字/-/中文，不含空格和 @、%、_、/、\\ 等符号。'; break; }
                if ($n === $adminUser) { $bad = 'AI 的名字不能和管理员用户名“' . $adminUser . '”重复。'; break; }
            }
            if ($bad === '' && count(array_unique($names)) !== count($names)) $bad = 'AI 的名字不能重复。';
        }
        if ($bad !== '') {
            $err = $bad;
        } else {
            $_SESSION['install']['muse_names'] = $names;
            header('Location: ?step=4');
            exit;
        }
    }
    $count = count($names);
    echo page_head('第 4 步：AI 助手');
    echo '<h1>第 4 步：AI 助手</h1><p class="sub">群里的 AI 助手：群成员 @ 名字就能叫醒它。先建一个也行，以后在管理后台的“＠ 名单”里随时加减，不用改代码、不用重装。</p>';
    echo step_bar(3, $steps);
    if ($err !== '') echo '<div class="err">' . h($err) . '</div>';
    echo '<form method="post" id="museForm">'
        . '<label>几个 AI 助手</label><input type="number" id="museCount" min="1" max="10" value="' . $count . '"><p class="hint">1–10 个。每个 AI 都要单独接 Muse（各建各的轮询），名字不同、干的活可以不同。</p>'
        . '<div id="nameList"></div>'
        . '<br><button class="btn" type="submit">下一步：唤醒模式 →</button></form>';
    echo '<script>'
        . 'function buildNames(){var c=parseInt(document.getElementById("museCount").value,10);if(isNaN(c)||c<1)c=1;if(c>10)c=10;'
        . 'var old=[];document.querySelectorAll("#nameList input").forEach(function(i){old.push(i.value)});'
        . 'var box=document.getElementById("nameList");box.innerHTML="";'
        . 'for(var i=0;i<c;i++){var d=document.createElement("div");var l=document.createElement("label");l.textContent="第 "+(i+1)+" 个 AI 的名字";'
        . 'var inp=document.createElement("input");inp.type="text";inp.name="muse_names[]";inp.maxLength=20;inp.required=true;'
        . 'inp.value=old[i]!==undefined?old[i]:(i===0?"助手":"");inp.placeholder=i===0?"比如：助手":"比如：助手"+(i+1);'
        . 'd.appendChild(l);d.appendChild(inp);box.appendChild(d);}}'
        . 'document.getElementById("museCount").addEventListener("input",buildNames);buildNames();'
        // 回填已提交过的名字
        . 'var saved=' . json_encode(array_values($names)) . ';'
        . 'if(saved.length){document.querySelectorAll("#nameList input").forEach(function(inp,i){if(saved[i]!==undefined)inp.value=saved[i];});}'
        . '</script>';
    echo '<p><a href="?step=2">← 上一步</a></p>';
    echo page_foot();
    exit;
}

// ================= 第 4 步：唤醒模式 =================
if ($step === 4) {
    if (!isset($_SESSION['install']['muse_names'])) { header('Location: ?step=3'); exit; }
    $wake = isset($_SESSION['install']['wake']) ? $_SESSION['install']['wake'] : 'watchdog';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $w = isset($_POST['wake']) ? (string)$_POST['wake'] : 'watchdog';
        if (!in_array($w, array('watchdog', 'cron', 'both'), true)) $w = 'watchdog';
        $_SESSION['install']['wake'] = $w;
        header('Location: ?step=5');
        exit;
    }
    echo page_head('第 5 步：唤醒模式');
    echo '<h1>第 5 步：唤醒模式</h1><p class="sub">群里的 AI 不会 24 小时在线盯着，它是被“叫醒”的。主叫醒是 hook 轮询（每 5 秒问服务器一次“有没有新消息”，没消息不花 token）；下面选的是“主叫醒停了怎么办”的保险。选一个就行，以后随时能换。</p>';
    echo step_bar(4, $steps);
    echo '<form method="post">';
    $cards = array(
        'watchdog' => array('看门狗（推荐，平时零成本）', '两个 hook 脚本互相盯着：B 每 30 秒看主 hook 的日志，A 每 30 秒看 B 的日志。', '平时几乎零成本：每 30 秒只读一次日志文件，健康时不唤醒 AI、不花 token；主 hook 一停（超过 90 秒没跑）就立刻叫醒，恢复快。', '看门狗和主 hook 跑在同一个调度器上，平台层面整体停摆（维护、资源紧张）时三者会一起停，这层保险就失效了。'),
        'cron' => array('定时检查（独立调度）', '一个定时任务每 30 分钟读一次主 hook 日志最后一条，超 90 秒没跑就叫醒。', '走独立的定时任务调度，和 hook 调度器无关，就算 hook 全停了也能发现并叫醒。', '每次触发都要加载一次 AI 上下文，约 24 万 token/次；30 分钟一次约占免费周额度的 8.1%。夜间 0:00–9:00 也省不下这笔：调度器不能跳过夜间触发，只能触发后立即退出，白花一次加载。'),
        'both' => array('两者都要（双保险）', '看门狗 + 定时检查一起装。', '双保险：hook 停了有看门狗立刻叫，平台整体停摆还有定时检查兜底。', '定时检查的 token 成本照付（约 24 万 token/次，30 分钟一次约占免费周额度的 8.1%）。'),
    );
    foreach ($cards as $key => $c) {
        $sel = ($wake === $key) ? ' sel' : '';
        $chk = ($wake === $key) ? ' checked' : '';
        echo '<label class="card' . $sel . '" onclick="document.querySelectorAll(\'.card\').forEach(function(e){e.classList.remove(\'sel\')});this.classList.add(\'sel\')">'
            . '<input type="radio" name="wake" value="' . $key . '"' . $chk . ' style="width:auto"> <h3 style="display:inline">' . h($c[0]) . '</h3>'
            . '<p>' . h($c[1]) . '</p>'
            . '<p class="pro">优点：' . h($c[2]) . '</p>'
            . '<p class="con">缺点：' . h($c[3]) . '</p></label>';
    }
    echo '<br><button class="btn" type="submit">下一步：确认 →</button></form>';
    echo '<p><a href="?step=3">← 上一步</a></p>';
    echo page_foot();
    exit;
}

// ================= 第 5 步：确认 =================
if ($step === 5) {
    if (!isset($_SESSION['install']['wake'])) { header('Location: ?step=4'); exit; }
    $db = $_SESSION['install']['db'];
    $wakeNames = array('watchdog' => '看门狗', 'cron' => '定时检查', 'both' => '两者都要（双保险）');
    echo page_head('第 6 步：确认');
    echo '<h1>第 6 步：确认</h1><p class="sub">核对一遍，没问题就点安装。向导会：建库建表、创建管理员、写好配置、把网站文件同步到 chat/ 目录。</p>';
    echo step_bar(5, $steps);
    echo '<table>'
        . '<tr><th>项目</th><th>内容</th></tr>'
        . '<tr><td>数据库地址 / 库名</td><td>' . h($db['host']) . ' / ' . h($db['name']) . '</td></tr>'
        . '<tr><td>数据库用户</td><td>' . h($db['user']) . '</td></tr>'
        . '<tr><td>管理员用户名</td><td>' . h($_SESSION['install']['admin_user']) . '</td></tr>'
        . '<tr><td>AI 助手（' . count($_SESSION['install']['muse_names']) . ' 个）</td><td>' . h(implode('、', $_SESSION['install']['muse_names'])) . '</td></tr>'
        . '<tr><td>唤醒模式</td><td>' . h($wakeNames[$_SESSION['install']['wake']]) . '</td></tr>'
        . '<tr><td>站点地址</td><td>' . h(site_url()) . '</td></tr>'
        . '</table>';
    echo '<form method="post" action="?step=6"><button class="btn" type="submit">确认安装</button> <a class="btn sec" href="?step=1">返回修改</a></form>';
    echo page_foot();
    exit;
}

// ================= 第 6 步：执行安装 =================
if ($step === 6) {
    // 成功页：只显示一次（刷新后不再显示 token，token 在服务器 config.local.php 里）。
    if (isset($_GET['done']) && isset($_SESSION['install_done'])) {
        $done = $_SESSION['install_done'];
        unset($_SESSION['install_done']);
        unset($_SESSION['install']);
        $wakeNames = array('watchdog' => '看门狗', 'cron' => '定时检查', 'both' => '两者都要（双保险）');
        echo page_head('安装完成');
        echo '<h1>✅ 安装完成</h1>';
        echo step_bar(6, $steps);
        echo '<div class="ok">网站已装好：数据库、管理员、AI 名单、配置都写好了，网站文件也同步到了 chat/ 目录。</div>';
        echo '<h2>Muse token（只显示这一次，请立即保存）</h2>';
        echo '<p class="hint">这个 token 是你的 AI 助手连网站用的凭证，注册 Muse 连接器时要填。丢了可以在服务器 config.local.php 里找到 muse_token。</p>';
        echo '<div class="token" id="tokenBox">' . h($done['token']) . '</div>';
        echo '<p><button class="btn sec" type="button" onclick="var t=document.getElementById(\'tokenBox\');var r=document.createRange();r.selectNode(t);window.getSelection().removeAllRanges();window.getSelection().addRange(r);document.execCommand(\'copy\');this.textContent=\'已复制\';">复制 token</button></p>';
        echo '<h2>唤醒模式文件（已按你的选择）</h2>';
        echo '<p class="hint">下载后按 muse/README.md 装到 Muse 里。链接里的站点地址已经是你的真实域名，不用再改。</p><ul>';
        echo '<li><a href="?download=hook-wake-check.sh">hook-wake-check.sh</a> —— 主叫醒 hook，每 5 秒问一次服务器（三种模式都要装）。</li>';
        if ($done['wake'] === 'watchdog' || $done['wake'] === 'both') {
            echo '<li><a href="?download=hook-watchdog-a.sh">hook-watchdog-a.sh</a> —— 看门狗 A（盯看门狗 B）。</li>';
            echo '<li><a href="?download=hook-watchdog-b.sh">hook-watchdog-b.sh</a> —— 看门狗 B（盯主 hook）。</li>';
        }
        if ($done['wake'] === 'cron' || $done['wake'] === 'both') {
            echo '<li><a href="?download=cron-monitor.md">cron-monitor.md</a> —— 定时检查计划的任务文本和说明。</li>';
        }
        echo '</ul>';
        echo '<h2>接下来做这几件事</h2><ol>'
            . '<li><b>立即删除 install.php</b>（服务器上的这个文件），不然别人能重装覆盖你的配置。</li>'
            . '<li>按仓库里 muse/README.md 的说明，在 Muse 里注册连接器（muse/connector/），把上面那个 token 填进去。</li>'
            . '<li>把上面下载的 hook 脚本装到 Muse 里（muse/README.md 有步骤）。</li>'
            . '<li>（可选）要浏览器实时推送（新消息秒到），按 docs/从零开始说明.md 搭 push-server；不搭也能用，只是浏览器要靠轮询刷消息。</li>'
            . '<li>打开 <a href="' . h(site_url()) . '">' . h(site_url()) . '</a>，用管理员账号 <b>' . h($done['admin_user']) . '</b> 登录，进管理后台加群成员、建旁聊。</li>'
            . '</ol>';
        echo '<div class="warn"><b>先删 install.php，再去做别的。</b>装好后这个文件没有任何用处，只剩风险。</div>';
        echo '<p>安装摘要：管理员 ' . h($done['admin_user']) . '；AI 助手 ' . h(implode('、', $done['muse_names'])) . '；唤醒模式 ' . h($wakeNames[$done['wake']]) . '。</p>';
        echo page_foot();
        exit;
    }

    // 执行：只接受从第 5 步 POST 过来的确认。
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['install']['db'])) {
        header('Location: ?step=0');
        exit;
    }
    $db = $_SESSION['install']['db'];
    $adminUser = $_SESSION['install']['admin_user'];
    $adminPass = $_SESSION['install']['admin_pass'];
    $museNames = $_SESSION['install']['muse_names'];
    $wake = $_SESSION['install']['wake'];
    $fail = '';
    try {
        $pdo = new PDO('mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4', $db['user'], $db['pass'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        // 导 SQL（顺序不能乱：基础结构 → 房间 → 负责人 → 已读 → 待审核提醒 → 归档 → 表情回应）。
        $sqlOrder = array('sql/schema.sql', 'sql/migrate-rooms.sql', 'sql/migrate-rooms-owner.sql', 'sql/migrate-room-reads.sql', 'sql/migrations/001-add-reminded.sql', 'sql/migrate-room-archives.sql', 'sql/migrate-reactions.sql');
        foreach ($sqlOrder as $f) {
            $path = __DIR__ . '/' . $f;
            if (!is_file($path)) throw new Exception('缺少 SQL 文件：' . $f);
            $sql = file_get_contents($path);
            if ($f === 'sql/schema.sql') {
                // schema.sql 里写的是占位库名 groupchat，换成用户选的库名。
                $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS `[^`]+`/', 'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $db['name']) . '`', $sql, 1);
                $sql = preg_replace('/^USE `[^`]+`;/m', 'USE `' . str_replace('`', '', $db['name']) . '`;', $sql, 1);
            }
            foreach (split_sql($sql) as $stmt) {
                $pdo->exec($stmt);
            }
        }
        // 建管理员账号。
        $hash = password_hash($adminPass, PASSWORD_DEFAULT);
        $st = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
        $st->execute(array(':u' => $adminUser));
        if ($st->fetch()) {
            throw new Exception('管理员用户名“' . $adminUser . '”已存在（库里已有同名用户）。');
        }
        $st = $pdo->prepare('INSERT INTO users (username, password_hash, role, can_command) VALUES (:u, :h, \'admin\', 1)');
        $st->execute(array(':u' => $adminUser, ':h' => $hash));
        // 写 config.local.php（token 现场生成）。
        $token = bin2hex(random_bytes(32));
        $cfg = array(
            'db_host' => $db['host'],
            'db_name' => $db['name'],
            'db_user' => $db['user'],
            'db_pass' => $db['pass'],
            'db_charset' => 'utf8mb4',
            'muse_token' => $token,
            'site_name' => '群聊',
            'session_name' => 'CHAT_SESSID',
            'muse_names' => array_values($museNames),
        );
        $cfgText = "<?php\n/**\n * 群聊站本地配置（install.php 生成）。\n * 只放服务端，绝不要提交、不要打包外发、不要放前端 JS。\n */\nreturn " . var_export($cfg, true) . ";\n";
        if (file_put_contents(__DIR__ . '/config.local.php', $cfgText) === false) {
            throw new Exception('写 config.local.php 失败（目录不可写）。');
        }
        // 网站文件同步到 chat/ 目录（实时推送和部分接口走 /chat/ 路径，两处都要有）。
        $chatDir = __DIR__ . '/chat';
        if (!is_dir($chatDir)) mkdir($chatDir, 0755, true);
        foreach (glob(__DIR__ . '/*.php') as $php) {
            $base = basename($php);
            if ($base === 'install.php') continue;
            copy($php, $chatDir . '/' . $base);
        }
        foreach (array('api', 'assets', 'lib') as $d) {
            copy_tree(__DIR__ . '/' . $d, $chatDir . '/' . $d, array());
        }
        $_SESSION['install_done'] = array(
            'token' => $token,
            'admin_user' => $adminUser,
            'muse_names' => $museNames,
            'wake' => $wake,
        );
        header('Location: ?step=6&done=1');
        exit;
    } catch (Exception $e) {
        $fail = $e->getMessage();
    }
    echo page_head('安装失败');
    echo '<h1>安装失败</h1>';
    echo step_bar(6, $steps);
    echo '<div class="err">' . h($fail) . '</div>';
    echo '<p>已经执行的部分可能已写入（比如已建的表、已生成的 config.local.php）。处理办法：先看报错改好（比如数据库权限、重名用户），删掉 config.local.php（如果已生成），再刷新本页从第 1 步重装。</p>';
    echo '<p><a class="btn" href="?step=1">返回第 1 步</a></p>';
    echo page_foot();
    exit;
}

// 默认回到第 0 步。
header('Location: ?step=0');
exit;
