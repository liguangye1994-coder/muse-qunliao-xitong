#!/usr/bin/env bash
# 派活跟进 hook：派活 5 分钟后，被派的人在那个房间还没回，就叫醒 worker 去群里再 @ 一遍。
# 检查本身只读文件 + 调收件箱摘要，不花 token；只有真要提醒时才叫醒 agent。
# 干跑（HATCH_HOOK_DRY_RUN=1）时不写任何状态。
set -euo pipefail
source "$HATCH_HOOK_RUNTIME"

DECISION=$(python3 "$HOME/hooks/scripts/hook-assign-followup.py" 2>/dev/null || echo '{"checked":false,"error":"helper failed","wake":[],"new_pending":[]}')

checked=$(echo "$DECISION" | jq -r '.checked // false' 2>/dev/null || echo false)
wake_count=$(echo "$DECISION" | jq -r '.wake | length' 2>/dev/null || echo 0)
err=$(echo "$DECISION" | jq -r '.error // ""' 2>/dev/null || echo "")

log "observation" "{\"checked\":$checked,\"wake_count\":$wake_count,\"error\":\"$err\"}"

if [ "$checked" != "true" ]; then
  silent "check-failed" "{\"error\":\"$err\"}"
elif [ "$wake_count" -gt 0 ]; then
  wake_payload=$(echo "$DECISION" | jq -c '.wake' 2>/dev/null || echo '[]')
  wake "assignment-no-reply" "{\"assignments\":$wake_payload}"
else
  silent "all-answered-or-waiting" "{}"
fi
