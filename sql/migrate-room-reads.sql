-- 旁聊未读红点迁移：先跑这个，再覆盖代码包。
-- 部署步骤（宝塔面板 → 数据库 → 你的库 → 导入，执行下面语句）：
--   1) 在 phpMyAdmin 或宝塔数据库管理里选中你的库，执行本文件全部内容。
--   2) 再把代码包解到网站根目录覆盖。
-- 已跑过一次就不用再跑（IF NOT EXISTS，不会重复建）。
-- 注意：这个迁移不依赖 rooms 表的 room_id 列（那是上一个 migrate-rooms.sql 的事），
-- 但未读功能本身要 rooms + messages.room_id 都在才有意义；两个迁移都跑过才完整。

-- 每个用户在每个旁聊里“已读到哪条消息”：last_read_id 是该用户在这个房间里
-- 已看到的最大消息 id。某房间最新一条“他能看到的”消息 id > last_read_id，
-- 边栏那间就显示蓝色小圆点；点进那间就把 last_read_id 更新到最新，圆点消失。
-- 没记录（从没点进过某间）就当 last_read_id=0，有消息就算未读。
CREATE TABLE IF NOT EXISTS `room_reads` (
  `user_id`      INT UNSIGNED NOT NULL,
  `room_id`      INT UNSIGNED NOT NULL,
  `last_read_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `room_id`),
  KEY `idx_room` (`room_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
