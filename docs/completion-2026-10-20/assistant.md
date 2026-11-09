# SeaPass AI Assistant: Parity Matrix, Architecture & Implementation Report

**Document Date:** 2026-10-20
**Branch:** `feat/seapass-completion-2026-10-20`
**Delegated Owner:** Agy (Assistant Completion) via rootFinn for SeaPass
**Backend Parity Status:** Backend implementation and contracts complete; full end-to-end parity pending root client integration and QA.
**Test Verification (php-cli wrapper):**
- Assistant tests: **29 tests, 152 assertions (100% pass)** on SQLite and MariaDB (`vendor/bin/phpunit --filter Assistant`).
- Full project test suite: **78 tests, 433 assertions (100% pass)** on SQLite and MariaDB (`phpunit.xml` and `phpunit.mysql.xml`).

---

## 1. Executive Summary & Root Audit Defect Fixes

Following the root integration audit, all identified material defects in the backend assistant services have been fixed and validated without broad refactoring or touching root-owned JS (`public/js/assistant.js`, `rootguides.js`) or mobile files:

1. **Canonical Schedule & Booking Reschedule Propagation (`update_trip`):**
   - Eliminated divergent manual mutation.
   - When a trip's departure time or route is updated, `update_trip` propagates the new `trip_date`, `departure_time_slot`, and `route` to all active bookings.
   - Regenerates valid scannable boarding pass QR codes (`Booking::generateQrCode()`) with updated schedules.
   - Dispatches canonical reschedule SMS alerts via `SmsService::send` (`schedule-change:...`).
   - When trip status is changed to `Cancelled`, flags paid bookings for payment review (`payment_review_required = true`) and sends cancellation SMS (`trip-cancel:...`).
   - Vessel changes without explicit `available_seats` automatically recalculate seat availability to the new vessel's passenger capacity and re-initialize cabin seat maps (`alignSeatMapAvailability`).
   - Supports individual updates of either `departure_date` or `departure_time_slot`, validating against existing slot policy (`07:30, 10:30, 13:30, 17:00`) and enforcing future departure times.
2. **True Verification for Discount Approvals (`approve_discount`):**
   - Reuses canonical `AdminBookingController::approveBooking` logic.
   - Enforces strict preconditions prior to approval:
     - Status must be `to_be_confirmed`.
     - Positive payment collected (`amount_collected > 0.00`).
     - Payment recorded (`payment_received_at !== null` or `paymongo_payment_id`).
     - No unresolved payment reviews (`payment_review_required === false`).
     - Real discount ID photos present (`discount_id_photos` or `seat_breakdown` photo URLs).
   - Test suite updated with true database fixtures and negative regression cases.
3. **Canonical Travel Advisory Store (`publish_advisory`):**
   - Removed duplicate storage logic and eliminated artificial `!app()->environment('testing')` runtime skips from production code.
   - Reuses canonical `AdvisoryController::store`, ensuring dual-table synchronization (`advisories` and `travel_advisories`) and canonical HTML email rendering.
   - Notification delivery is verified in tests via mocks and fakes (`AdvisoryDeliveryService`, `Mail::fake()`, `Http::fake()`), asserting broadcast invocation without sending external network messages.
4. **Correct Admin Navigation Paths (`navigate_to`):**
   - Updated admin profile destination to the actual user management route: `/admin/user-management/profile`.
   - Expanded admin route dictionary to reflect all real web routes: `dashboard` (`/admin/dashboard`), `trips` (`/admin/trip-schedules`), `bookings` (`/admin/tickets`), `fleet` (`/admin/boats`), `fares` (`/admin/fare-management`), `advisories` (`/admin/travel-advisory`), `reports` (`/admin/reports-audit`), `profile` (`/admin/user-management/profile`), `passengers` (`/admin/user-management/passengers`), `staff` (`/admin/user-management/scanner-staff`), `roles` (`/admin/user-management/roles-permissions`), and `sms` (`/admin/sms`).
5. **Active Future Bookable Policy (`search_trips` and `start_booking`):**
   - Synchronizes live statuses first via `TripStatusSynchronizer::sync()`.
   - Strictly enforces bookable policy: only `status === 'Scheduled'`, future departures (`departure_time > ManilaClock::nowDb()`), `available_seats > 0`, and vessels with `status === 'Active'`.
   - Excludes `Departed`, `Cancelled`, `Arrived`, sold out trips, and vessels in `Maintenance` or `Inactive`.

---

## 2. Scottie (DIELCO) vs. SeaPass Parity Matrix

> **Note on Parity Status:** Backend assistant capabilities, domain tools, validation policies, and client action contracts are implemented and verified. Full end-to-end parity remains pending root client integration (web JS & mobile UX widget).

| Domain Feature Area | Scottie (DIELCO Utility) | SeaPass (Maritime Ferry Service) | Parity Status & Implementation Details |
| :--- | :--- | :--- | :--- |
| **Voice & Multimodal** | Gemini text API + WebSocket Live (16 kHz PCM16 / 24 kHz playback) | Gemini text API + WebSocket Live (`gemini-3.8-live` via ephemeral auth tokens) | **Backend Complete** (`AssistantService::session`, `assistant-provider-result.json`). Client integration active. |
| **Document Attachments** | Up to 4 files, 10 MB total, JPG/PNG/PDF/Text, client downsampling | Up to 2 files, 4 MB total, JPG/PNG/WebP/PDF/Text | **Backend Complete** (`AssistantService::attachment`, binary inspection). |
| **Reporting & Export** | Structured plaintext report + client jsPDF local export | Strategic Report prompt format + local PDF export | **Backend Complete** (`AssistantService::prompt`, long reply detail collapse). |
| **Passenger Booking Flow** | `file_incident_report`, `start_incident_report` (prefill navigate to report form) | `start_booking`, `navigate_to(trips, args)` (prefill date, route, trip_id, seats) | **Backend Complete**. Navigates to native booking UI. **Never charges, chooses fake payments, or auto-books.** |
| **Operational Reading** | `search_incidents`, `get_incident`, `get_my_reports` | `search_trips`, `get_bookings`, `get_booking`, `list_fares`, `get_advisories` | **Backend Complete**. Strict tenant privacy: passengers only see own bookings; phone numbers, emails, QR tokens, and other accounts are hidden. |
| **Operational Attention** | `list_incidents_needing_action` (unverified, unscheduled, overdue) | `operator_needs_attention` (unverified discount IDs, payment reviews, pending bookings, urgent trips) | **Backend Complete**. Role-scoped for staff and admin. |
| **Admin Operations** | `dispatch_lineman`, `save_office`, `save_barangay`, `update_user` | `create_trip`, `update_trip`, `set_boat_status`, `update_fare` | **Backend Complete**. Staged with 48-char one-time token. Enforces active boat status, future departure, capacity, and seat maps. |
| **Verification & Approval** | `verify_incident` (approve/reject outage report) | `approve_discount` (verify student/senior/PWD photo ID on paid bookings) | **Backend Complete**. Delegates to `AdminBookingController::approveBooking`, generates QR code, audits action. |
| **Cancellations & Safety** | Lineman job status update, incident archiving | `cancel_booking` (pending unpaid bookings only) | **Backend Complete**. Releases seats back to vessel. **Paid bookings rejected** with truthful instructions to use PayMongo refund workflow. |
| **Advisories & Broadcasts** | Emergency outage announcements | `publish_advisory` (draft or publish travel advisory; optional broadcast) | **Backend Complete**. Reuses `AdvisoryController::store`. Syncs both tables, canonical delivery service. |

---

## 3. Tool Inventory & Role Coverage Matrix

### Legend
- **Roles:** `passenger` (authenticated passenger), `staff` (port operator/scanner), `admin` (port administrator)
- **Execution Mode:**
  - `immediate`: Returns data or client navigation action immediately.
  - `staged`: Returns a confirmation card with a one-time token; database mutation executed only after explicit user confirmation.

| Tool Name | Roles Permitted | Mode | Parameters | Description & Domain Safety Controls |
| :--- | :--- | :--- | :--- | :--- |
| `search_trips` | `passenger`, `staff`, `admin` | `immediate` | `date`, `route`, `from`, `to` | Lists upcoming active bookable trips (`status: Scheduled`, future departure, available seats > 0, active boat). Port staff queries are automatically scoped to their `assigned_port`. |
| `list_fares` | `passenger`, `staff`, `admin` | `immediate` | *(none)* | Returns official route fares in PHP (Regular, Student, Senior). |
| `get_advisories` | `passenger`, `staff`, `admin` | `immediate` | *(none)* | Returns active published travel advisories. |
| `get_page_context` | `passenger`, `staff`, `admin` | `immediate` | *(none)* | Returns client screen snapshot (treated as untrusted reference data). |
| `navigate_to` | `passenger`, `staff`, `admin` | `immediate` | `page` (required), `args` (object) | Prepares client navigation. Admin maps to actual routes (`/admin/user-management/profile`, `/admin/sms`, etc.). Supports passenger prefill (`trips?date=...&from=...`). |
| `highlight_element` | `passenger`, `staff`, `admin` | `immediate` | `target`, `message` | Admin: points at one console part by guide anchor id from `resources/assistant/guides.json`; unknown ids are refused with close matches. Passenger and staff (no console anchors): the target must exist in `context.targets`. Read only. |
| `start_tour` | `passenger`, `staff`, `admin` | `immediate` | `preset`, or `title` + `steps[{target,title,body}]` (admin) | Admin: `current_page`, a preset (`getting_started`, `create_trip`, `verify_discount`, `publish_advisory`) or a custom tour of 1 to 14 anchor steps with unknown targets dropped. Multi-page tours move only on a user click. Passenger and staff: `current_page` only. Never saves or submits. |
| `list_guide_targets` | `admin` | `immediate` | `page` (path or page name) | This account's guide anchors (id, label, description, page), current page first, plus the preset list. |
| `update_profile` | `passenger`, `staff`, `admin` | `staged` | `name` (min 2, max 100) | Proposes updating user account display name. Identity-bound confirmation required. |
| `start_booking` | `passenger` | `immediate` | `trip_id`, `date`, `route`, `seats` | Prepares passenger booking flow, validating trip is scheduled, future, and vessel is active. Returns `client_action` with `navigate` to `trips`. **Never charges or selects payment.** |
| `get_bookings` | `passenger`, `admin` | `immediate` | `status`, `date`, `needs_attention`, `search`, `limit` | For passenger: strictly scoped to authenticated `passenger_id`; hides contact numbers, QR codes, and other users. For admin: filters by status, trip date, attention flag, or search term. |
| `get_booking` | `passenger`, `admin` | `immediate` | `reference` (string) | Looks up a specific booking by reference number or numeric ID. Scoped to owner for passengers. |
| `cancel_booking` | `passenger`, `admin` | `staged` | `reference`, `reason` | Proposes cancelling a `pending` (unpaid) booking and releases reserved seats back to the vessel. **Paid bookings with collections are rejected** and directed to the official PayMongo refund workflow. |
| `operator_needs_attention` | `staff`, `admin` | `immediate` | `limit` (int) | For admin: aggregates unverified discount IDs (`to_be_confirmed`), payment review flags, pending bookings, and urgent trips (departing <24h with issues). For staff: summarizes trips today at their assigned port. |
| `get_fleet` | `admin` | `immediate` | *(none)* | Returns all registered vessels, passenger/vehicle capacities, and operational statuses (`Active`, `Maintenance`, `Inactive`). |
| `get_audit_logs` | `admin` | `immediate` | `limit` (int) | Returns recent administrative and assistant actions (sanitized of IP addresses). |
| `get_analytics` | `admin` | `immediate` | *(none)* | Database snapshot of total bookings, status breakdowns, confirmed collection totals (PHP), and recorded refunds. |
| `set_boat_status` | `admin` | `staged` | `boat_id`, `status` | Proposes updating boat status. **Strictly blocks deactivation if active passenger bookings exist.** |
| `update_fare` | `admin` | `staged` | `fare_id`, `regular`, `student`, `senior` | Proposes updating route fare rates in PHP. Requires admin confirmation. |
| `create_trip` | `admin` | `staged` | `boat_id`, `route`, `departure_date`, `departure_time_slot`, `available_seats`, `notes` | Proposes creating a new scheduled trip. Validates boat is `Active`, departure is in the future, and seats <= boat capacity. Automatically initializes standardized cabin seat map. |
| `update_trip` | `admin` | `staged` | `trip_id`, `boat_id`, `route`, `departure_date`, `departure_time_slot`, `available_seats`, `status`, `notes` | Proposes modifying a trip. **Propagates schedule changes to active bookings, regenerates QR codes, and sends reschedule SMS.** Handles date or slot individually with policy validation (`07:30, 10:30, 13:30, 17:00`). |
| `publish_advisory` | `admin` | `staged` | `title`, `message`, `severity`, `route`, `effective_from`, `until`, `status`, `broadcast` | Proposes drafting or publishing a travel advisory. Reuses canonical `AdvisoryController::store`, syncing both tables. Broadcasts via canonical delivery service. |
| `approve_discount` | `admin` | `staged` | `reference` (string) | Proposes approving a Student/Senior/PWD discounted booking with verified ID photo. Requires booking to be in `to_be_confirmed` status with recorded positive payment and no payment review flags. Reuses `AdminBookingController::approveBooking`. |

---

## 4. Client Action Shapes & Integration Contracts

The assistant communicates frontend effects to client shells (desktop web and mobile embedded WebView) using two canonical response structures: `confirmation` and `client_action`.

### A. Staged Confirmation Action
Returned when a tool requires explicit human approval before execution:
```json
{
  "confirmation": {
    "token": "48_character_cryptographic_random_string",
    "tool": "approve_discount",
    "args": {
      "booking_id": 14,
      "reference_number": "REF-SENIOR-123",
      "passenger_name": "Senior Citizen"
    },
    "expires_in": 300
  },
  "status": "awaiting_user_confirmation"
}
```

### B. Navigation & Passenger Prefill Action
Returned by `navigate_to` and `start_booking`:
```json
{
  "client_action": {
    "type": "navigate",
    "page": "trips",
    "path": "trips?trip_id=5&date=2026-10-25&seats=2",
    "args": {
      "trip_id": 5,
      "date": "2026-10-25",
      "seats": 2
    }
  },
  "status": "ready_for_client",
  "result": {
    "message": "Navigating to trips. Select your seats and confirm payment in the SeaPass app.",
    "prefill": {
      "trip_id": 5,
      "date": "2026-10-25",
      "seats": 2
    }
  }
}
```

---

## 5. Verification Evidence & Automated Tests

All automated tests run cleanly under PHPUnit 11 with PHP 8.4 using the generic PHP CLI helper preserving environment configurations:
```bash
/home/kiuren/.local/share/seapass-preview/php-cli vendor/bin/phpunit --filter Assistant
```

### Test Suite Execution Output (SQLite)
```text
PHPUnit 11.5.53 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.24
Configuration: /home/kiuren/Projects/NESMARK/webapp/phpunit.xml

.............................                                     29 / 29 (100%)

Time: 00:00.777, Memory: 54.50 MB

OK (29 tests, 152 assertions)
```

### Test Suite Execution Output (MariaDB)
```bash
/home/kiuren/.local/share/seapass-preview/php-cli vendor/bin/phpunit -c phpunit.mysql.xml --filter Assistant
```
```text
PHPUnit 11.5.53 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.24
Configuration: /home/kiuren/Projects/NESMARK/webapp/phpunit.mysql.xml

.............................                                     29 / 29 (100%)

Time: 00:01.964, Memory: 54.50 MB

OK (29 tests, 152 assertions)
```

### Full Application Test Suites
- **SQLite Full Suite:** `78 / 78 (100%)`, 433 assertions (Time: 00:02.942).
- **MariaDB Full Suite:** `78 / 78 (100%)`, 433 assertions (Time: 00:04.511).

---

## 6. External Mic, File Picker, & Platform Limitations

1. **File picker verification:** The official browser runtime rejected this session’s synthetic file-chooser population with “Not allowed.” That browser action was not retried through another mechanism. Backend attachment validation is covered separately; actual browser/native file selection remains unverified.
2. **Microphone Permissions & AudioWorklet Requirements:** Web Audio capture downsamples mic audio to 16 kHz PCM16 mono. When embedded in an `<iframe>`, the hosting iframe **must explicitly specify `allow="microphone"`**.
3. **Document attachments:** Attachments are submitted through authenticated text chat as validated inline data. Live voice and text attachment handling are separate paths; actual file selection on a physical phone remains unverified.
4. **Console tours:** The admin console has multi-page guided tours (section 7). The passenger app and the staff scanner keep the current-page guide; staff have no web console pages (every console route is `role:admin`), so there is no staff console tour.

## 7. Console guide and tours (2026-11-09 parity)

Ported from the DIELCO guide (`guide.js`, `guideAnchors.js`, `guideTours.js`, `GuideOverlay.vue`).

- **Registry:** `resources/assistant/guides.json` holds console pages, anchors (id, label, description, page, optional fallback) and preset tours per role. Every anchor id is a `data-guide` attribute in the Blade templates, including repeated rows (manifest, seats, approve). `App\Services\Assistant\GuideRegistry` reads it for tool validation and the system prompt; the admin layout injects only the signed-in role's slice as `#sp-guide-registry`.
- **Presets (admin):** `getting_started` (13 steps across the dashboard, trips, tickets, advisories, fares and reports), `create_trip`, `verify_discount`, `publish_advisory`. The model can also build a short custom tour from anchor ids; unknown ids are dropped, a tour with no valid step is refused.
- **Engine:** `public/js/assistant/guide-engine.js` is DOM free (validation, step state, bounded lookup with fallbacks, about 4 s timeout, placement, sessionStorage resume). `guide-overlay.js` draws it: native modal dialog, dimmed cut-out, ring, arrow and a card with Back, Next or Done, and Skip tour. Right or Enter moves on, Left goes back, Esc exits, Tab stays in the card. The card flips to the side with room, stays inside a 360 px viewport and follows scroll and resize. Reduced motion turns off the pulse and smooth scrolling.
- **Pages:** Laravel pages are full reloads. When Next or Back lands on another page, the tour saves its steps and index in sessionStorage (`seapass.guide.v1`, bound to the account identity and role, 30 minute limit, read once) and resumes on the next page. A tour the model starts never changes page by itself; a step on another page shows an Open page button.
- **Safety:** Tours never click, type or submit. The modal layer blocks page input. A missing part shows a centred card with Next and Skip instead of hanging.
- **No AI needed:** Take the tour in the sidebar account area and Show me around in the assistant empty state start `getting_started` without the AI connection.
- **Tests:** `tests/Feature/AssistantGuideTest.php` (tool validation, role scoping, prompt, markup agreement) and `node --test tests/js/*.test.mjs` (engine validation, transitions, cancel, timeout, resume, placement).

## Root integration verification

2026-10-20: root reran the complete suite after checkout hold recovery was added: 79tests444assertions passed on both SQLite and dedicated MariaDB. Earlier counts above describe the assistant worker checkpoint. Passenger client integration is being verified separately.
