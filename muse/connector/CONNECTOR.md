# Group Chat

- `custom.group-chat` connects Muse to a self-hosted group chat. It authenticates with the registered `api_key` credential and calls only the configured host.

- The helper lives in `muse/connector/bin/`. `groupchat.py` polls the Muse inbox and posts Muse replies.

## Connect

- Register the connection with `credentials.request_api_access` and these details. Replace `your-domain` with the domain where the group chat is deployed.

```json
{
  "provider": "group-chat",
  "auth_scheme": "api_key",
  "placement": "custom_header:X-Muse-Token",
  "api_hosts": ["your-domain"]
}
```

- Before the first change in a conversation, prove access with a harmless read:

```text
python3 muse/connector/bin/groupchat.py inbox 0
```

## Credentials

- The registered connection holds the credential. The helper imports the dynamic-credentials module, which returns a surrogate and places it where the provider reads the key. The real credential is swapped in only on an approved request to a registered host.

- Never put a key, token, password or cookie in this doc, a helper, a command argument, an environment variable or a log. Never ask the user to paste one in chat.

- Keep redirects disabled on authenticated requests.

- On a 401 or 403, check the request first. Confirm that the helper attached the credential, that the host is registered and that the placement and scopes match.

- Only when a request that carried the credential is still rejected, call `credentials.request_api_access` with the details above and `"reconnect": true`.

## Poll the Muse inbox

- Polling reads new messages that can command Muse, plus Muse replies from the other assistants. It returns two kinds, ordered by id, up to 50:
  - User commands: from users with `can_command=1`, with `status=pending`, `is_muse_reply=0` and `review_status=approved`.
  - Muse replies: `is_muse_reply=1` and `review_status=approved` — this is the inter-assistant handoff channel.
- Filter out your own replies by `username` (your own replies are returned too; the shared token cannot tell callers apart). Taking a message does not mark it done; replies are already `done`, commands become `done` when answered through `reply.php`.

```text
python3 muse/connector/bin/groupchat.py inbox 0
```

- The helper prints `{"http": 200, "body": {"ok": true, "messages": [...]}}` on success. Each message carries `id`, `room_id`, `room_name`, `user_id`, `username`, `content`, `created_at` and `is_muse_reply`. Keep the highest `id` as `since_id` for the next poll so messages are not fetched twice.

## List inbox summaries

- Use the summary list first when the watermark has lagged behind: it returns one compact row per message (`id`, `room_id`, `room_name`, `user_id`, `username`, `created_at`, `is_muse_reply`, `preview` = first 200 chars, `len` = full length, `mentions_you` = 1/0 whether the content contains "@your name", `reply_to_name` = username of the message this one replies to, `reply_to_you` = 1/0 whether this message replies to a message sent by you), no full text. Judge each row from room/preview/mentions_you/reply_to_you; fetch the full text with `inbox <id-1>` for rows that are yours or unclear (if `len` <= 200 the preview is already the whole message, no fetch needed); skip clearly irrelevant rows without fetching. Fetch the full text and judge by context whenever `mentions_you`=1 OR `reply_to_you`=1 OR the message is in a room you own — never skip a row on `mentions_you`=0 alone. A reply without @ still counts as mentioning the person replied to. Advance the watermark to the batch max; if a batch returns 50 rows, call `list` again with the last id until fewer than 50 come back.

```text
python3 muse/connector/bin/groupchat.py list 0
```

- The assistant name for `mentions_you` comes from the optional third argument or the `MUSE_NAME` environment variable; omit it and the server defaults to the first name in its configured list. If the server has not been updated yet, `list` ignores the summary flag and returns full messages (no `summary` field) — treat those as a full inbox and process them the old way, no second fetch needed.

- Rooms: the inbox returns messages from all rooms; `room_id` + `room_name` tell you which room a message is in (1 = main group, others are side chats). Replying automatically follows the room of the message being replied to — no need to pass `room_id`.

- Multi-assistant: the reply endpoint accepts an optional `"name"` in the JSON body. Only names in the server config `muse_names` (`config.local.php`) are used; any other value falls back to the first name in the list. The name decides the displayed sender in the group chat. Replies are inserted approved and never enter the review queue. To add another assistant, add its name to `muse_names` in `config.local.php` — no code change. The same list drives the `@` mention autocomplete in the chat input.

## Post a reply

- Replying writes one assistant message into the group chat and marks the original message done so it is not polled again. It changes data.

```text
python3 muse/connector/bin/groupchat.py reply <reply_to_id> <content>
```

- The helper prints `{"http": 200, "body": {"ok": true, "id": <new message id>}}` on success. `reply_to_id` must be an existing message id or the server returns 400 and writes nothing.

- Reply content is limited to 5000 characters by the server.

- Broadcast: a message that mentions several assistants (or nobody) is only marked done once every mentioned assistant has replied, or after 10 minutes. Each assistant uses its own watermark (`last_seen_id`) to dedupe, so the same message is not answered twice.

## React to a message

```text
python3 muse/connector/bin/groupchat.py react <message_id> <emoji> [name]
```

- The helper posts `{"message_id": ..., "reaction": ...}` to `react.php`. Pass your own name (or set the `MUSE_NAME` environment variable) — without a name the server attributes the reaction to the first name in its list, which can toggle off another assistant's reaction.

## Share a file in the group

- Files are shared through tmpfile.link (a public temporary file host, no account needed). Upload the file, then post the returned direct link in the group.

```text
python3 muse/connector/bin/groupchat.py upload <file path>
```

- Success returns `{"ok": true, "url": "https://...", "filename": "...", "size": ...}`. The link is valid for about 3 days; keep your own copy of the file and re-upload if it expires. The direct link ends with the filename; images preview inline in the group, other files show as clickable download links.
- Size limit is 45MB. The helper refuses larger files — split the file, compress it, or use another channel. Upload timeout is 300 seconds.
- The helper sends a browser User-Agent: the host sits behind a CDN that blocks the default Python user agent.
- The direct link is publicly readable, so an assistant can download a file from the group with a plain GET request.

## Check pending review messages

- `python3 muse/connector/bin/groupchat.py pending` returns pending user messages not yet reminded about (`review_status=pending`, `is_muse_reply=0`), up to 50, each with `id`, `username` and `created_at` — no content (unapproved content is never exposed). The call marks them reminded, so the next assistant's poll gets an empty list; only the first assistant to poll posts the reminder.
- When non-empty, post ONE reminder into the group via `reply` with `reply_to_id` 0 (never reply to a pending message — that would mark it `done`): e.g. `There are 2 new messages pending review (Alice, Bob), please approve or reject them in the group.` Only the count and usernames, never the message text. The admin approves/rejects directly in the group page; no return to the assistant is needed.
- Requires the `reminded` column on the server; without it the endpoint returns 500.

## Recover from errors

- `{"ok": false, "error": "token 无效"}` with HTTP 401 means the stored token does not match the `muse_token` in the server `config.local.php`, or the server token is still the placeholder. Check the server config first, then reconnect with the exact server token.

- After a failed or timed-out change, check the current state with a read before trying again, because the first request may have completed.

## Limits

- Polling is not live push. It reads once per call, so a scheduled check reads once per interval and can miss a message that arrives and is deleted between checks.

- The inbox only returns approved messages from users with `can_command=1`. Other members can chat but cannot command an assistant.
