# Builder M — contracts + shared engine (NOTIF-V2-ENGINE-001)

Branch `feature/notifications-v2-m` (based on `feature/notifications-v2`, builder L merged in at `fd98e6253`). Not
pushed, no PR. Matrix and ledger not edited. Scope: Owner spec §0, §1, §3, §4. Contracts: `CONTRACTS.md` (this folder).
Status: `IMPLEMENTED_NOT_VERIFIED` — targeted tests green; full suite, CI and production not run here.

## Decisions (ambiguous points)

| Point | Decision | Why |
|---|---|---|
| «RLS on every new table» | Repository convention: `tenant_id` + `BelongsToTenant` / explicit tenant in console queries + isolation tests. No Postgres policies | The schema has none anywhere; one policy table would be a second isolation mechanism nobody else checks |
| Immutable log | Append-only `notification_delivery_log`; a BEFORE UPDATE/DELETE trigger raises; no FKs (no cascade); names snapshotted; one terminal row per delivery, optionally preceded by `deferred` | Proof of notice must survive renames, project deletion and tenant deletion |
| Mutable work state | Separate `notification_outbox` | The log cannot carry attempts / back-off if it is immutable |
| De-dup | `event|object|threshold|day(workspace tz)|recipient|channel`, unique in the outbox; also checked inside a pending group | Spec §3 |
| Grouping | Trailing window (default 10 min, workspace `settings.grouping_window_minutes`) for info/important; critical never waits | «3 similar events → one grouped» needs the first to wait |
| In-app and quiet hours | The in-app centre is never deferred; push and email are | The bell is silent (L's ping obeys quiet hours itself); hiding rows till morning hides information |
| Personal quiet hours | Push: L's `AlertQuietHours` (22:00–08:00 default); email: explicit window in `notification_preferences` only | Keeps both existing behaviours |
| Push personal switch | Router reads the in-app per-type switch; L's five push types stay in `PushTypes` | No second preference store |
| Event without project | Goes to the profile's responsible role only | Plan / workspace events are owner matters |
| Roles of internal members | Tenant role slug → profile role via `member_roles` (data); unmapped → `other` | No code branch on a type or role |
| External email words | The engine never improvises an external message: without an external-ready composer (the §11 design) an external delivery is logged `skipped (external_template_not_installed)` | §8/§11–§13 identity, footer, disclaimer are other builders' and mandatory |
| Entitlement paid later | Row off + `paid_from`: workspaces created before → grandfathered (`keep_existing`) or grace `grace_days` then view-only; created after → off. Explicit `workspace_entitlement_states` wins | «Never break», no code change to price |
| View-only over the API | `plan.feature:<key>`: GET passes unless `off`; writes need a usable key; 403 `upgrade: true` | Settings stay visible behind «ترقية» |
| Legacy migration | Recipients → `pending`, subscriptions → `pending` (inactive legacy → `paused`); legacy rows untouched; old sender skips migrated recipients | Spec §9 migration rule |
| «أرسل طلبات التأكيد» | Owner only; must repeat the previewed count (409 otherwise); no provider → nothing recorded | A list that grew since the preview is not mailed by surprise |
| Confirmation link | `GET /n/confirm/{token}` shows a page, `POST` confirms; token hashed, single use | Link scanners fetch every URL |
| Emoji definition | Pictographs, VS16, keycaps, U+2600–27BF, emoji arrows/stars/clocks. The mail design's check/cross glyphs (U+2713–2718) are allowed | They are typographic glyphs already in the design system |

## Settings (level + default)

| Setting | Level | Default |
|---|---|---|
| Event catalog (scope, priority, roles per type, channels, entitlement, creates_task, internal_only) | Platform (`notification_event_catalog` over config) | `config/notification_events.php` (39 events) |
| Workspace-type profiles (roles, terms, responsible role, client role, work days) | Platform (config) | agency / business / client_portal |
| Plan features + limits + grace + keep_existing | Platform (`notification_plan_features`) | all on except footprint removal; grace 14 days; keep existing on |
| Per-workspace limit override | Platform per workspace (`tenant_limit_overrides`, `notif.<limit>`) | none |
| Timezone | Workspace | Asia/Riyadh |
| Work days | Workspace | profile (Sun–Thu) |
| Quiet hours | Workspace | on, 21:00–08:00 |
| Holidays | Workspace | none |
| Org domains | Workspace | none |
| Routing (event → roles, channels, enabled) | Workspace | catalog default per type |
| Grouping window | Workspace (`settings`) | 10 min |
| Sender Reply-To | Workspace (`settings.sender.reply_to`) | platform reply-to |
| Personal channels per type, quiet hours | User (existing `notification_preferences`) | catalogue defaults |
| Sound, volume, push types, devices | User (L's `notification_alert_settings`, `push_subscriptions`) | sound on 35, all push types |
| Subscription period, frequency, weekdays, month day, time, timezone, review | Subscription | inherits workspace timezone; review per role (§9 builders) |

## Entitlement keys and default limits (every plan, every type)

| Key | Default | Limits (default) |
|---|---|---|
| notifications.internal | on | — |
| notifications.push | on | devices_per_user 10, monthly_push 50000 |
| notifications.sound | on | — |
| notifications.external | on | external_recipients 500, monthly_messages 20000 |
| notifications.external.charts | on | monthly_charts 50000 |
| notifications.external.cc_bcc | on | cc_per_message 10 |
| notifications.external.custom_subject | on | — |
| notifications.external.review_mode | on | — |
| followup.morning_brief | on | — |
| followup.tasks | on | — |
| branding.remove_platform_footprint | **off** | — |

Usage counters (`usage_counters`, month in workspace tz): `notif.external_messages`, `notif.external_recipients`,
`notif.push_notifications`, `notif.charts_generated`. The sender increments messages and push; recipients and charts
are incremented by the builders that create them.

## Components moved / generalised to the shared level

- Builder L's push stack: engine `PushSender` uses `PushDispatcher::deliver` (made public); plan checks added to
  `PushDispatcher::wants`, `SoundPreferences::forReader`, `PushSubscriptions::register` (devices limit).
- `app_notifications` (bell) is the in-app channel's store — no second inbox table.
- `notification_preferences` (personal per-type switches) and `alert_rules` are read in place, not copied.
- `external_digest_recipients` → `notification_external_recipients` + `notification_subscriptions` (workspace level,
  not agency); legacy rows kept.
- `usage_counters`, `tenant_limit_overrides`, `SubscriptionService::currentPlan` reused for usage, overrides and plan.
- Builder K's `PeriodResolver` reused for every digest period.
- Nothing in the engine is under `/agency`; per-type terminology and roles come from profiles.

## Mail provider («stuck queued»)

Found in code (production not inspected — no access from here): the production example authenticates as
`info@campaignshub.io` and sends `From: no-reply@campaignshub.io`. Hostinger refuses a From that is not the
authenticated mailbox (553 «Sender address rejected: not owned by user»). `MailFailure` classified 553 as a recipient
problem and the 554/«sender» variant as transient, so the ledgers retried and rows waited «queued».
Fixed in code: `AlignEnvelopeSender` sets the envelope sender to the authenticated mailbox; sender refusals are
`sender_rejected` and permanent; the deploy example now sets `MAIL_FROM_ADDRESS=info@campaignshub.io`.
**Owner action:** set `MAIL_FROM_ADDRESS` to the authenticated mailbox in the live env (or create `no-reply@` as an
alias of the authenticated mailbox in Hostinger), then run `php artisan mail:dns-check` on the server: it reports
MX/SPF/DKIM/DMARC, the From alignment and ledger rows stuck >30 min, and changes nothing. DNS was not checked from
here and nothing was changed.

## Tests (targeted, `ptest.sh`, no --parallel)

`tests/Feature/NotificationsV2/`: EngineSchemaTest 3, CatalogAndWorkspaceTypesTest 5, EntitlementsAndUsageTest 8,
RoutingAndOutboxTest 9, SchedulerTest 6, SenderPipelineTest 10, MailProviderFixTest 3, LegacyMigrationAndConsentTest 5,
NoEmojiInNotificationsTest 3. Frontend: `src/lib/notifications/entitlements.test.ts`. Plus L's and the existing
notification / mail / route / isolation guards (see the final report for the run).

## Deferred (and why)

- External message design, privacy/review pre-send checks, CC/BCC, unsubscribe in the message, footprint, disclaimer
  (§8–§13): other builders; the extension points are `MessageComposer` and the `notifications.presend` tag.
- Settings UI, admin page, usage page UI, wizard (§2, §14, §15): no UI in this scope; the API above serves them.
- Domains do not call `NotificationEngine::raise` yet (alerts, budgets, sync still raise through the existing
  `NotificationDispatcher`): switching producers is a behaviour change per event and belongs with §5.
- Multi-project single message for org roles: the scheduler raises one intent per project; combining is §11's.
- Live SMTP / DNS evidence: requires the production server.
