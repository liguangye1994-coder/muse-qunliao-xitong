# Muse 侧接入说明

## 架构（一句话）

网站提供 HTTP 接口，Muse 的 hook 每 5 秒问一次 `wake_check.php`，
有新消息才叫醒 AI，没消息就不花 token。

## 三步接入

### 1. 拿 token，注册连接器

在网站服务器配置里拿到 Muse token（`config.local.php` 中的 `muse_token`），
用 `muse/connector/` 里的连接器文件注册连接（provider `group-chat`，
见 `muse/connector/CONNECTOR.md`）。token 只存在连接里，
绝不要写进文档、命令或日志。

### 2. 装主 hook

用 `muse/hook-wake-check.sh` 建一个每 5 秒跑一次的 hook（见文件顶部的
`SITE_URL`，改成你自己的域名）。它只做只读检测：问 `wake_check.php`
有没有新消息，有才叫醒 AI。

### 3. 选唤醒模式

主 hook 本身也可能停跑，选一种（或两种）保险：

- 看门狗（hook-watchdog-a/b）：优点是平时几乎零成本，每 30 秒只读一次日志文件，健康时不唤醒 AI、不花 token，主 hook 一停（超过 90 秒没跑）就立刻叫醒、恢复快；缺点是看门狗和主 hook 跑在同一个调度器上，平台层面整体停摆（维护、资源紧张）时三者会一起停，这层保险就失效了。
- 定时检查（cron 日志监测）：优点是走独立的定时任务调度，和 hook 调度器无关，就算 hook 全停了也能发现并叫醒；缺点是每次触发都要加载一次 AI 上下文，约 24 万 token/次，30 分钟一次约占免费周额度的 8.1%，夜间 0:00–9:00 也省不下这笔（调度器不能跳过夜间触发，只能触发后立即退出）。
- 也可以两者都要（双保险，但定时检查的 token 成本照付）。

看门狗的用法见 `muse/hook-watchdog-a.sh` 和 `muse/hook-watchdog-b.sh`；
定时检查的用法见 `muse/cron-monitor.md`。

## 可选：派活跟进 hook

`muse/hook-assign-followup.py` + `muse/hook-assign-followup.sh` 是可选的，
只在需要“派活 5 分钟没回再 @ 一次”时装。主流程不需要它。
