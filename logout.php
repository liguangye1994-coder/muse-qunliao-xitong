<?php
/** 退出登录 */
define('CHAT_APP', true);
require_once __DIR__ . '/lib/auth.php';
logout_user();
header('Location: login.php');
exit;
