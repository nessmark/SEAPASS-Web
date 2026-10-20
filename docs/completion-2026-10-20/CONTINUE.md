# SeaPass local acceptance and continuation

Owner: Jay. Executor: Finn, Codex session 01a155e9-a0ee-7362-8e0a-732dad896920.
Source branch in both repositories: feat/seapass-completion-2026-10-20.

## Delivered source

Web commits: 81410eb reservation/payment/notification integrity; 1c06e44 admin workspace and assistant surfaces; 9560b80 assistant domain actions and failed-checkout hold recovery; 3b71c8c verified receipt, review flag and signed ticket API.

Mobile commits: 3e718e7 removes fabricated seat maps and blocks payment without live seat data; a43cb7f passenger phone UX, actual profile/password transport, verified checkout, reservation retry, signed QR and assistant integration. Original palette values remain unchanged. Desktop phone framing is an owner-locked permanent default.

No active source workers remain. Do not restart old Antigravity PIDs from historical logs. Existing copied mode-only changes are not implementation changes; use git -c core.filemode=false when reviewing either repository. No source was pushed or deployed.

## Verification

- Backend: 80 tests, 454 assertions pass on SQLite and dedicated disposable MariaDB.
- Concurrency: two available seats with ten contenders yields two holds; eight duplicate capture requests yield one financial audit; eight attempts against two signed boarding seats yield two boardings and six rejections.
- Flutter: final full suite 41 tests pass; analyzer reports no issues.
- Admin: 44 unique page/theme/viewport combinations reviewed, eleven pages at 1280 and 360 widths in both themes. Details and exceptions are in web-ux.md and evidence/admin-responsive-checks.json.
- Provider: actual Gemini fare tool response and live ephemeral setup/audio/clean close verified; timestamped result in evidence/assistant-provider-result.json.
- Profile: authenticated update/read-back/restore verified; invalid phone and incorrect current password rejected. Evidence/profile-api-result.json.
- PDF: actual two-page report export rendered and reviewed; evidence/assistant-export-qa.pdf.

- Phone browser: normal seeded session persisted, centered390x844 desktop frame, 360x800 full-width layout with scroll width360. Assistant initialized, accepted keyboard input, submitted provider-backed chat and navigated to October21 trips. Two passengers reached the actual booking details form. All three current-screen guide steps reached real controls. Passenger form entry and live seat map were reviewed.
- Confirmed mock booking appeared in My Bookings and opened two individual boarding passes with QR codes, seats1A/1B. See evidence/mobile-ui-result.json and screenshots/mobile-{trips,bookings}-360.jpg.
- Complete browser checkout remains unverified: the task tab closed twice before the final payment interaction. Browser recovery checks passed; reopening official Brave restored the bridge. No alternate browser or blocked file chooser workaround was used.
- Actual local HTTP checkout passed: authenticated two-seat hold, server fare200 despite client amount1, no receipt/tickets while pending, explicit mock capture, verified receipt and two signed tickets. See evidence/checkout-runtime-result.json. This is local test evidence, not real merchant settlement.
- Provider default routing timed out. The preview now opts into IPv4, with bounded connection setup. A subsequent real fare-tool and live audio probe passed at13:42UTC; latency remains provider/network dependent.
- Release web build passed after final seat-map changes; analyzer clean; final Flutter41tests passed. Backend suites were rerun after the provider connection change:80tests454assertions pass on both databases.

Android debug APK compilation passed in301seconds after SDK repair. APK Signature Scheme v2 verification passed. Artifact: mobile/build/app/outputs/flutter-apk/app-debug.apk, approximately191MiB. Minimum Android API24, target/compile36, arm64-v8a/armeabi-v7a/x86_64. SHA256 and exact size are in evidence/android-build-result.json. No device installation or physical acceptance was performed. This local debug build points at127.0.0.1:8000; a phone requires adb reverse tcp:8000 tcp:8000 or a reachable API selected in connection settings.

## Local runtime

- Passenger: http://127.0.0.1:5185, systemd user service seapass-mobile-preview.service.
- Admin/API: http://127.0.0.1:8000, service seapass-preview.service, four PHP workers.
- Normal seeded passenger session: Marilou Bautista. No authentication bypass was added.
- Preview data is isolated SQLite. PayMongo mock mode is explicit and local. No real money movement or SMS sends occurred.
- Runtime/toolchain/logs: /home/kiuren/.local/share/seapass-preview. php-cli preserves test environment; php-preview forces walkthrough data and must never run tests.
- Flutter: /home/kiuren/.local/share/seapass-preview/flutter/bin/flutter.
- Android SDK: /home/kiuren/.local/share/android-sdk, platform/build-tools36 and NDK28.2.13676358.
- JDK21: /home/kiuren/.local/share/sugihandi-jdk/usr/lib/jvm/java-21-openjdk-amd64. Extracted JDK requires its explicit PKCS12 CA truststore for Gradle downloads.
- Schedule timer: seapass-preview-schedule.timer. MariaDB verification service: seapass-mysql-test.service, local socket only, disposable seapass_verification database.

## Remaining real-world gates

Authorized PayMongo merchant and webhook setup, real charge/refund reconciliation, TextLink sending phone/SIM and recipient receipt, and physical phone camera/microphone/file selection/save acceptance remain open. Browser synthetic file chooser population was blocked; do not bypass that restriction through another browser mechanism.

Do not call the entire project production-ready while these gates remain. Local mock checkout only establishes the app's local behavior. No production push, deployment, spending or external notification is authorized by this acceptance run.

## Saved continuation safeguards

- Web source additions: 2ce4c02 cache refresh and connected state; b9671be configurable provider IPv4 route. Mobile latest integrity checkpoint3e718e7; acceptance/ignore checkpoint1637290.
- SDK extraction repair restored35 symlinks from the checksum-verified official NDK archive. Clang19 now runs. Flutter's global SDK setting and ignored android/local.properties point to the actual ~/.local/share/android-sdk, not the stale ~/.local/lib path.
- Run PHP tests through php-cli vendor/bin/phpunit, and add --configuration phpunit.mysql.xml for MariaDB. The artisan test subprocess omits the extracted runtime configuration unless PHPRC is explicitly provided.
- Browser synthetic fill/type did not reliably update nested frame inputs. Real keyboard pressSequentially worked after the Flutter focus/semantics update settled. This did not justify bypassing authentication or changing form data through JavaScript.

## Final saved state

Source and acceptance are committed locally. Web acceptance checkpoint02e7d81 contains the evidence and screenshots; later documentation records the passing APK. Original palette files and owner phone-frame lock are preserved. Real merchant/SIM and physical-device gates remain open, and full browser checkout is still marked unverified. The local HTTP mock flow, actual phone ticket rendering and native compilation are separately evidenced.
