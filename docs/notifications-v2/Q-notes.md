# Builder Q — external notifications pipeline (NOTIF-V2-EXTERNAL-001)

Branch `feature/notifications-v2-q` (based on `feature/notifications-v2` = earlier batches + L + M). Not pushed, no PR.
Matrix and ledger not edited. Scope: Owner spec §7–§13 (engine side) on M's contracts. Status: `IMPLEMENTED_NOT_VERIFIED`
— targeted tests green; full suite, CI, real mail clients and production not run here.

## Where it lives (all under `backend/app/Domains/Notifications/External/`, workspace level, nothing under `/agency`)

| Part | File |
|---|---|
| Wiring into M's engine | `ExternalNotificationsServiceProvider` (after M's provider): `MessageComposer` → `ExternalMessageComposer` (external scope; internal keeps M's catalog composer), `MailSender` hands external deliveries to `Mail/ExternalMailer`, `Checks/ExternalPreSendChecks` on the `notifications.presend` tag |
| Design tokens (ONE source) | `frontend/src/lib/designTokens/tokens.json` (+ `index.ts`), byte-identical copy `backend/resources/design-tokens/tokens.json`, reader `Design/DesignTokens.php`; guard `DesignTokensSourceTest` |
| Recipients, subscriptions, settings | `Recipients/ExternalRecipients.php`, `Recipients/ExternalSettings.php` |
| Message | `Content/ExternalContext.php`, `Content/ReportView.php` (view model, subject), `Content/TextVersion.php`, `Content/Footprint.php`, view `resources/views/mail/external/report.blade.php`, strings `lang/{ar,en}/notifications_external.php` |
| Figures | `Digest/DigestDataSource.php` (contract), `Digest/MetricsDigestDataSource.php` |
| Charts | `Charts/ChartSpecs.php`, `Charts/ChartSvg.php`, `Charts/ResvgCharts.php`, `Charts/TableOnlyCharts.php`, `backend/scripts/chart-render.mjs` |
| Sending, CC/BCC | `Mail/ExternalMailer.php`, `Mail/ExternalTransport.php` |
| Consent email | `Consent/ConsentMessage.php` (used by M's `ConfirmationRequests::send`, still only on the owner's click) |
| Unsubscribe, footprint | `Unsubscribe/UnsubscribeLinks.php`, `Unsubscribe/Unsubscriber.php`, `Http/PublicLinksController.php` |
| Checks, review | `Checks/ExternalPreSendChecks.php`, `Review/ReviewQueue.php` |
| Events | `Events/ExternalEventDetector.php` |
| Commands | `notifications:v2-review-sweep` (15 min), `notifications:v2-external-events` (07:30 Riyadh) — both in the scheduled inventory |
| Migration | `2026_11_19_100000_notifications_v2_external_pipeline.php` (additive: `notification_external_reviews`, `notification_footprint_clicks`, `platform_settings.notifications_footprint`; `down()` drops them) |
| Engine edits (additive) | `ComposedMessage` (+`inline`, +`facts`), `PreSendCheck` (+`deferred` verdict), `SenderPipeline` (deferral), `MailSender` (external → ExternalMailer), `ConfirmationRequests` (designed invitation; projects added after a confirmation), catalog event `external.review_skipped` |

## JSON API for builder R (all `/api/v1/settings/workspace/notifications/external/…`, tenant from the membership, `settings.manage`)

| Method + path | Body / query | Returns |
|---|---|---|
| `GET options` | `?lang=en` | workspace type, roles `{key,label,is_client_role}`, responsible role, terms, digest types, external event keys, periods, frequencies, `period_rules` (alternatives), entitlement modes, limits, org domains |
| `POST period-check` | `period_key, frequency` | `{ok, alternative}` («استخدم البديل») |
| `GET kpis` | — | held_for_review, held_for_privacy, sent_this_week, failed, awaiting_confirmation, unsubscribed_this_month |
| `GET|PUT settings` | `sender{reply_to,signature}`, `external{disclaimer{ar,en}, forbidden_words[], contact_link, subject_templates{event:text}, continuity_days, improvement_pct, copy_delivery(all|events|digest), review_window_hours, display_currency}` | merged settings (disclaimer editable, never removable; empty = default) |
| `GET recipients` | `?status=` | recipients with subscriptions |
| `POST recipients` | `email, name, salutation, role_key, language, display_currency, is_role_mailbox, approve_internally, subscriptions[{project_ids, digest_types[], event_keys[], period_key, frequency, weekdays[], month_day, send_time, timezone, subject_template, cc[], bcc[], review_mode, show_budget, template_key}]` | `{recipient, notes[], hints[]}` — notes: `archived_projects_excluded`, `cc_moved_to_bcc:<addr>`, `client_role_review_stays_on`; hints: `suggest_role_mailbox`, `already_workspace_user_suggest_internal`, `confirmation_email_required` / `can_approve_internally` |
| `GET|PATCH recipients/{id}` | name, salutation, role_key, language, display_currency, is_role_mailbox | recipient |
| `POST recipients/{id}/{pause|resume|cancel}` | cancel needs `confirm: true` | recipient (nothing deleted) |
| `POST recipients/{id}/subscriptions` | one subscription | `{subscription, notes}` |
| `PATCH subscriptions/{id}` | any subscription field | `{subscription, notes}` |
| `POST subscriptions/{id}/{pause|resume|cancel|duplicate}` | cancel needs `confirm: true` | subscription |
| `GET reviews` | `?status=pending|sent|skipped` | held messages (reference, reason, expiry, recipient, project) |
| `POST reviews/{id}/send` / `…/skip` | — | «أرسل» / «تخطَّ هذه المرة» |
| `POST preview` | `subscription_id, project_id?, event_key?, theme(light|dark), locale(ar|en)` | `{subject, sender_name, reply_to, html (charts as data:), text, html_bytes, image_bytes}` — phone-frame preview + inbox line |
| `POST test-send` | `subscription_id, project_id?, event_key?` | «أرسل نسخة لي»: to the signed-in user only, «[تجربة]», outside the log |
| `POST send-now` | `subscription_id, project_id, confirm: true` | «أرسل للمستلم الآن»: through the engine (checks, review, log) |

Existing M endpoints still used: confirmation-requests preview/send, approve-internally, log, subscription-log, usage, entitlements.
Writes carry `plan.feature:notifications.external` (403 `meta.upgrade: true` when off; GETs stay readable). Admin footprint
settings (`platform_settings.notifications_footprint` = `{invitation_ar, invitation_en, site_url}`) and click statistics
(`Footprint::clicksByType()`) have no admin endpoint yet — R's admin page needs one (see Deferred).

## Decisions (ambiguous points)

| Point | Decision | Why |
|---|---|---|
| Image embedding | **Inline CID** (light + dark PNG parts), not a signed link | No public endpoint serving figures, no expiry breaking archived mail, no tracking pixel; size is bounded (≤ 400 KB, measured below). Apple Mail swaps to the dark image via `prefers-color-scheme`; clients that ignore it keep the light one |
| resvg route | npm `@resvg/resvg-js@2.6.2` (prebuilt incl. musl) via `scripts/chart-render.mjs`, one Node process per message | Node is already in the production image for reports; no Rust toolchain; installed beside playwright-core in `/opt/print` (Dockerfile) and as a frontend devDependency for local/CI |
| «Same chart config» | Charts are drawn from the message's own view model + figures (`ChartSpecs`) with the shared tokens (platform colours, brand, surfaces) | The UI charts are React/Recharts; the email reuses their data source and token palette, not their component code |
| Data source | `MetricsAggregator` scoped to the project (the analytics / live-report reads) + H's `ProjectBudgetReader`; sales result = the system's `ObjectiveResult` (conversions) | «message == screen» by construction; tested against an independent read within 0.01 |
| Currency | Recipient display currency → workspace external setting → `FxPolicy::displayCurrencyFor`; every amount through `FxEngine::convert` on the window's last day before any sum; a withheld rate → review | Spec §12; FxEngine is the only conversion source |
| Cost per result | Per objective group only, from that group's own spend and result; never across objectives; awareness shows reach, no cost card | Spec §10 |
| Client role | Separate message per project (already one intent per project); top **platforms** instead of top campaigns; no campaign names; budget shown per subscription (`show_budget`) | Spec §9 + the permanent client-report rule (no campaign names) |
| Review entitlement | `notifications.external.review_mode` gates turning review ON for non-client subscriptions; the mandatory defaults (client role, first 3 messages) stay on whatever the plan | Safety defaults are not a paid feature |
| Review «أرسل» | Re-queues a new delivery marked released; it skips the review/content/number/currency/screen checks but **not** privacy | A person decided; privacy is never overridable |
| Incomplete data | Pipeline gained a `deferred` verdict: log `deferred`, retry once after 120 min with `_data_retry`; then the message goes with «قيد التحديث — يصلك في الملخص القادم» in place of the figures | Spec §7; one message per project means «exclude the project» = the updating notice |
| CC/BCC | One header (To subscriber, Cc visible); each address its own message + envelope + own unsubscribe link; copies only after the primary was accepted; each copy its own log row `<ref>-C<n>`, not retried; outside-domain copy addresses become pending recipients (copy subscription) and get nothing until they confirm | Spec §9; a retried primary never duplicates copies |
| CC out of scope | A visible CC moves to BCC when the subscriber is a client and the address is outside the org domains, or the address is a client-role recipient of another project | Spec §8 |
| Unsubscribe links | HMAC over the app key, claims = workspace + recipient (or copy address) + subscription + project + event; no expiry; GET never changes anything; RFC 8058 one-click = «this type» | Spec §9; link scanners |
| Subject | `{project} — {workspace} | {custom} · CampaignsHub`, custom part only cut to fit 90 with «…»; custom from the subscription (needs `custom_subject`), else workspace `subject_templates[event]`, else default | Spec §8/§12 |
| Workspace logo | Initial-letter mark in the brand colour, not an image | No logo source on the engine side; an image would be another CID or external link (deferred) |
| Footprint removal | Only `branding.remove_platform_footprint` (OFF for every plan) removes subject suffix, «عبر CampaignsHub» and the footer | Spec §12 |

## Design tokens (the shared list)

Colours (light + dark, from `styles/tokens.css`): `brand-50/100/200/600/700`, `brand-gold`, `brand-background`, `success`,
`warning`, `info`, `purple`, `teal`, `background`, `surface`, `surface-secondary`, `border`, `border-strong`,
`text-primary/secondary/muted`, `positive-/warning-/info-background`, `platform-meta/google/tiktok/snapchat/x/linkedin/openai`,
`shadow-small`. Calm statuses: `on_track` (brand), `opportunity` (info), `needs_follow_up` (warning) — no alarm red exists.
Platforms map (incl. `google_ads`), chart series order, radii (`radius-extra-small` … `radius-pill`), type steps
(`text-xs` … `text-kpi`, weights), spacing (4–32 px), email width 600 and mail-safe font stacks (AR / EN).

## Measured (ExternalPreviewsTest, 7 templates × light/dark × AR/EN = 28 renders, this Mac, real resvg)

Mean render 135 ms per message (max 375 ms, incl. one Node process for all charts of the message; per chart 60–80 ms inside
resvg, first-render font load included). HTML mean 16.7 KB (max 18.3 KB, budget 100 KB). Images mean 51.9 KB (max 73 KB for
3 charts × light + dark, budget 400 KB). Previews: `NOTIF_PREVIEW_OUT=<dir> ptest.sh <wt> --filter=ExternalPreviewsTest`.

## Tests (targeted, `ptest.sh`, no --parallel)

`tests/Feature/NotificationsV2/External/`: DesignTokensSourceTest 3, ExternalRecipientsTest 8, ExternalMessageTest 8,
ExternalChartsTest 2, ExternalChecksAndReviewTest 8, ExternalPeriodsAndScreenTest 4, ExternalPreviewsTest 3; M's
SenderPipelineTest adapted (its catalog-composer guard now binds the bare composer explicitly); ScheduledWorkInventoryTest
gains the two commands. Frontend: `src/lib/designTokens/designTokens.test.ts`.

## Deferred (and why)

- `target.reached`, `record.reached`, `budget.renewed` producers: events are catalogued and fully rendered, but the facts
  belong to the targets and budget-ledger domains; a producer there calls `NotificationEngine::raise`.
- Multi-project message for org-internal roles (one message, a card per project): the scheduler raises one intent per
  project (M); combining needs an intent carrying several projects — not built; every message is single-project today.
- Admin endpoints for footprint settings and click statistics (data and `Footprint::clicksByType()` exist) — R's admin page.
- Workspace logo image in the header (initial mark used).
- Real-client rendering proof (Gmail, Outlook web/desktop, Apple Mail) — needs a real mailbox; only HTML structure,
  sizes and the dark media query are tested here. No real send was made (array transport only).
- Copy-delivery «daily digest for the sender» mode is the `digest` filter (copies of digests only), not a separate
  bundled daily email.
