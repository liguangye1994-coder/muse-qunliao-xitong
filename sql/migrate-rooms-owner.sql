-- 群聊房间负责人迁移：rooms 表加 muse_owner 列，负责人房间不再写死在代码里。
-- 部署步骤（宝塔面板 → 数据库 → 你的库 → 导入，执行下面语句，再覆盖代码包）：
--   1) 在 phpMyAdmin 或宝塔数据库管理里选中你的库，执行本文件全部内容。
--   2) 再把代码包（api/muse/wake_check.php、rooms.php）解到网站根目录覆盖。
-- 已跑过一次就不用再跑（列已存在会报 Duplicate column name，忽略即可；UPDATE 可重复跑）。
--
-- 1) 房间表加负责人列：记着每间房的负责人是哪个 Muse（NULL = 没设负责人）。
--    wake_check.php 直接读这列决定“负责人房间有新消息就叫醒谁”，
--    以后新建房间、换负责人都不用改代码。
ALTER TABLE `rooms` ADD COLUMN `muse_owner` VARCHAR(32) NULL DEFAULT NULL AFTER `name`;

-- 2) 「新的游戏项目」房间（id=3）负责人记为助手，保持现有行为不变。
UPDATE `rooms` SET `muse_owner` = '助手' WHERE `id` = 3;

-- 3) 以后任命负责人：管理员调 rooms.php action=set_owner
--    （POST room_id + muse_owner + csrf_token），或直接在库里 UPDATE rooms
--    SET muse_owner='名字' WHERE id=N；muse_owner 设空即清除。
--    注意：主群（id=1）不要设负责人，主群只靠 @ / 回帖叫醒。
--    没设负责人的房间也只靠 @ / 回帖叫醒，不会乱叫醒人。
