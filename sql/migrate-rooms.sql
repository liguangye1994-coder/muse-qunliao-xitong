-- 群聊旁聊（房间）迁移：先跑这个，再覆盖代码包。
-- 部署步骤（宝塔面板 → 数据库 → 你的库 → 导入，执行下面语句）：
--   1) 在 phpMyAdmin 或宝塔数据库管理里选中你的库，执行本文件全部内容。
--   2) 再把代码包解到网站根目录覆盖。
-- 已跑过一次就不用再跑（IF NOT EXISTS / 重复列会报错但无害，见下）。
-- 注意：messages 加 room_id 列时，若已存在会报错 "Duplicate column name"，
-- 可忽略该错继续执行后面的语句；rooms 表和默认房间用 IF NOT EXISTS，不会重复建。

-- 1) 房间表：一个房间就是一个旁聊（主群 + 按项目开的旁聊，做视频/生图/代码各一个）。
CREATE TABLE IF NOT EXISTS `rooms` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(64)  NOT NULL,
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) 主群房间（id=1，名字“群聊”）：老消息全归到它下面。
INSERT IGNORE INTO `rooms` (`id`, `name`, `created_by`) VALUES (1, '群聊', 0);

-- 3) 消息表加 room_id 列（默认 1 = 主群，老消息不用动，默认值自动归到主群）。
--    若已执行过会报 Duplicate column name，忽略即可。
ALTER TABLE `messages` ADD COLUMN `room_id` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `messages` ADD KEY `idx_room_created` (`room_id`, `created_at`);
