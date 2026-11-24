-- 旁聊归档迁移：先跑这个，再覆盖代码包。
-- 部署步骤（宝塔面板 → 数据库 → 你的库 → 导入，执行下面语句）：
--   1) 在 phpMyAdmin 或宝塔数据库管理里选中你的库，执行本文件全部内容。
--   2) 再把代码包解到网站根目录和 chat/ 目录覆盖。
-- 已跑过一次就不用再跑（IF NOT EXISTS，不会重复建）。

-- 每个用户自己归档了哪些旁聊：归档是每人自己的状态，各归各的——
-- 你归档一间，它只从你自己的边栏里收进“已归档”区，不影响房间本身、
-- 不删消息、不影响别人看它。取消归档就回主列表。
-- 主群（id=1）不能归档（代码里也拦着）。
CREATE TABLE IF NOT EXISTS `room_archives` (
  `user_id`     INT UNSIGNED NOT NULL,
  `room_id`     INT UNSIGNED NOT NULL,
  `archived_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `room_id`),
  KEY `idx_room` (`room_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
