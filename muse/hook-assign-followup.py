#!/usr/bin/env python3
"""派活跟进检查：读 pending_assignments.json，派活超过 5 分钟没回的列出来。
只读文件 + 调一次收件箱摘要，不花 token。输出 JSON 决策到 stdout：
{"checked": bool, "error": str|None, "wake": [entries], "new_pending": [...]}。
干跑（HATCH_HOOK_DRY_RUN=1）时不写文件。
"""
import json
import os
import subprocess
import time

PENDING = os.path.expanduser("~/workspace/group-chat/pending_assignments.json")
GROUPCHAT = ["python3", os.path.expanduser("~/workspace/group-chat/muse/connector/bin/groupchat.py")]
FOLLOWUP_SECS = 300
EXPIRE_SECS = 86400
DRY_RUN = os.environ.get("HATCH_HOOK_DRY_RUN") == "1"


def load():
    try:
        with open(PENDING, "r", encoding="utf-8") as f:
            data = json.load(f)
        return data if isinstance(data, list) else []
    except FileNotFoundError:
        return []
    except Exception:
        return None


def save(entries):
    if DRY_RUN:
        return
    tmp = PENDING + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(entries, f, ensure_ascii=False)
    os.replace(tmp, PENDING)


def fetch_messages(since_id, pages=4):
    """调 list 取 since_id 之后的消息（摘要），翻页最多 pages 次。失败返回 None。"""
    out = []
    cur = since_id
    for _ in range(pages):
        try:
            r = subprocess.run(GROUPCHAT + ["list", str(cur)], capture_output=True, text=True, timeout=25)
        except Exception:
            return None
        if r.returncode != 0:
            return None
        try:
            body = json.loads(r.stdout).get("body", {})
        except Exception:
            return None
        msgs = body.get("messages") or []
        out.extend(msgs)
        if len(msgs) < 50:
            break
        cur = msgs[-1]["id"]
    return out


def main():
    entries = load()
    if entries is None:
        print(json.dumps({"checked": False, "error": "pending file corrupt", "wake": [], "new_pending": []}, ensure_ascii=False))
        return
    now_ms = int(time.time() * 1000)
    due = [
        e for e in entries
        if e.get("reminded_at") in (None, 0)
        and (now_ms - int(e.get("assigned_at_ms", 0))) > FOLLOWUP_SECS * 1000
    ]
    if not due:
        print(json.dumps({"checked": True, "error": None, "wake": [], "new_pending": entries}, ensure_ascii=False))
        return
    since = min(int(e["message_id"]) for e in due)
    msgs = fetch_messages(since)
    if msgs is None:
        print(json.dumps({"checked": False, "error": "inbox fetch failed", "wake": [], "new_pending": entries}, ensure_ascii=False))
        return
    wake, kept = [], []
    due_ids = {id(e) for e in due}
    for e in entries:
        mid, room, who = int(e["message_id"]), int(e["room_id"]), e.get("assignee", "")
        if (now_ms - int(e.get("assigned_at_ms", 0))) > EXPIRE_SECS * 1000:
            continue
        replied = any(
            int(m.get("id", 0)) > mid
            and int(m.get("room_id", -1)) == room
            and m.get("username") == who
            for m in msgs
        )
        if replied:
            continue
        if id(e) in due_ids:
            e = dict(e)
            e["reminded_at"] = now_ms
            wake.append(e)
        kept.append(e)
    save(kept)
    print(json.dumps({"checked": True, "error": None, "wake": wake, "new_pending": kept}, ensure_ascii=False))


main()
