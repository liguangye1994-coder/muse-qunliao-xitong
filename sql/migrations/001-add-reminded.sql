-- 审核提醒用：给 messages 加 reminded 列。
-- Muse 调 api/muse/pending.php 取走待审核提醒后标 1，防群里多个 Muse 重复提醒。
-- 在宝塔 phpMyAdmin 里对你的库执行一次即可；已有数据默认 0。
-- 注意：先跑这句，再覆盖 pending.php，否则接口报 500。
-- （执行前先在工具里选中你的库；安装向导会自动选库，不用管这句。）

ALTER TABLE `messages` ADD COLUMN `reminded` TINYINT(1) NOT NULL DEFAULT 0 AFTER `review_status`;
