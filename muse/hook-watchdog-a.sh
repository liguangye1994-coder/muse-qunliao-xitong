#!/usr/bin/env bash
# 看门狗 A：盯看门狗 B 的日志。B 正常每 30 秒跑一次，
# 日志最后一条超过 180 秒没动静，说明 B 停了，叫醒 worker 接手（并顺带看主 hook 是否也停了）。
# 本脚本只做只读检测：读日志、读写自己的去重状态，不碰 token、不写消息。
set -euo pipefail
source "$HATCH_HOOK_RUNTIME"

B_LOG="$HOME/hooks/logs/group-chat-watchdog-b.jsonl"
STATE_DIR="$HOME/hooks/state/group-chat-watchdog-a"
STATE_FILE="$STATE_DIR/last_alert.json"
STALE_SECS=180
RE_ALERT_SECS=600

mkdir -p "$STATE_DIR"

now_ms=$(date +%s%3N)
now=$(date +%s)

# B 最后一次运行的时间（毫秒）；B 还没跑过、日志不存在时当它还没启动，不叫醒
last_b_run_ms=0
b_log_exists=0
if [ -f "$B_LOG" ]; then
  b_log_exists=1
  last_b_run_ms=$(tail -n 1 "$B_LOG" 2>/dev/null | jq -r '.started_at_ms // 0' 2>/dev/null || echo 0)
  case "$last_b_run_ms" in ''|*[!0-9]*) last_b_run_ms=0 ;; esac
fi

stale_secs=0
if [ "$b_log_exists" = "1" ] && [ "$last_b_run_ms" -gt 0 ]; then
  stale_secs=$(( (now_ms - last_b_run_ms) / 1000 ))
elif [ "$b_log_exists" = "1" ]; then
  stale_secs=999999
else
  stale_secs=0
fi

# 上次叫醒的状态（防重复叫醒）：{last_b_run_ms, last_alert_at}
last_seen_run_ms=0
last_alert_at=0
if [ -f "$STATE_FILE" ]; then
  last_seen_run_ms=$(jq -r '.last_b_run_ms // 0' "$STATE_FILE" 2>/dev/null || echo 0)
  last_alert_at=$(jq -r '.last_alert_at // 0' "$STATE_FILE" 2>/dev/null || echo 0)
fi

log "observation" "{\"last_b_run_ms\":$last_b_run_ms,\"stale_secs\":$stale_secs,\"b_log_exists\":$b_log_exists}"

# 叫醒条件：B 的日志存在且停了，且是新的一轮停跑（日志时间变了），或同一轮停跑已超过 10 分钟没再叫过
should_wake="false"
if [ "$b_log_exists" = "1" ] && [ "$stale_secs" -gt "$STALE_SECS" ]; then
  if [ "$last_b_run_ms" != "$last_seen_run_ms" ] || [ $((now - last_alert_at)) -gt "$RE_ALERT_SECS" ]; then
    should_wake="true"
  fi
fi

if [ "$should_wake" = "true" ]; then
  # dry run 不写状态
  if [ "${HATCH_HOOK_DRY_RUN:-0}" != "1" ]; then
    printf '{"last_b_run_ms":%s,"last_alert_at":%s}' "$last_b_run_ms" "$now" > "$STATE_FILE"
  fi
  wake "watchdog-b-stalled" "{\"stale_secs\":$stale_secs,\"last_b_run_ms\":$last_b_run_ms}"
else
  silent "watchdog-b-ok" "{\"stale_secs\":$stale_secs,\"b_log_exists\":$b_log_exists}"
fi
