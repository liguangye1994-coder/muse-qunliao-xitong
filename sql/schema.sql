-- 群聊站数据库结构（你的域名/chat/）
-- 目标库：MySQL 5.7+ / 8.0，字符集 utf8mb4，PHP 7.4+ PDO
-- 部署：在宝塔面板新建数据库后，导入本文件（不要手工建表）。
-- 注意：本文件不含任何真实密码。管理员 管理员 的初始密码见文件末尾注释，用
-- password_hash() 生成后填入，不要把明文密码写进 SQL。

CREATE DATABASE IF NOT EXISTS `groupchat`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `groupchat`;

-- 用户表
-- role: admin = 管理员，member = 普通成员
-- can_command: 1 = 该用户在群里发的话可被助手轮询取走并执行，
--              而且能发信息（send.php 里直接 approved，所有成员都能看到）；
--              0 = 不能发信息（send.php 直接拒绝），只能看群里的消息，
--              不能指挥助手。
-- 定版口径：默认只有 管理员（admin）= 1；除非 管理员 亲口指定其他人，
-- 否则不要把任何 member 的 can_command 改成 1。
CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(64)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          ENUM('admin','member') NOT NULL DEFAULT 'member',
  `can_command`   TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 消息表（只存本群消息，不存其他站 / 其他群的内容）
-- status: pending = 还没被助手处理；done = 已处理（已回帖或无需处理）
-- is_muse_reply: 1 = 这条是助手的回帖；0 = 用户发的消息
-- reply_to_id: 助手回帖时填它回复的那条用户消息 id；用户发消息时为 NULL
-- review_status: pending = 待 管理员 审核；approved = 已通过；rejected = 未通过。
--   现在发信息不走审核了（只有 admin 和 can_command=1 的人能发，发了就直接 approved），
--   所以新消息都是 approved；pending 只剩以前的老消息，普通成员也能看到 pending 的，
--   只有别人的 rejected 看不到。助手回帖直接 approved。status（助手处理状态）与 review_status（审核状态）是两个
--   独立字段，不要混用。
CREATE TABLE IF NOT EXISTS `messages` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL DEFAULT 0,
  `username`      VARCHAR(64)  NOT NULL,
  `content`       TEXT         NOT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status`        ENUM('pending','done') NOT NULL DEFAULT 'pending',
  `is_muse_reply` TINYINT(1)   NOT NULL DEFAULT 0,
  `reply_to_id`   BIGINT UNSIGNED NULL DEFAULT NULL,
  `review_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`),
  KEY `idx_status_created` (`status`, `created_at`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_reply_to` (`reply_to_id`),
  KEY `idx_review_created` (`review_status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 消息表情回应（像 Muse 界面的“留下心情” / TG 的回复表情）：
-- message_id + user_id + username + reaction 唯一，点一下留、再点一下取消。
-- 网页用户 user_id>0；Muse user_id=0、username 是 Muse 名，多个 AI 能在
-- 同一条消息上各留一个 👌，管理员一眼就能看到谁接到了。
CREATE TABLE IF NOT EXISTS `message_reactions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL DEFAULT 0,
  `username`   VARCHAR(64)  NOT NULL,
  `reaction`   VARCHAR(16)  NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_msg_user_reaction` (`message_id`, `user_id`, `username`, `reaction`),
  KEY `idx_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 管理员账号：由安装向导 install.php 创建（向导里填用户名和密码）。
-- 如果不用向导、手工建站，按下面步骤生成 hash 再执行本段：
-- 1) 在服务器或本地执行：
--      php -r "echo password_hash('你的初始密码', PASSWORD_DEFAULT), PHP_EOL;"
-- 2) 把输出的 hash 替换下面占位符、把 'admin' 换成你自己的管理员用户名，再执行本段。
--    初始密码设定后立即登录改一次，不要长期用初始密码。
-- 3) 管理员的 role 是 admin 且 can_command=1；其他用户由管理员在管理页添加。
--
-- INSERT INTO `users` (`username`, `password_hash`, `role`, `can_command`)
-- VALUES ('admin', '<把上面生成的 hash 粘贴到这里>', 'admin', 1);
