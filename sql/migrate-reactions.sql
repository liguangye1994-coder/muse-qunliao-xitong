-- 消息表情回应迁移：先跑这个，再覆盖代码包。
-- 部署步骤（宝塔面板 → 数据库 → 你的库 → 导入，执行下面语句）：
--   1) 在 phpMyAdmin 或宝塔数据库管理里选中你的库，执行本文件全部内容。
--   2) 再把代码包解到网站根目录覆盖。
-- 已跑过一次就不用再跑（IF NOT EXISTS，不会重复建）。
-- 注意：网站文件要同时放到根目录和 chat/ 两处，平时在根目录打开。

-- 每条消息下面的表情 chips（像 Muse 界面的“留下心情” / TG 的回复表情）：
-- message_id + user_id + username + reaction 唯一，点一下留、再点一下取消。
-- 网页用户 user_id>0、username 是用户名；Muse user_id=0、username 是 Muse 名
-- （助手们），这样多个 AI 能在同一条消息上各留一个 👌，
-- 管理员一眼就能看到谁接到了。
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
