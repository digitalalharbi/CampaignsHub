# Builder R — settings page, admin page, usage, first run (NOTIF-V2-SETTINGS-UI-001)

Branch `feature/notifications-v2-r` (on `feature/notifications-v2` + L + M). Not pushed, no PR. Matrix and ledger not edited.
Status: `IMPLEMENTED_NOT_VERIFIED` — targeted tests and local Playwright below; no CI, no production, no real send.

## What exists

| Piece | Where |
|---|---|
| ONE page, any workspace type | `frontend/src/features/notifications/workspace/` — `WorkspaceNotificationsPage` mounted by `SettingsPage` for `?tab=notifications`; `&section=internal\|external\|usage`; sub-tab `&tab2=` |
| Per-type routes | `/app/settings/workspace` and `/agency/settings/workspace` already mount the same `SettingsPage`; no copy. Client-portal route: **deferred** (below) |
| Terms and roles | server data: `GET settings/workspace/notifications/settings` returns `workspace.type.{role_labels,term_labels}`; all strings in `strings.ts` (AR+EN, `{client}/{project}/{projects}` filled from that data) |
| Internal section | routing matrix (event → priority → roles → channels → on), channels/quiet hours/work days/holidays/timezone/default sound/grouping, follow-up settings + link to alert rules, «الفريق» tab keeping the old panels (team arrangement, team board, send log, templates) |
| External section | recipients and subscriptions (pause/resume/edit/duplicate/delete-with-confirm, approve internally, «أرسل طلبات التأكيد» with count preview), events, general settings, immutable log + CSV + subscription log |
| Wizard | 4 steps, template cards with thumbnails, sentence builder, illogical period blocked with «استخدم البديل», advanced collapsed (custom subject, CC/BCC, «أضف نسختي»), projects + phone-frame preview (light/dark, AR/EN, inbox line), «اعرض الرسالة», «أرسل نسخة لي», «أرسل للمستلم الآن» (confirm) |
| First run | `FirstRunWizard`: responsible recipient (the type's responsible role) + org domains; skip leaves defaults |
| Locks | `LockedNote`: lock + «ترقية» from the server entitlement state; settings view-only; grace shows a heads-up |
| Usage | indicator in the header (recipients, monthly messages vs limit) and `section=usage` (counters + every plan key's state) |
| Admin | `/admin/notifications`: event catalog, texts (phrasing dictionary, default disclaimer, templates per type), plan features (keys, limits, grace, keep existing), footprint (removal key + invitation line), statistics |
| Backend (mine) | `GET|PUT settings/workspace/notifications/settings`, `GET .../kpis`; `GET /admin/notifications-v2`, `.../stats`, `PUT .../events/{key}`, `PUT|DELETE .../plan-features`, `PUT .../texts/{key}`; migration `notification_platform_texts` (additive, `down()`), `config/notifications_v2_texts.php` |
| Old external panel | `ExternalRecipientsPanel` is now one line pointing to `section=external` |
| Fit primitives | builder D's branch (`feature/nov-overhaul-d`) merged into this branch (the primitives need its PortalFrame/CSS); `SettingsLayout` gained `min-h-0 flex-1` so a fitted page can fill it |

## Decisions

| Point | Decision |
|---|---|
| Where D's primitives come from | The base branch has none. Cherry-picking one commit conflicted in `PortalFrame`/`index.css` (D's rail work precedes it), so D's whole branch was merged. It is D's own history: merging D later adds nothing |
| Internal / external KPIs | Internal: mine. External: Q's `external/kpis` wins (its «held for review» counts the review queue), mine is the fallback; once Q is merged mine can be dropped. Open follow-up tasks shows «-» until builder P's table exists (unknown is not zero) |
| Where settings live | Workspace-level (domains, timezone, routing, quiet hours, default CC/BCC, preferred phrases, role names, follow-up, priorities, sound, grouping, first-run flag): `workspace_notification_settings` through my endpoint, only keys the page owns are stored. External pipeline settings (reply-to, signature, disclaimer, forbidden words, contact link, subject templates, copy delivery, display currency): builder Q's endpoint — it is what the sender reads |
| Plan locks per part | A view-only/off key refuses only its own part (403 + `meta.upgrade`) and the rest of the request is applied; CC/BCC and subject templates have their own keys |
| «طلب تواصل» | a URL field (empty = off), matching Q's `contact_link` |
| Delete a subscription | a confirmed cancel (the row is kept; the immutable log points at it) |
| «Priorities» | stored per event in `settings.priorities`; the catalog priority stays the default (the engine reading the override is deferred) |
| Owner-only confirmation requests | the button shows for anyone with `settings.manage`; the server answers 403 to a non-owner (shown as the error) |
| Archived projects | the server excludes them (Q) and the wizard prints the remark |

## New settings (level, default)

| Setting | Level | Default |
|---|---|---|
| org domains, timezone, work days, quiet hours, holidays, routing | workspace | M's defaults (none / Asia/Riyadh / profile / 21:00-08:00 / none / catalog) |
| `settings.priorities`, `default_sound`, `grouping_window_minutes`, `followup.{morning_brief,morning_time,tasks}`, `first_run_completed` | workspace | catalog priority / on / 10 / on, 08:00, on / false |
| `settings.cc_bcc.{default_cc,default_bcc}`, `phrasing.preferred`, `role_labels` | workspace | empty |
| `notification_platform_texts`: `phrasing.dictionary`, `disclaimer.default`, `footprint.invitation`, `templates.<type>` | platform | `config/notifications_v2_texts.php` |

## Contract Q should know about

`notification_platform_texts(key, value jsonb)` holds the platform text layer; Q's sender should read `phrasing.dictionary` (`forbidden` extends its own list), `disclaimer.default` (`{ar,en}`, `{workspace}` placeholder) and `footprint.invitation` (`{ar,en}`) from it, and `footprint_clicks(clicked_at)` is read by the admin statistics when that table exists (Q's migration adds one). Preview and test-send are called at `POST .../external/preview` and `.../external/test-send` — **not present in Q's branch when this was written**; the wizard shows the server's error until they exist.

## Tests

Backend (`ptest.sh`, targeted): `WorkspaceSettingsApiTest` 7, `AdminNotificationsApiTest` 7, plus `NoEmoji…`, `EngineSchema…`, route/migration/isolation guards (250 passed). Frontend: `strings.test` (AR/EN keys equal, no emoji, placeholders equal), `digestPeriods.test`, `WorkspaceNotificationsPage.test` (19: deep links, KPIs, three workspace types x terms/roles, lock + upgrade, grace, wizard period block, approve/delete/confirm preview, log export), the rewritten `ExternalRecipientsPanel.test`. `tsc -b` clean.
Playwright (`e2e/notifications-settings.spec.ts`, own ports 8300/5673/5773, DB `mediabuying_nv2r_e2e`): 33 tests x chromium + webkit = 66 passed (deep links, toggle, migrated recipients, edit survives reload, old-panel pointer, three types x first run and wizard roles/terms, lock + «ترقية», no vertical scroll at 1366x768 and no sideways scroll at 390 on all 8 views, cards on a phone). Firefox not run: it cannot launch on this machine (D's note).
A real defect found by the 390 run and fixed: the shared `Modal` bottom sheet sat at the same z-index as the phone tab bar, which covered its buttons; it is now `z-[55]` (below the navigation drawer's 60). Recipients/subscriptions/Q settings are answered by fixtures; nothing is sent.

## Deferred

- Client-portal route: `/portal/:clientSlug` captures `/portal/settings`, and the settings API group excludes the `portal` portal on purpose; opening it would expose the whole group. The component itself holds nothing portal-specific.
- Engine reading `settings.priorities`, `default_sound`, `followup.*` (stored and editable; consumers are builder P/M follow-ups).
- Screenshots of the three types and the admin page for the final owner report.
- A pre-existing failure in `src/lib/countedNouns.test.ts` (`AgencyHeadline.tsx`) arrived with builder D's branch, not from this work.
