#!/usr/bin/env bash
# 看门狗 B：盯主 hook（group-chat-wake-check）的日志。主 hook 正常每 5 秒跑一次，
# 日志最后一条超过 90 秒没动静，说明主 hook 停了，叫醒 worker 接手处理积压消息并踢醒它。
# 本脚本只做只读检测：读日志、读写自己的去重状态，不碰 token、不写消息。
set -euo pipefail
source "$HATCH_HOOK_RUNTIME"

MAIN_LOG="$HOME/hooks/logs/group-chat-wake-check.jsonl"
STATE_DIR="$HOME/hooks/state/group-chat-watchdog-b"
STATE_FILE="$STATE_DIR/last_alert.json"
STALE_SECS=90
RE_ALERT_SECS=600

mkdir -p "$STATE_DIR"

now_ms=$(date +%s%3N)
now=$(date +%s)

# 主 hook 最后一次运行的时间（毫秒）
last_main_run_ms=0
if [ -f "$MAIN_LOG" ]; then
  last_main_run_ms=$(tail -n 1 "$MAIN_LOG" 2>/dev/null | jq -r '.started_at_ms // 0' 2>/dev/null || echo 0)
  case "$last_main_run_ms" in ''|*[!0-9]*) last_main_run_ms=0 ;; esac
fi

stale_secs=0
if [ "$last_main_run_ms" -gt 0 ]; then
  stale_secs=$(( (now_ms - last_main_run_ms) / 1000 ))
else
  stale_secs=999999
fi

# 上次叫醒的状态（防重复叫醒）：{last_main_run_ms, last_alert_at}
last_seen_run_ms=0
last_alert_at=0
if [ -f "$STATE_FILE" ]; then
  last_seen_run_ms=$(jq -r '.last_main_run_ms // 0' "$STATE_FILE" 2>/dev/null || echo 0)
  last_alert_at=$(jq -r '.last_alert_at // 0' "$STATE_FILE" 2>/dev/null || echo 0)
fi

log "observation" "{\"last_main_run_ms\":$last_main_run_ms,\"stale_secs\":$stale_secs}"

# 叫醒条件：主 hook 停了，且是新的一轮停跑（日志时间变了），或同一轮停跑已超过 10 分钟没再叫过
should_wake="false"
if [ "$stale_secs" -gt "$STALE_SECS" ]; then
  if [ "$last_main_run_ms" != "$last_seen_run_ms" ] || [ $((now - last_alert_at)) -gt "$RE_ALERT_SECS" ]; then
    should_wake="true"
  fi
fi

if [ "$should_wake" = "true" ]; then
  # dry run 不写状态
  if [ "${HATCH_HOOK_DRY_RUN:-0}" != "1" ]; then
    printf '{"last_main_run_ms":%s,"last_alert_at":%s}' "$last_main_run_ms" "$now" > "$STATE_FILE"
  fi
  wake "main-hook-stalled" "{\"stale_secs\":$stale_secs,\"last_main_run_ms\":$last_main_run_ms}"
else
  silent "main-hook-ok" "{\"stale_secs\":$stale_secs}"
fi
