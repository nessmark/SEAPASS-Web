# SeaPass Assistant Parity (2026-11-09)

Branch in both repositories: `feat/assistant-parity-2026-11-09` (merged from `assist/web-live`, `assist/web-guide`, `assist/web-tools`, and `assist/mobile`).
Assistant asset version: `v=20261109-parity`.

## Stream Summary: What Users Can Now Do

### 1. Live Voice Reliability (`assist/web-live`)
- **Admin, Port Staff, Passenger:** Live voice sessions now recover automatically when a WebSocket drops mid-conversation, resuming with the existing session handle (or recent chat turns) and sending buffered microphone audio from the gap.
- Server `goAway` notices open a replacement socket before the old socket closes, and drop stale replacements if the user spoke during handover.
- Repeated connection drops (3 in 60 seconds), provider outages (503), rate limits (429), or quota closes switch cleanly to **Text mode** while a background probe checks every minute and restores voice only between turns.
- Going offline pauses reconnects, queues up to 5 typed messages in an outbox, and sends them in order when connectivity returns. Hung turns time out after 30 seconds (paused while a tool runs).

### 2. Console Guided Tours and Show Me (`assist/web-guide`)
- **Admin:** Multi-page guided tours (`getting_started`, `create_trip`, `verify_discount`, `publish_advisory`, plus custom anchor tours and `list_guide_targets`) highlight real console elements (`data-guide`) across full page reloads using `sessionStorage` resume (`seapass.guide.v1`).
- Tours work without an AI connection via **Take the tour** in the sidebar and **Show me around** in the assistant empty state.
- **Port Staff, Passenger:** Current-page walkthroughs (`current_page`) and single-element highlights (`highlight_element`) stay scoped to visible targets on the current screen and never click, type, or submit forms.

### 3. Role-Scoped Operations Tools and Strategic Reports (`assist/web-tools`)
- **Admin:** Can inspect today's departures (`get_port_departures`), full passenger manifests with audit logging and CSV export navigation (`get_trip_manifest`), ticket boarding state (`lookup_ticket`), payment review and refund queues (`get_payment_reviews`), SMS and advisory delivery health (`get_delivery_status`), and staff accounts (`list_staff_accounts`). Can stage activating or deactivating scanner staff (`set_staff_active`), plus request DIELCO-style strategic reports formatted for local PDF export.
- **Port Staff:** Can query departures (`get_port_departures`), seat-level manifest summaries without passenger names (`get_trip_manifest`), and read-only ticket boarding readiness codes (`lookup_ticket`), all strictly limited to trips departing their assigned origin port.
- **Passenger:** Can check their next active upcoming trip with boarding window and route advisories (`get_my_upcoming_trip`), inspect unread in-app notifications and post-registration advisories (`get_notifications`), and confirm marking their own notifications or advisories read (`mark_notifications_read`) so the app badge updates.

### 4. Mobile Passenger Assistant Host (`assist/mobile` + host contract `1a080c0`)
- **Passenger:** Can open the assistant from the top bar on all four main shell tabs, the 3-step booking checkout flow, and the boarding pass screen.
- **Show me** highlights real controls on Trip Schedules, Checkout (progress, passenger cards, discount ID upload, seat map, price breakdown, pay action), Boarding Pass (QR pass, trip summary, save image), My Bookings, Advisories, and Account.
- Assistant navigation and booking prefill (`start_booking` and `navigate_to` with tab aliases and `from`/`to` port prefill) work from pushed screens back to the shell without auto-booking or charging.
- Bounded host states handle offline, server unreachable, signed out, assistant disabled, rate limited (429), slow loads, and blocked microphone permissions with retry or settings actions.

## Review Fixes That Mattered

- **Privacy and Role Scoping (`67e0db7`):** Stripped `Booking` model `$appends` (`setAppends([])`) in `get_bookings` and `operator_needs_attention` so QR tokens and lazy-loaded relations never leak into tool outputs. Restricted staff `search_trips` and `operator_needs_attention` to trips whose origin matches the staff member's assigned port (`departsFrom`), and rejected placeholder port strings (`All Ports`, `Unassigned`). Extracted plain text from HTML email bodies in `get_advisories` and `get_notifications`.
- **Live Reconnect and Tool Races (`8c49d29`):** Tracked in-flight `goAway` token minting (`replacing`) and turn sequence numbers so replacements never open mid-turn or race with new turns. Aborted in-flight `/assistant/tool` requests via `AbortController` when a socket drops or disconnects, cleared partial transcripts on interruption, and blocked input clearing when the 5-message offline outbox is full.
- **Guide Overlay and 360px Mobile Layout (`8af927b`, `06fed88`, `1a080c0`):** Prevented hidden mobile sidebar anchors (`<= 900px` without `sidebar-open`) from drawing off-screen cutouts, restored keyboard focus cleanly on guide exit, ordered `list_guide_targets` with current-page anchors first, wrapped the mobile bottom navigation items in `Flexible` with ellipsis to prevent 360px overflow, resized the assistant bottom sheet with `MediaQuery.viewInsetsOf` so the keyboard and mic banner never cover the WebView, and aligned backend `context.targets.*.description`, passenger tab aliases, and `from`/`to` port prefill with the Flutter host.

## Verification and Exact Test Counts

- **Web PHPUnit (SQLite):** 119 tests, 1582 assertions pass (baseline: 80 tests, 453 assertions).
- **Web Node JS tests (`node --test tests/js/*.test.mjs`):** 56 / 56 tests pass, and `node --check` passes on every assistant JS module.
- **Mobile Flutter (`flutter test --concurrency=1`):** 105 tests pass (baseline: 41 tests), `flutter analyze` is clean, and `flutter build web --release` succeeds.
- **Locked assets:** `mobile/web/phone-preview.js`, `mobile/web/index.html`, and all web and Flutter color palette tokens remain unchanged.

## What Is Still Not at DIELCO Parity and Why

- **Staff console multi-page tours:** All web console routes in SeaPass are `role:admin`; port staff only use the scanner interface and have no multi-page web console to tour.
- **Assistant-triggered boarding clearance:** `BookingVerificationController::verifyTicket` requires a signed QR boarding token for scanner staff. Issuing boarding clearance from chat would weaken gate verification, so `lookup_ticket` is strictly read-only and boarding remains on the QR scanner page.
- **Assistant-triggered PayMongo refunds and SMS retries:** `rejectAndRefundBooking` and `SmsController::retry` trigger external money movement or live SMS dispatches. The assistant reads those queues (`get_payment_reviews`, `get_delivery_status`) and directs admins to the dedicated Bookings and SMS pages for execution.
- **Pending staff account approval:** SeaPass admins create scanner staff accounts directly in an active state; there is no self-registration approval queue like DIELCO's user onboarding, so `set_staff_active` toggles active status instead.

## Open Real-World Gates

- **Physical phone microphone and file picking:** Real-device Android WebView microphone capture, OS permission prompts, and native camera/file chooser selection for discount IDs and chat attachments remain unverified on hardware (synthetic browser file-chooser population was blocked in automation).
- **Provider latency:** Live voice setup and Gemini tool-call round-trip times depend on external network routing and Gemini API latency (local preview forces IPv4 with bounded timeouts).
- **MariaDB test run in this round:** `seapass-mysql-test.service` was inactive during this integration pass, so the 119 PHPUnit tests ran against SQLite only this round (previous 80-test baseline passed on both SQLite and MariaDB).
- **PayMongo merchant and SMS SIM gates (from `docs/completion-2026-10-20/CONTINUE.md`):** Production PayMongo merchant credentials, live webhook reconciliation, real charge/refund settlement, and physical TextLink sending phone/SIM delivery remain open external gates.

## 2026-11-10 follow-up: sidebar, thinking display, everything on Live

- **Admin sidebar.** No scrollbar on a 1366x768 laptop. "Take the tour" is now a small compass button beside the
  account name. Sidebar items no longer shrink, which is what clipped the SeaPass logo. Short screens get tighter
  spacing, and below 720px tall the static "San Jose Port" card hides.
- **Thinking display (admin panel and phone app, same panel).** While a reply is being made, one row sits where it will
  land and says what is happening: "Connecting…", "Thinking…", then the real tool running ("Checking the fleet…",
  "Looking up trips…", one label per tool). The reply then carries a small "Checked the fleet" line that opens to the
  list of steps it really took. Send turns into Stop while it works; a failure shows a Retry button. Steps are saved with
  the chat history.
- **Model routing (Jay's decision: everything on Live).** Typed messages go over the Gemini Live websocket, the same as
  voice. Typed questions get text replies (the transcript of the answer); audio plays only after the mic is used.
  Live opens when you start typing or press Voice and is released one minute after the panel closes, because every
  open Live session counts against quota (seen on DIELCO, 2026-09-26). The text endpoint is only the backup: Text mode
  after a quota close or outage, messages with attachments, and when Live is not ready within 6 seconds.
- **Found in the real check:** Google sends the transcribed reply after it marks the turn complete. The panel now waits
  a moment for trailing text, and that late text no longer reopens the turn or trips the "took too long" timer.
- Tests: PHP 119 / 1582 assertions, JS 64 / 64. Real check: two typed admin questions answered over Live
  (session minted, websocket opened, tool called through /assistant/tool, the text endpoint never used).

### Final verification on 5292449 (web) and bf08e35 (mobile), 2026-11-10

- PHP 119 / 1582, JS 64 / 64, Flutter 105 pass, flutter analyze clean.
- Admin smoke, 1280x720 and 360x800 on dashboard, trips, tickets and fleet: launcher shows, panel opens, Esc closes,
  no horizontal scroll, no console errors. Tour from the account button crossed dashboard to trips with the arrow
  key, Esc ended it, and nothing was written during the tour.
- Passenger phone app at 390x844: signed in as the seeded passenger, opened Ask SeaPass, asked "Do I have any upcoming
  trips?". Answered over Live (session, websocket, get_my_upcoming_trip), steps line "Checked your next trip".
- Local phone preview must be built with the local API, or a fresh browser goes to the old ngrok address:
  `flutter build web --release --dart-define=SEAPASS_API_BASE_URL=http://127.0.0.1:8000`
