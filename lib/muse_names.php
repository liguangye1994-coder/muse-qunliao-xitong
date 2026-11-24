<?php
/**
 * lib/muse_names.php —— 群里的 Muse 名单（@ 列表）读写。
 *
 * 名单存在 config.local.php 的 muse_names 里（回帖白名单、@ 候选、房间负责人
 * 校验都读这一处），管理后台 admin.php 的“＠ 名单”直接改它，不用再进宝塔
 * 改文件。改完立即生效，不用重启、不用重新打包。
 *
 * 写文件用 var_export + 临时文件 + rename 原子替换：var_export 生成的代码
 * 天生就是合法 PHP，不会写出半截文件；其他配置项（数据库密码、token 等）
 * 原样保留，只换 muse_names 这一项。
 * PHP 7.4+ 兼容。
 */
if (!defined('CHAT_APP')) {
    define('CHAT_APP', true);
}
require_once __DIR__ . '/../config.php';

function muse_names_default()
{
    return array('助手');
}

/**
 * 读名单：每次都重新读一遍 config.local.php（不用 app_config 的静态缓存，
 * 不然同一请求里刚保存完、显示的还是旧名单）。文件不存在或读失败时，
 * 回退到 app_config_get 的默认值。
 */
function muse_names_read()
{
    $file = __DIR__ . '/../config.local.php';
    if (is_file($file)) {
        try {
            $cfg = require $file;
            if (is_array($cfg) && isset($cfg['muse_names']) && is_array($cfg['muse_names'])) {
                $names = array();
                foreach ($cfg['muse_names'] as $n) {
                    $n = trim((string)$n);
                    if ($n !== '') {
                        $names[] = $n;
                    }
                }
                if (!empty($names)) {
                    return array_values(array_unique($names));
                }
            }
        } catch (Throwable $e) {
            // 文件坏了就回退默认值，不把管理页拖垮。
        }
    }
    $names = app_config_get('muse_names', muse_names_default());
    if (!is_array($names) || empty($names)) {
        return muse_names_default();
    }
    return array_values($names);
}

/**
 * 校验一个名字。返回 '' 表示通过，否则返回原因。
 * 限制：1–20 字；不能含空白、@、% _ \（@ 是提醒语法，% _ \ 会干扰 wake_check 的 LIKE 匹配）。
 */
function muse_name_check($name)
{
    $name = trim((string)$name);
    if ($name === '') {
        return '名字不能为空。';
    }
    if (mb_strlen($name, 'UTF-8') > 20) {
        return '名字最多 20 字。';
    }
    if (preg_match('/\s/', $name)) {
        return '名字不能含空格。';
    }
    if (strpos($name, '@') !== false || strpos($name, '%') !== false
        || strpos($name, '_') !== false || strpos($name, '\\') !== false) {
        return '名字不能含 @、%、_、\\。';
    }
    return '';
}

/**
 * 保存整份名单。$pdo 传了就顺带查一下：名字不能和已注册用户名重名
 * （重名会让 @ 分不清是在叫 Muse 还是在叫那个用户）。
 * 成功返回 array('ok' => true, 'names' => [...])；
 * 失败返回 array('ok' => false, 'error' => '...')。
 */
function muse_names_write($names, $pdo = null)
{
    $clean = array();
    foreach ((array)$names as $n) {
        $n = trim((string)$n);
        if ($n === '') {
            continue;
        }
        $err = muse_name_check($n);
        if ($err !== '') {
            return array('ok' => false, 'error' => '名字“' . $n . '”不对：' . $err);
        }
        $clean[] = $n;
    }
    $clean = array_values(array_unique($clean));
    if (empty($clean)) {
        return array('ok' => false, 'error' => '名单不能为空，至少留一个 Muse。');
    }
    if (count($clean) > 20) {
        return array('ok' => false, 'error' => '名单最多 20 个。');
    }
    if ($pdo !== null) {
        try {
            $placeholders = implode(',', array_fill(0, count($clean), '?'));
            $stmt = $pdo->prepare("SELECT username FROM users WHERE username IN ($placeholders) LIMIT 1");
            $stmt->execute($clean);
            $hit = $stmt->fetch();
            if ($hit) {
                return array('ok' => false, 'error' => '名字“' . $hit['username'] . '”已经是一个注册用户名，换一个。');
            }
        } catch (Exception $e) {
            // 用户表查不到就不拦，保存优先。
        }
    }

    $file = __DIR__ . '/../config.local.php';
    if (!is_file($file)) {
        return array('ok' => false, 'error' => '找不到 config.local.php，请先按 config.example.php 建好。');
    }
    // 保存是临时文件 + rename 替换：只要文件本身可写、或者目录可写（能替换文件），就能保存。
    if (!is_writable($file) && !is_writable(dirname($file))) {
        return array('ok' => false, 'error' => 'config.local.php 保存不了：请在宝塔文件管理里把 config.local.php 改成 666（或把网站目录所有者改成 www）后再试，只改这一次，以后都不用再进宝塔。');
    }
    $cfg = app_config();
    if (!is_array($cfg)) {
        $cfg = array();
    }
    $cfg['muse_names'] = $clean;
    $content = "<?php\n"
        . "/**\n"
        . " * 群聊站本地配置（config.local.php）\n"
        . " *\n"
        . " * 注意：muse_names（群里的 Muse 名单）现在由管理后台 admin.php 的“＠ 名单”维护，\n"
        . " * 不要再手工改这一项；数据库密码和 token 仍只能在这里改，不要发给任何人。\n"
        . " */\n"
        . "return " . var_export($cfg, true) . ";\n";
    $tmp = tempnam(dirname($file), 'cfg');
    if ($tmp === false) {
        return array('ok' => false, 'error' => '写临时文件失败，请检查目录权限。');
    }
    if (file_put_contents($tmp, $content) === false) {
        @unlink($tmp);
        return array('ok' => false, 'error' => '写临时文件失败，请检查目录权限。');
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return array('ok' => false, 'error' => '保存失败，请检查 config.local.php 的目录权限。');
    }
    return array('ok' => true, 'names' => $clean);
}
