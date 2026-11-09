# Assistant live voice: connection reliability

Live voice in the web assistant (and the Flutter app, which embeds the same page at
`/assistant/embed`) runs through a small connection state machine in
`public/js/assistant/live.js`. The panel code in `public/js/assistant.js` only maps its
events onto the screen. Typed chat always uses `POST /assistant/chat`; Live carries voice
only. Every Live connection uses a fresh single-use token from `POST /assistant/session`.

Ported from DIELCO's `liveClient.js`, `fallbackClient.js` and the failure modes table in
its `docs/ASSISTANT.md`, adapted to SeaPass endpoints.

## States

`idle` > `connecting` > `ready`, then on trouble `reconnecting`, `fallback` (shown as
**Text mode**), `offline` or `unavailable`. `closed` after Stop voice.

## Failure modes: what the user sees

| Cause | State | What is shown |
|---|---|---|
| WebSocket drops mid-conversation | `reconnecting` then `ready` | "Connection lost. Reconnecting…", then the same conversation resumes (session resumption handle). Mic audio spoken during the gap is held (last few seconds) and sent once ready. A reply cut off by the drop ends cleanly. |
| No resumption handle yet | `reconnecting` then `ready` | Same as above; the new session is seeded with the last turns of the chat instead. |
| 3 failures within 60 s | `fallback` | Status "Text mode" and "Voice is having trouble, so answers come as text for now." Typing keeps working. A background probe retries Live about every minute and switches back only at a turn boundary (no typed reply in flight). Then "Voice is back. Listening." |
| `/assistant/session` returns 503 (no AI key, provider down) | `fallback` at once | Text mode with the server's plain message. |
| `/assistant/session` returns 429 (throttle shared with chat) | `fallback` at once | "Voice is busy right now, so answers come as text for now." Probe after a minute. |
| Provider closes with a quota reason | `fallback` at once | Same busy line; probes back off from 2 minutes up to 15. |
| `goAway` from the server | stays `ready` | "Refreshing the connection…" briefly. A replacement opens and takes over before the old socket closes. If the user spoke on the old socket during the handover, the replacement is a turn behind, so it is dropped and redone from the newer handle. |
| Setup never completes (12 s) or is rejected | counts as a failure | Same path as a drop. A rejected resumption handle is not used again. |
| A turn never finishes (30 s with no progress) | stays `ready` | "That took too long. Try again." The watchdog pauses while a SeaPass tool is running. |
| Typed chat takes over 90 s | n/a | "That took too long. Try again." with a Retry button. |
| Browser goes offline | `offline` | "You are offline." No reconnect attempts while offline; resumes on its own once back online. Messages typed while offline wait (up to 5) and send in order when the connection returns. |
| Signed out (401 or 419) | `unavailable` | "Sign in again to use the assistant." No retries. |
| Mic denied or unavailable | voice stops | "Microphone access was denied. You can still type to the assistant." Said once per tap. No session token is spent, because the mic is asked for first. |
| Malformed or unknown server message | unchanged | Ignored; the next valid message still works. |
| Tool call cancelled by the model | unchanged | The running request is aborted and no tool response is sent. |
| Any error text | n/a | Only short plain sentences reach the UI. Stack traces, SQL, paths, URLs and anything that looks like a key or token are replaced by a generic line. |

Role scoping and staged confirmations are unchanged: Live tool calls still go through
`POST /assistant/tool`, and every change still needs the one-time confirmation card.

## Tests

- `node --test tests/js/*.test.mjs` runs the state machine tests with a fake WebSocket,
  a manual clock and a fake online signal. No browser, no network. Node 24 needs the
  file glob; it does not accept a bare directory.
- `tests/Feature/AssistantLiveContractTest.php` pins the `/assistant/session` contract the
  client depends on (payload shape, 503 with plain text, 401 when signed out, single-use
  tokens) and the `?v=20261109-parity` asset versions.
