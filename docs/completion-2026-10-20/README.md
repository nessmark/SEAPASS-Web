# SeaPass completion and UX review, 2026-10-20

Owner request: complete outstanding SeaPass functionality, revise admin and passenger UX from the two supplied visual references while preserving colors, and implement a SeaPass assistant with Scottie/DIELCO capability parity. Jay explicitly authorized multiple agents and Antigravity. No production release or external messaging was requested.

## Source and working state

- Working branch (both repos): feat/seapass-completion-2026-10-20.
- Original heads: web e10505c, mobile 7de6902, ui/apple-refresh.
- Original copied worktrees have widespread mode-only changes. Do not reset, chmod or stage them as implementation.
- Local preview: admin8000; passenger5185, centered phone frame per owner lock.
- Separate user-local SQLite walkthrough database; never production records.
- Local PHP runtime ~/.local/share/seapass-preview/php-preview, service seapass-preview.service.
- Flutter3.47.7 installed from the official archive with its release SHA verified. Final40test Flutter suite and80test PHP suite pass; actual browser acceptance is tracked in CONTINUE.md.

## Requirement inventory and acceptance evidence

Each item requires actual tests and rendered/runtime evidence, not implementation intent. Status remains pending until audited.

1. Payments: server-calculated fares, owned authenticated checkout, provider errors remain errors, explicit separate mock mode, correct confirmation/refund states, signed matched payment events, duplicate-event handling, expired holds release seats, cancel/retry paths. Actual money movement remains unverified without authorized provider account setup.
2. Accounts/access: passenger registration/profile management and token handling; staff/operator/admin gates; scanner cannot access admin-only actions; passenger cannot read or mutate others' bookings.
3. Operations: boats/capacity/schedules/recurrence, walk-in booking, safe QR boarding, confirmed passenger manifest, useful reports/audit and coherent status vocabulary.
4. Messaging: booking confirmations plus delay/cancel/weather advisories via email and configurable TextLink SMS adapter; delivery status/retry/operator control; provider notconfigured is honest. Physical Android/SIM/carrier allowance and real recipient receipt must be verified separately.
5. Admin UX: task-first main lane plus supporting lane, clear attention list, grouped navigation, quiet joined rows, consistent spacing/type across major pages. Existing color values unchanged. Reference image1 is Remote dashboard, principles only.
6. Passenger UX: purposeful content hierarchy, route/date/departure search, booking/seat/checkout/ticket, bookings/advisories/account/login, usable compact navigation/sheets, accessible controls and narrow-width behavior. Reference image2 is FuturePro, not an Apple screenshot; use interaction/layout principles without copying design/assets. Existing color values and phone-preview lock preserved.
7. Assistant: inventory actual Scottie chat/livevoice/attachments/history/pagecontext/highlight/tours/role tools/confirmation/report features; implement SeaPass-specific authenticated tools on web+mobile; verify provider-backed responses, voice paths, attachments and reporting against the inventory. No static chatbot substitution, unauthorized side effects, or use of Scottie tenant secrets.
8. Integration: analyze/tests/build pass with meaningful cases; actual desktop and360/390phone flows reviewed in Brave; refreshed previews retain demo session; scoped services persist; saved local changes/continuation instructions/evidence; no false completion claims.

## Source paper coverage

Newest local paper: SEAPASS NEW.pdf,91pages. PDFpages41-43 specify passenger registration/profile, tripsearch, bookings, QR tickets, confirmations, SMS booking/advisory notifications; operator schedules/capacity, assistedwalk-inbooking, confirmedmanifest, advisorymanagement, SMScontrol, QRboarding. Page10 excludes onlinepayment/AI/GPS/automaticweather. Current Jay instructions explicitly add payment/AI scope; GPS and automaticweather remain outside the requested completion. Paper notedited.

## Delegated ownership

- backend_completion: backend/config/migrations/routes/tests; backend.md.
- web_ux: Blade/adminCSS/JS; web-ux.md.
- assistant: assistantbackend/web+mobileclients/parity; assistant.md.
- Antigravity CLI mobile task: mobile lib except assistant files; mobile/docs/completion-2026-10-20/mobile-ux.md. Original process PID1895637, metadata/log ~/.local/share/seapass-preview/tasks/mobile-ux-job.json.
- Root Finn executor: integration, toolchain, provider setup coordination, acceptance evidence, consolidated handoff.

## External gates and final audit

Pending, not evidence of completion: real PayMongo transaction/refund and merchant/webhook setup; actual TextLink Android/SIM delivery; physical-phone camera/microphone behavior. Resolve whatever is available within existing authorized accounts. Do not invent provider readiness, charge money, send unrelated external messages, or publish the site to make a checklist green.


## Audited local checkpoints

-81410eb: backend reservation/payment/refund/boarding/notification integrity.
-1c06e44: admin UX and assistant surfaces.
-9560b80: authenticated role-scoped assistant tools and failed-checkout hold recovery.
-3b71c8c: mobile API verified receipt/review state, server hold expiry and signed per-passenger QR.
-Root final backend suite:80tests454assertions passed on SQLite and dedicated MariaDB.
-Root final Flutter suite:41 tests passed, analyzer clean. Later ticket-layout guard suite12tests passed.
-Real Gemini tool-backed text and live ephemeral/audio probe passed again; provider resultJSON records the time and status.
-Current phone source includes actual profile/password transport, live fares, strict server payment verification, signed ticket display, default assistant, argument-preserving navigation and real-control guides.
-Source palette files have no content changes. Existing copied file-mode noise is preserved.
-Release web output passed. Final browser and native build results are recorded in CONTINUE.md.
-Final local readiness does not establish real merchant/SIM/physical-device acceptance. External gates above remain open.
