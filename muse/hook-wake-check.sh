#!/usr/bin/env bash
# 群聊站 hook 唤醒检测：每 5 秒问服务器一句“有没有需要处理的新消息”，
# 有才叫醒 agent，没消息就不花 token。替代原来每分钟跑一次 agent 轮询。
# 本脚本只做只读检测：读水位文件、curl 公开接口、读写自己的去重状态，
# 不碰 token、不写消息、不标已读。
set -euo pipefail
source "$HATCH_HOOK_RUNTIME"

# 改成你自己的域名，例如 https://chat.example.com
SITE_URL="https://你的域名"

WATERMARK_FILE="$HOME/workspace/group-chat/last_seen_id.txt"
STATE_DIR="$HOME/hooks/state/group-chat-wake-check"
STATE_FILE="$STATE_DIR/last_woke.json"
ENDPOINT="$SITE_URL/api/muse/wake_check.php"

mkdir -p "$STATE_DIR"

# 水位：上次处理到的最大消息 id，没有文件当 0
since_id=0
if [ -f "$WATERMARK_FILE" ]; then
  since_id=$(tr -d '[:space:]' < "$WATERMARK_FILE")
  case "$since_id" in ''|*[!0-9]*) since_id=0 ;; esac
fi

# 上次叫醒的状态（防重复叫醒）：{max_id, pending, woke_at}
last_max=0
last_pending=0
last_woke_at=0
if [ -f "$STATE_FILE" ]; then
  last_max=$(jq -r '.max_id // 0' "$STATE_FILE" 2>/dev/null || echo 0)
  last_pending=$(jq -r '.pending // 0' "$STATE_FILE" 2>/dev/null || echo 0)
  last_woke_at=$(jq -r '.woke_at // 0' "$STATE_FILE" 2>/dev/null || echo 0)
fi
now=$(date +%s)

# 问服务器一句（公开接口，只回数字，不用 token；接口还没传到服务器时会 404，
# 那是预期内的失败，只记日志、不叫醒）
if ! resp=$(curl --fail --silent --show-error --max-time 3 "$ENDPOINT?since_id=$since_id" 2>&1); then
  log "wake-check-failed" "{\"since_id\":$since_id}"
  silent "wake-check-unreachable" "{\"since_id\":$since_id}"
fi

has_new=$(echo "$resp" | jq -r '.has_new // false' 2>/dev/null || echo false)
max_id=$(echo "$resp" | jq -r '.max_id // 0' 2>/dev/null || echo 0)
pending=$(echo "$resp" | jq -r '.pending // 0' 2>/dev/null || echo 0)

# 叫醒条件：有新命中的消息且没叫过（或上次叫醒后 10 分钟水位还没动，说明
# agent 那次没跑成，重新叫一次）；或有待审核且上次没叫过待审核（同理 10 分钟兜底）
stale=0
if [ $((now - last_woke_at)) -gt 600 ]; then stale=1; fi

should_wake="false"
if [ "$has_new" = "true" ]; then
  if [ "$max_id" -gt 0 ] && { [ "$max_id" -gt "$last_max" ] || [ "$stale" = "1" ]; }; then should_wake="true"; fi
  if [ "$pending" -gt 0 ] && { [ "$last_pending" = "0" ] || [ "$stale" = "1" ]; }; then should_wake="true"; fi
fi

log "observation" "{\"since_id\":$since_id,\"has_new\":$has_new,\"max_id\":$max_id,\"pending\":$pending,\"should_wake\":$should_wake}"

if [ "$should_wake" = "true" ]; then
  # dry run 不写状态、不消耗检测
  if [ "${HATCH_HOOK_DRY_RUN:-0}" != "1" ]; then
    printf '{"max_id":%s,"pending":%s,"woke_at":%s}' "$max_id" "$pending" "$now" > "$STATE_FILE"
  fi
  wake "new-message" "{\"since_id\":$since_id,\"max_id\":$max_id,\"pending\":$pending}"
else
  silent "no-new-message" "{\"since_id\":$since_id,\"max_id\":$max_id,\"pending\":$pending}"
fi
