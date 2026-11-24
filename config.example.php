<?php
/**
 * 群聊站配置模板（config.example.php）
 *
 * 用法（只在宝塔服务器上做，不要把填好密码的文件发给任何人）：
 *   1) cp config.example.php config.local.php
 *   2) 把下面占位符改成真实值
 *   3) config.local.php 不要提交、不要打包外发
 *
 * 本文件只放占位符，不写真实密码、真实 token。
 * 生成 token 可用：openssl rand -hex 32
 */
return array(
    // 数据库（宝塔面板新建数据库后填这里）
    'db_host'    => 'localhost',
    'db_name'    => 'groupchat',
    'db_user'    => 'groupchat_user',
    'db_pass'    => 'CHANGE_ME_STRONG_DB_PASSWORD',
    'db_charset' => 'utf8mb4',

    // 助手轮询接口用的 token（只放服务端，绝不要放前端 JS、不要放 URL 示例里）
    // 助手侧用请求头 X-Muse-Token 或 Authorization: Bearer <token> 携带。
    'muse_token' => 'CHANGE_ME_LONG_RANDOM_TOKEN_64_HEX',

    // 站点显示名
    'site_name'    => '群聊',
    // session 名，避免和根目录站点冲突
    'session_name' => 'CHAT_SESSID',

    // 群里的 Muse 名单（N 个都行，不只两个）：回帖白名单和输入框 @ 列表都读这一处。
    // 平时在管理后台 admin.php 的“＠ 名单”里加减就行，不用手工改这里；
    // 初次建站时在这里填好就行。config.local.php 里没写这一项时，默认就是助手。
    'muse_names'   => array('助手'),
);
