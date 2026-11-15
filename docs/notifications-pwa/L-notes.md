# Builder L — the bell, the ping, the installable app and mobile push

Branch `feature/notifications-pwa-l`, based on `feature/notifications-pwa`. **Nothing is pushed and there is no PR.**
Status: `IMPLEMENTED_NOT_VERIFIED` until CI and a walk on a real phone. Requirement ids: NOTIF-BELL-001, NOTIF-SOUND-001,
NOTIF-PWA-001, NOTIF-PUSH-001, NOTIF-PREFS-001. The matrix and the ledger were not edited.

## 1. The bell

### Root cause of «صرف بدون نتائج» titled «تجاوز حدود الميزانية»
`AlertEvaluator::NOTIFICATION_TYPE` files five findings (`cpa_increase`, `cpl_increase`, `roas_drop`, `no_results`, `not_spending`) and the budget
alerts under ONE notification type, `budget_risk` — the type is also the person's preference switch. The bell named a row by its TYPE
(`TYPE_LABELS.budget_risk`), so every one of them read «تجاوز حدود الميزانية».

### What changed
- `app_notifications.kind` — the finding itself, derived once in `BellKind` from the alert's own type and figures. The type is untouched
  (it is still the preference category). The resource sends `kind`, `kind_label` (Arabic or English), `group`, `repeat_count`, `open_url`.
- Names: `no_results` «صرف بدون نتائج» · `cost_up` «ارتفاع تكلفة النتيجة» · `roas_drop` «انخفاض العائد» · `not_spending` «حملة ما تصرف» ·
  `budget_75/90/100` «الميزانية 75% / 90% / 100%» · `sync_failed` «فشل المزامنة» · `report_ready` «تقرير جاهز».
  **`budget_over` «تجاوز حدود الميزانية» only when the measured spend is past the limit** (`consumed > limit`, or `ratio > 1`). A budget row with
  no figure behind it is «تنبيه الميزانية» (never «تجاوز»). The spend-limit crossings of builder C (75/90/100) carry `consumed` and `limit`, which is what decides it.
- One notification per finding per day (kind + object + Riyadh day + recipient). A repeat UPDATES the row (wording, figures,
  `repeat_count`, `last_seen_at`) and brings it back as unread only if it got **worse**. No second mail, no second push. A different day or
  a different kind is a different notification. (`NotificationDispatcher`, `upsert_daily`; the 60-minute window of the old dedup is unchanged for every other producer.)
- Closing an alert closes its notification, and the same finding raised earlier (`AlertEvent::updated` → `NotificationInbox::closeForAlert`). It
  covers the sweep clearing it, a person pressing «حُلّ», and a rule being removed. Two rules raising one finding on one object keep the shared row open while either is open.
- **«Read» is per person.** A team-wide notification is ONE row seen by many people; `markAllRead` used to update only rows addressed to the reader, so
  those rows stayed unread forever (345 → still 345). New table `notification_reads (notification_id, user_id)`; `NotificationInbox` is the one
  place that decides «visible» (the SEC-1 rule, moved unchanged), «unread for this reader» and «clear». `POST /notifications/read-all` clears everything the reader can see in one call.
- The bell number is the real count: `GET /notifications/summary` → `unread`, the unread critical/warning ids (for the ping) and the sound state.
  It is asked every 30 s **only while the tab is visible** and on focus; the 100-row list is fetched only while the panel is open. Above 99 the badge reads «99+».
  A push that arrives while the app is open also refreshes it (the service worker messages the page). No socket, no heavy polling.
- Panel filters الكل / حرج / ميزانية / أداء / مزامنة are server-side (`?group=`); the badge ignores the filter. A button per row opens `open_url`
  (budgets → `/budgets?project=…`, sync → `/integrations?project=…`, alerts, reports).
- `severity` `error` is served as `critical` (the frontend only knew `critical`; `error` rows were drawn as info).
- `php artisan notifications:backfill-kind [--dry-run]` names the old rows (run once by hand after the migration). The bell does not wait for it — a missing kind is derived
  from the alert behind the row at read time — but the server-side filters read the stored column.
- `alert_sweep_baseline.json` was regenerated for this intended change (new columns; the day key; two rules raising one finding now share its notification instead of the second getting null).

### Migrations (additive, reversible)
`2026_11_16_210000` kind / repeat_count / last_seen_at + `notification_reads` · `2026_11_16_220000` `notification_alert_settings` ·
`2026_11_16_230000` `push_subscriptions`, `push_deferred`, `push_ledger`, `notification_alert_settings.push_types`.

## 2. The ping
`frontend/src/lib/alertSound.ts`: a 880 Hz sine, 12 ms attack, exponential fade, 320 ms, peak gain 0.25 × volume. No file, no library.
- Plays only for a NEW critical or warning (a warning that becomes critical counts as new), **once per refresh however many arrived**, never on the first load, never for info,
  never in quiet hours, never when switched off or at volume 0. Whatever the verdict, every listed id becomes «seen», so what arrived in quiet hours does not ring when they end.
- Audio unlocks on the first pointer / key / touch; until then `playPing` returns false and nothing is created, thrown or logged (verified: zero console errors, zero autoplay warnings, in the e2e).
- Settings per person: on/off, volume, «جرّب الصوت» (plays from the click itself), quiet hours. Server: `notification_alert_settings`. Default quiet hours **22:00–08:00 on the person's own clock**
  (`AlertQuietHours`); an explicit choice — a window, or «off» — always wins over the default. Email's quiet hours (`NotificationChoices`) are unchanged and still need an explicit window.

## 3. The installable app
- `manifest.webmanifest`: name «كمبينز هب», rtl, ar, standalone, `start_url /agency/dashboard`, theme `#0d8a6f`, background `#062d29`, icons 48–512 (any), 192/384/512 (maskable). All generated from the product mark by
  `frontend/scripts/build-brand-icons.mjs`. `apple-touch-icon` 180 (dark tile). `/agency/dashboard` is still the landing page; `/agency` redirects to `/agency/home` (E's role home) — both work and are asserted.
- `public/sw.js` + `public/offline.html`. **How «never cache financial or client data» is guaranteed** (stated at the top of `sw.js`, asserted by `src/app/sw.test.ts`, which RUNS the real file against a fake `self`):
  1. only a same-origin GET is ever intercepted; every other method and every cross-origin request (the production API is on `api.campaignshub.io`) never reaches a handler;
  2. `/api/`, `/sanctum/`, `/broadcasting/`, `/storage/`, `/oauth/` and any request with an `Authorization` header return before `respondWith` — no cache read, no cache write; there is no runtime-caching rule for the API anywhere in the file;
  3. only the precached shell list, the empty navigation HTML and static assets (script, style, font, image, manifest) are ever stored, and only a 200, same-origin response that is not `no-store` / `private` and not JSON / HTML / XML / CSV;
  4. sign-out sends `CLEAR_CACHES`. The e2e opens `CacheStorage` after a signed-in session and asserts no key is an API, auth, JSON, CSV, XLSX or PDF URL.
- Navigations: network-first; offline → `/offline.html`; a slow network (4 s) → the cached shell so the app opens at once.
- «ثبّت التطبيق» is in the user menu (`InstallAppItem`): Chrome / Edge / Android get the real prompt (captured before React mounts), iPhone gets «مشاركة ← إضافة إلى الشاشة الرئيسية», and it is hidden when installed or unsupported.
- **Lighthouse:** not available offline here, and Lighthouse 12+ no longer has a PWA category. Instead: `pwaManifest.test.ts` asserts the criteria from the files; `e2e/pwa-install.spec.ts` loads the built app, checks every
  icon is a PNG of its declared size, and asks Chromium itself (`Page.getInstallabilityErrors` over CDP) — it must return no error.

## 4. Mobile push (VAPID)
- Library: **`minishlink/web-push` ^11 (MIT)**. No Firebase, no OneSignal, no paid service.
- Keys only from the environment: `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`. `.env.example` and `deploy/backend.production.env.example` name them **commented out and empty** (an uncommented new key blocks every deploy — `scripts/env-contract.sh`). With no pair the feature is off, the page says so, the bell is untouched.
  `php artisan webpush:vapid` prints a pair to the console and writes nothing.
- Permission is requested ONLY by the «فعّل إشعارات الجوال» button in `/account/notifications`. Never on load, sign-in or a timer (asserted). On iPhone it works only after install to the Home Screen (iOS 16.4+) and the page says so instead of offering a prompt.
- Several devices per person; per-device test and revoke; the device is forgotten at sign-out. Expired subscriptions (404 / 410) are deleted at once.
- **Pushed only for:** a critical finding, budget 90%, budget 100% / over, a sync failure, an approval request (each switchable per person). **Who:** the same people who could already read it in the product —
  `ProjectReach` + `ProjectAbilities` with the person's own membership bound as a request binds it, `money.view` for a budget, the access-centre section not switched off, an internal portal only. Re-checked at send time.
  A notification with no project reaches only its addressee. **No producer raises an «approval request» for internal staff yet**; the sender and the switch are ready for the first one.
- Payload: `title` = client (or project), `body` = the kind label («الميزانية 90%»), `url` = a path in the person's own portal, `tag` so a newer one replaces the older. No amounts, no campaign names. The phone's own sound and vibration (no `silent`, no `vibrate`).
- Quiet hours and an hourly ceiling (6) HOLD a push (`push_deferred`); `notifications:flush-deferred-push` (every 10 min) sends ONE summary per person after the window — «وصلتك N تنبيهات وقت الهدوء» — counting only what is still open.
- The job binds the notification's tenant itself: a queue worker has none and `TenantScope` fails closed (a test runs it with no tenant bound).
- SSRF: the endpoint must be https, port 443, no credentials, no IP literal, on the push-service allow-list (`config/webpush.php`); redirects are never followed; only a 2xx is a success (the library calls 3xx a success).

## 5. Preferences
`/account/notifications` → «الصوت وإشعارات الجوال» below the existing table (which still holds in-app / email per type): sound, volume, «جرّب الصوت», quiet hours, the five push types, devices.

## 6. Tests and what they prove
| Layer | Where | Proves |
|---|---|---|
| Backend | `BellNotificationsTest` (11) | 345 unread → mark all → 0 for that reader and still 300 for a colleague; same finding twice same day = one row; kind names; «تجاوز» only when over; filters; close with alert; summary; a real sweep end to end |
| Backend | `BellSoundSettingsTest` (4) | defaults, per-person save, 22:00–08:00 Riyadh default, explicit choice wins |
| Backend | `WebPushTest` (20) | see below |
| Frontend | `NotificationBell.test`, `alertSound.test`, `useBellSound.test` | 99+, titles, one-call clear, filters, open button; ping once for a batch of 5, not info, not quiet, not disabled, error-free before a gesture, < 400 ms and ≤ 0.25 gain |
| Frontend | `sw.test`, `pwaManifest.test`, `pwaInstall.test`, `InstallAppItem.test`, `MobileAndSoundCard.test` | the worker's guarantees; manifest and document criteria; install states; permission only on the button, iPhone says install |
| E2E | `notifications-bell.spec.ts`, `pwa-install.spec.ts` | the bell and the ping in a real browser on rows the real dispatcher raised; manifest, icons, start URL, caches, offline page, Chromium's own installability audit |

`WebPushTest` runs the REAL library against a Guzzle mock push service and then DECRYPTS (RFC 8291) what arrived with the device's own key pair, so what is proven is
who is sent what, when, and in which words. It covers: subscribe → the fake service receives the encrypted, VAPID-signed message; payload has no amounts; a member without project access / with `section:budgets` off / in the client portal gets none; type switch;
quiet hours defer and ONE summary after; ceiling; 410 / 404 delete the device and 500 keeps it; a redirect is not followed; refused endpoints (http, IP literals, other ports, userinfo, look-alike hosts); no key pair = nothing sent and the bell still works; the VAPID command writes nothing; the examples carry no key.
The first two bell tests were written alongside the fix, not before it, so «fail first» is shown by construction (the old endpoint had no `summary`, no `read-all` for team rows and no `kind`), not by a recorded red run.

### Results (2026-11-15, this machine)
- Backend, targeted + guards (no full suite, no `--parallel`): 375 tests / 9,958 assertions — notification, alert, funding, access, schedule, route, source-guard, matrix-vocabulary and defect-ledger tests — all green (the one pre-existing flake below passed on the last run).
- Pint on every touched file: clean. PHPStan (level of `phpstan.neon`) on `app/Domains/Notifications`, `Alerts/Models`, `AlertEvaluator`, `FundingNotifier`, the e2e command and the provider: no errors.
- Frontend: `tsc -b` clean, `oxlint` reports only two warnings in files this batch does not touch, full Vitest **746 files / 5,160 tests green** (includes the retired-words guard).
- Playwright, built app, own ports (5283 / 8110 / 5383) and own database (`mediabuying_pwal_e2e`), never another checkout's server:
  **chromium 15 / 15 passed; webkit 13 passed + 2 skipped** (the two skips are the chromium-only offline and CDP-installability tests, skipped by design).
  **Firefox: it does not launch on this machine** (`firefox.launch()` times out after 20 s — the same limit builder E recorded); no Firefox result exists and none is claimed.
- `AlertSweepBatchedReadsTest` (the statement budget of a sweep): the bell closing a notification with its alert is one UPDATE per closing alert, so the bound moved 558 → 559 and +34 → +38 for 18 campaigns
  (measured against the merge base); the comment in the test says why. It is an event, not a per-campaign read.
- **Pre-existing, not mine:** `FundingNotificationsTest::test_running_low_is_said_once…` orders two notices by `created_at` to the second, so it flips when both land in the same second (passes alone, three times in a row).

### Real versus simulated
- **Real:** the bell on real rows (backend + browser), the service worker running in Chromium, the manifest and icons, Chromium's installability audit, the offline page, the VAPID message's encryption and signature (decrypted in the test).
- **Simulated:** the push service (a Guzzle mock) and the device (a key pair the test generated). **A push to a physical phone was NOT done**: there is no device here and no production VAPID key (and one must not be created in a chat or a commit).
  The browser-side subscription (`pushManager.subscribe`) cannot be automated without a reachable push service and a key, so the e2e checks the page's behaviour (no automatic prompt, the not-configured state) and the unit tests check the button flow.

## 7. What the Owner must do in production (one time, by hand)
1. On the production server run `php artisan webpush:vapid` (it only prints). Copy the two lines into the **live** backend env file — not into git, not into a GitHub secret, not into a chat. Optionally set `VAPID_SUBJECT=mailto:info@campaignshub.io`.
2. Restart the PHP workers / Horizon so the config is read. Make sure the scheduler runs (`notifications:flush-deferred-push` is every 10 minutes) and a queue worker drains `default`.
3. Run `php artisan migrate` (the deploy does), then once: `php artisan notifications:backfill-kind --dry-run`, then without `--dry-run`.
4. Ensure the nginx / Caddy config serves `/sw.js` and `/manifest.webmanifest` with `Cache-Control: no-cache` (a long-cached worker delays updates) — `sw.js` itself carries a version.
5. A new key pair invalidates every subscription: people press «فعّل إشعارات الجوال» again.

## 8. Manual test steps (a real device — the part that could not be done here)
**Android (Chrome):** open the site → sign in → user menu → «ثبّت التطبيق» → confirm. Open the installed app → حسابي → الإشعارات الشخصية → «الصوت وإشعارات الجوال» → «فعّل إشعارات الجوال» → allow.
Press «جرّب» beside the device: a notification «كمبينز هب — جرّبنا إشعارات الجوال» must arrive with the phone's own sound. Lock the phone, then raise a critical alert (or on the e2e/staging DB: `php artisan e2e:notify <email> --kind=budget_90 --severity=critical`) → «[client] — الميزانية 90%»; tapping it opens `/agency/budgets?project=…`.
**iPhone (iOS 16.4+, Safari):** open the site → Share → «إضافة إلى الشاشة الرئيسية» → open the app FROM the Home Screen → sign in → the same page → «فعّل إشعارات الجوال» → allow → «جرّب». In Safari (not installed) the page must say to install first and show no button.
**Laptop:** repeat in Chrome; both devices must appear in the list; revoke one and confirm only the other still receives the test.
**Quiet hours:** set the window to include now → raise a critical → nothing rings; end the window → within 10 minutes ONE «وصلتك N تنبيهات وقت الهدوء».
**Access:** a member with no access to that project, or with «الميزانيات» switched off, must receive nothing.
**Sign-out:** sign out on the phone → raise a critical → nothing arrives there.

## 9. Deferred, and why
- **Approval-request producer for internal staff** — none exists today (content approvals go to the client); the push type and sender are in place.
- **Per-type «mobile» column in the existing channel matrix** — the matrix drives `MessageCatalogue` and a dozen guard tests; the five push types live in their own switches instead.
- **Browser-level subscribe e2e** — needs a push service and a key (see above).
- **Webkit / Firefox offline-page e2e** — Playwright does not emulate offline faithfully for a worker there; the worker's branches are unit-tested for every browser.
- **Pre-existing `notification_id`-less team rows** keep their legacy `status`; they are read through `notification_reads` from now on.
