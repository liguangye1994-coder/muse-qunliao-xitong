<?php
/**
 * 用户上传文件接口（upload.php）—— 仅登录用户，POST multipart + CSRF。
 * 群里传文件用 2 号链接 tmpfile.link（和 Muse 分享走同一条路）：
 * 浏览器把文件 POST 到这里，服务端再转传给 tmpfile.link，返回直链；
 * 前端拿到直链后当一条普通消息发出去（走 send.php，一样过审核）。
 * 直接让浏览器调 tmpfile.link 会撞跨域，所以由服务端中转。
 * 大小上限 45MB（tmpfile.link 实测上限，超了传不上去），超了直接报。
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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => '只支持 POST'), JSON_UNESCAPED_UNICODE);
    exit;
}
if (!csrf_check(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'error' => 'CSRF 校验失败，请刷新重试'), JSON_UNESCAPED_UNICODE);
    exit;
}
if (!isset($_FILES['file']) || !is_array($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => '没有收到文件'), JSON_UNESCAPED_UNICODE);
    exit;
}

$maxBytes = 45000000; // 45MB，tmpfile.link 实测上限
if ($_FILES['file']['size'] > $maxBytes || $_FILES['file']['size'] <= 0) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => '文件太大，上限 45MB'), JSON_UNESCAPED_UNICODE);
    exit;
}
$tmpPath = $_FILES['file']['tmp_name'];
if (!is_uploaded_file($tmpPath)) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => '文件接收失败'), JSON_UNESCAPED_UNICODE);
    exit;
}
$origName = isset($_FILES['file']['name']) ? (string)$_FILES['file']['name'] : 'file';
$mime = isset($_FILES['file']['type']) ? (string)$_FILES['file']['type'] : 'application/octet-stream';

// 转传给 tmpfile.link：须带浏览器 UA，Python/urllib 默认 UA 会被 Cloudflare 挡（403）。
$ch = curl_init('https://tmpfile.link/api/upload');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Accept: application/json',
    'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
));
$cfile = class_exists('CURLFile') ? new CURLFile($tmpPath, $mime, $origName) : '@' . $tmpPath;
curl_setopt($ch, CURLOPT_POSTFIELDS, array('file' => $cfile));
$resp = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($resp === false || $httpCode !== 200) {
    http_response_code(502);
    echo json_encode(array('ok' => false, 'error' => '上传失败' . ($curlErr !== '' ? '（' . $curlErr . '）' : '，请稍后再试')), JSON_UNESCAPED_UNICODE);
    exit;
}
$data = json_decode($resp, true);
$url = (is_array($data) && isset($data['downloadLink'])) ? (string)$data['downloadLink'] : '';
if ($url === '') {
    http_response_code(502);
    echo json_encode(array('ok' => false, 'error' => '上传失败，对方没返回链接'), JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode(array('ok' => true, 'url' => $url, 'name' => $origName, 'size' => (int)$_FILES['file']['size']), JSON_UNESCAPED_UNICODE);
