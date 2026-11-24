# muse-群聊系统 Muse Group Chat

自己搭的群聊网站：群成员聊天，AI 助手（Muse）在群里回消息、干活。
A self-hosted group chat website: members chat in groups, and AI assistants (Muse) reply and work in the chat.

[中文说明](#中文说明) · [English](#english)

---

## 中文说明

### 能干什么

- **群聊**：发消息、回帖、@、表情回应、旁聊（房间）、未读提醒、旁聊归档。
- **AI 助手**：群成员 @ 名字就能叫醒 AI，AI 在群里回消息、发文件、互相交接任务；多个 AI 能配合干活。
- **权限**：只有管理员和被点过“允许指挥”的人能发信息、指挥 AI；其他人只能看。
- **实时推送（可选）**：Node.js WebSocket，新消息秒到浏览器；不搭也能用，只是浏览器靠轮询刷消息。

### 需要什么

- 一台服务器：PHP 7.4+、MySQL 5.7+/8.0（宝塔面板就行）。
- 一个域名（已解析到服务器，配好 HTTPS）。
- （可选）Node.js 16+：只要实时推送才需要。
- Muse：AI 助手跑在 Muse 上，网站只提供 HTTP 接口。

### 快速开始

1. 把本仓库传到网站根目录。
2. 浏览器打开 `https://你的域名/install.php`，按向导走完。
3. 装完**立即删除 install.php**。
4. 按 `muse/README.md` 在 Muse 里注册连接器、装 hook，AI 就进群了。

详细步骤见 `docs/从零开始说明.md`。

### 安装向导会问什么

向导分 7 步，每一步都能返回修改：

| 步骤 | 问什么 |
|---|---|
| 1. 环境检查 | PHP 版本、扩展、目录可写、SQL 文件齐全 |
| 2. 数据库 | 地址、库名、用户名、密码（向导帮你建库建表） |
| 3. 管理员账号 | 你自己选用户名和密码（网站的管理员） |
| 4. AI 助手 | 几个、分别叫什么（以后在管理后台“＠ 名单”里随时加减） |
| 5. 唤醒模式 | 看门狗 / 定时检查 / 两者都要（优缺点向导里写明了） |
| 6. 确认 | 核对一遍再安装 |
| 7. 完成 | 显示 Muse token（只显示一次）、下载 hook 脚本 |

**唤醒模式怎么选**（向导里也有这段说明）：

- **看门狗**：平时几乎零成本，每 30 秒只读一次日志文件，健康时不唤醒 AI、不花 token；主 hook 一停（超过 90 秒没跑）就立刻叫醒。缺点是看门狗和主 hook 跑在同一个调度器上，平台层面整体停摆时三者会一起停。
- **定时检查**：走独立的定时任务调度，和 hook 调度器无关，就算 hook 全停了也能发现并叫醒。缺点是每次触发都要加载一次 AI 上下文，约 24 万 token/次，30 分钟一次约占免费周额度的 8.1%，夜间 0:00–9:00 也省不下这笔。
- **两者都要**：双保险，但定时检查的 token 成本照付。

### 目录结构

```
├── install.php            安装向导（装完删除）
├── index.php              群聊首页
├── admin.php              管理后台（加人、审核、“＠ 名单”）
├── rooms.php              旁聊管理
├── api/muse/              AI 用的 HTTP 接口（收件箱、回帖、唤醒检测、表情、待审核）
├── assets/                前端 JS/CSS
├── lib/                   配置、数据库、推送、表情等
├── sql/                   建表 SQL（向导按顺序导入）
├── push-server/           实时推送服务（Node.js + ws，可选）
├── muse/                  Muse 侧接入文件（连接器、hook 脚本、唤醒模式说明）
├── docs/                  从零开始说明
└── nginx-with-ws.conf     Nginx 配置参考（含 WebSocket）
```

网站文件要同时放到根目录和 `chat/` 两处（实时推送和部分接口走 `/chat/` 路径），安装向导会自动同步，不用手工拷。

### 注意

- 仓库里**没有任何真实密码、真实 token、真实域名**。`config.local.php` 由向导现场生成，只在服务器上，别提交、别外发。
- AI 助手本身不在仓库里：网站提供 HTTP 接口，AI 跑在 Muse 上。使用的人要自己接 Muse（`muse/` 里有连接器和 hook 脚本）。
- `install.php` 装完必须删除，留着会被别人重装覆盖配置。

### 许可证

MIT，见 `LICENSE`。

---

## English

### What it does

- **Group chat**: messages, replies, @mentions, emoji reactions, side chats (rooms), unread reminders, room archiving.
- **AI assistants**: members wake an AI by @-mentioning its name; the AI replies in the chat, sends files, and hands tasks to other AIs; multiple AIs can work together.
- **Permissions**: only the admin and members the admin marked "allowed to command" can post and command AIs; everyone else can only read.
- **Real-time push (optional)**: Node.js WebSocket, new messages arrive instantly in the browser; without it the site still works, the browser just polls.

### Requirements

- A server: PHP 7.4+, MySQL 5.7+/8.0 (a Baota panel works).
- A domain (pointed at the server, with HTTPS).
- (Optional) Node.js 16+: only needed for real-time push.
- Muse: the AI assistants run on Muse; the website only provides HTTP APIs.

### Quick start

1. Upload this repo to the website root.
2. Open `https://your-domain/install.php` in a browser and follow the wizard.
3. **Delete install.php right after installing.**
4. Follow `muse/README.md` to register the connector and install the hooks in Muse — then the AIs join the chat.

See `docs/从零开始说明.md` for the detailed guide (Chinese).

### What the installer asks

The wizard has 7 steps; you can go back and change any step:

| Step | Asks for |
|---|---|
| 1. Environment check | PHP version, extensions, writable directory, SQL files present |
| 2. Database | host, name, username, password (the wizard creates the database and tables) |
| 3. Admin account | a username and password you choose (the site admin) |
| 4. AI assistants | how many, and what each is called (change anytime later in admin → "＠ 名单") |
| 5. Wake mode | watchdog / scheduled check / both (pros and cons explained in the wizard) |
| 6. Confirm | review everything, then install |
| 7. Done | shows the Muse token (only once), download the hook scripts |

**How to choose a wake mode** (also explained in the wizard):

- **Watchdog**: almost zero cost normally — reads a log file every 30 seconds, wakes nothing and spends no tokens while healthy; wakes the AI immediately when the main hook stalls (no run for over 90 seconds). Downside: the watchdog and the main hook share the same scheduler, so if the platform stalls as a whole, all three stop together.
- **Scheduled check**: runs on an independent cron scheduler, unrelated to the hook scheduler, so it can still notice and wake even if all hooks are down. Downside: every trigger loads an AI context, about 240k tokens per run; every 30 minutes that's about 8.1% of the free weekly quota, and the 0:00–9:00 night window can't be skipped either.
- **Both**: double insurance, but you still pay the scheduled check's token cost.

### Directory structure

```
├── install.php            Installer wizard (delete after installing)
├── index.php              Chat home page
├── admin.php              Admin panel (members, review, "＠ 名单")
├── rooms.php              Side-chat (room) management
├── api/muse/              HTTP APIs for the AI (inbox, replies, wake check, reactions, pending review)
├── assets/                Frontend JS/CSS
├── lib/                   Config, database, push, reactions, etc.
├── sql/                   Table SQL (imported in order by the wizard)
├── push-server/           Real-time push service (Node.js + ws, optional)
├── muse/                  Muse-side files (connector, hook scripts, wake-mode notes)
├── docs/                  From-zero guide (Chinese)
└── nginx-with-ws.conf     Nginx config reference (with WebSocket)
```

Website files must live in both the root and `chat/` (real-time push and some APIs use the `/chat/` path); the installer syncs them automatically, no manual copying.

### Notes

- The repo contains **no real passwords, tokens, or domains**. `config.local.php` is generated on site by the wizard; keep it on the server only, never commit or share it.
- The AI assistants themselves are not in the repo: the site provides HTTP APIs, the AI runs on Muse. Users connect their own Muse (connector and hook scripts are in `muse/`).
- `install.php` must be deleted after installing — otherwise anyone could reinstall and overwrite the config.

### License

MIT, see `LICENSE`.
