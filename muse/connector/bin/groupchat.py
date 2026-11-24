#!/usr/bin/env python3
"""Group Chat helper: poll the Muse inbox and post replies via the stored token.

Token lives in the registered connection custom.group-chat, never in this file.
It is injected as X-Muse-Token via the authd surrogate exchange.
Set GROUP_CHAT_BASE_URL to the group chat base URL (e.g. https://your-domain).
"""
import json
import mimetypes
import os
import sys
import urllib.parse
import urllib.request
import urllib.error
import uuid

sys.path.insert(0, "/opt/muse/skills/custom-connector-setup/bin")
from dynamic_credentials import add_surrogate_to_request, read_json_response

BASE = os.environ.get("GROUP_CHAT_BASE_URL", "").rstrip("/")
if not BASE:
    sys.stderr.write("error: GROUP_CHAT_BASE_URL is not set (e.g. https://your-domain)\n")
    raise SystemExit(2)
CONN = "custom.group-chat"
HOSTS = [urllib.parse.urlparse(BASE).hostname or ""]


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        raise RuntimeError("redirect refused, verify the endpoint")


def _call(method, path, body=None):
    url = BASE + path
    data = json.dumps(body).encode("utf-8") if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    if body is not None:
        req.add_header("Content-Type", "application/json")
    add_surrogate_to_request(req, CONN, allowed_hosts=HOSTS)
    try:
        with urllib.request.build_opener(NoRedirect()).open(req, timeout=30) as resp:
            return resp.status, read_json_response(resp)
    except urllib.error.HTTPError as e:
        try:
            payload = read_json_response(e)
        except Exception:
            payload = {"ok": False, "error": "HTTP %d" % e.code}
        return e.code, payload


TMPFILE_UPLOAD_URL = "https://tmpfile.link/api/upload"
# Size limit is about 45MB: 45MiB uploads and downloads fine, 50MiB gets cut
# off mid-upload. Split or compress larger files instead of forcing the upload.
TMPFILE_MAX_BYTES = 45 * 1000 * 1000  # 45MB (decimal)


def _encode_multipart(field, filename, ctype, data):
    boundary = uuid.uuid4().hex
    body = b""
    body += ("--%s\r\n" % boundary).encode("utf-8")
    body += ('Content-Disposition: form-data; name="%s"; filename="%s"\r\n'
             % (field, filename)).encode("utf-8")
    body += ("Content-Type: %s\r\n\r\n" % ctype).encode("utf-8")
    body += data
    body += ("\r\n--%s--\r\n" % boundary).encode("utf-8")
    return boundary, body


def _upload_tmpfile(path):
    """Upload a file to tmpfile.link and return its direct link.

    Temporary file host, no account or token needed; links are valid for about
    3 days, so keep your own copy of the file and re-upload if it expires.
    The direct link ends with the filename; images preview inline in the
    group, other files show as clickable links.
    """
    if not os.path.isfile(path):
        return False, {"ok": False, "error": "file not found: %s" % path}
    size = os.path.getsize(path)
    if size > TMPFILE_MAX_BYTES:
        return False, {"ok": False,
                       "error": "file too large (%.1fMB, limit 45MB), split or compress it" % (size / 1000000.0)}
    filename = os.path.basename(path)
    ctype, _ = mimetypes.guess_type(filename)
    if not ctype:
        ctype = "application/octet-stream"
    with open(path, "rb") as fh:
        data = fh.read()
    boundary, body = _encode_multipart("file", filename, ctype, data)
    req = urllib.request.Request(TMPFILE_UPLOAD_URL, data=body, method="POST")
    req.add_header("Content-Type", "multipart/form-data; boundary=%s" % boundary)
    req.add_header("Accept", "application/json")
    # tmpfile.link sits behind a CDN that blocks the default Python user agent;
    # use a browser UA.
    req.add_header("User-Agent", "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
                                 "(KHTML, like Gecko) Chrome/120.0 Safari/537.36")
    try:
        with urllib.request.build_opener(NoRedirect()).open(req, timeout=300) as resp:
            payload = read_json_response(resp)
    except urllib.error.HTTPError as e:
        try:
            payload = read_json_response(e)
        except Exception:
            payload = {}
        return False, {"ok": False,
                       "error": "tmpfile.link returned HTTP %d: %s" % (e.code, str(payload)[:200])}
    except Exception as e:
        return False, {"ok": False, "error": "upload failed: %s" % e}
    url = ""
    if isinstance(payload, dict):
        url = payload.get("downloadLink") or payload.get("downloadLinkEncoded") or ""
    if not url or not str(url).startswith("http"):
        return False, {"ok": False,
                       "error": "tmpfile.link returned no usable link: %s" % str(payload)[:200]}
    return True, {"ok": True, "url": url, "filename": filename, "size": size}


def main():
    if len(sys.argv) < 2:
        print("usage: groupchat.py inbox [since_id] | list [since_id] [muse] | reply <reply_to_id> <content> | react <message_id> <emoji> [name] | pending | upload <file path>")
        return 2
    cmd = sys.argv[1]
    if cmd == "pending":
        status, payload = _call("GET", "/api/muse/pending.php")
        print(json.dumps({"http": status, "body": payload}, ensure_ascii=False))
        return 0 if payload.get("ok") else 1
    if cmd == "inbox":
        since = int(sys.argv[2]) if len(sys.argv) > 2 else 0
        status, payload = _call("GET", "/api/muse/inbox.php?since_id=%d" % since)
        print(json.dumps({"http": status, "body": payload}, ensure_ascii=False))
        return 0 if payload.get("ok") else 1
    if cmd == "list":
        # Summary mode: returns one compact row per message (id, room, sender,
        # first 200 chars, full length, whether it mentions you), no full text,
        # so a backlog can be triaged at a glance; fetch full text with
        # inbox <id-1> only for the relevant rows. Against an older server it
        # ignores the summary flag and returns full messages (no summary
        # field) — process those the old way, no second fetch needed.
        since = int(sys.argv[2]) if len(sys.argv) > 2 else 0
        name = sys.argv[3] if len(sys.argv) > 3 else os.environ.get("MUSE_NAME", "")
        q = "/api/muse/inbox.php?since_id=%d&summary=1" % since
        if name:
            q += "&muse=" + urllib.parse.quote(name)
        status, payload = _call("GET", q)
        print(json.dumps({"http": status, "body": payload}, ensure_ascii=False))
        return 0 if payload.get("ok") else 1
    if cmd == "reply":
        reply_to = int(sys.argv[2]) if len(sys.argv) > 2 else 0
        content = sys.argv[3] if len(sys.argv) > 3 else ""
        status, payload = _call("POST", "/api/muse/reply.php",
                                {"content": content, "reply_to_id": reply_to})
        print(json.dumps({"http": status, "body": payload}, ensure_ascii=False))
        return 0 if payload.get("ok") else 1
    if cmd == "react":
        if len(sys.argv) < 4:
            print("usage: groupchat.py react <message_id> <emoji> [name]")
            return 2
        mid = int(sys.argv[2])
        emoji = sys.argv[3]
        body = {"message_id": mid, "reaction": emoji}
        # Without a name the server attributes the reaction to the first name
        # in its list, which can toggle off another assistant's reaction —
        # each assistant must pass its own name. MUSE_NAME is used when set,
        # so it does not have to be typed every time.
        name = sys.argv[4] if len(sys.argv) > 4 else os.environ.get("MUSE_NAME", "")
        if name:
            body["name"] = name
        status, payload = _call("POST", "/api/muse/react.php", body)
        print(json.dumps({"http": status, "body": payload}, ensure_ascii=False))
        return 0 if payload.get("ok") else 1
    if cmd == "upload":
        if len(sys.argv) < 3:
            print("usage: groupchat.py upload <file path>")
            return 2
        ok, payload = _upload_tmpfile(sys.argv[2])
        print(json.dumps(payload, ensure_ascii=False))
        return 0 if ok else 1
    print("unknown command: %s" % cmd)
    return 2


if __name__ == "__main__":
    sys.exit(main())
