# Notifications v2 — Owner spec (2026-11-15), binding

Source: Owner order of 2026-11-15 (Arabic). This file is the engineering restatement every builder reads first.
Branch `feature/notifications-v2`. One batch, one deployment. DB additive + reversible only (migrations with down()).
No secrets in commits (VAPID keys env-only; generate locally if absent, never print values, name the env var only).
No paid provider, no PDF, no headless browser. Chart images ONLY via resvg (SVG → PNG 2x), cached, batched.
No emoji anywhere (internal + external notifications, titles, buttons) — automated check.
Existing partial features are REUSED and moved to the shared level, never rebuilt: sound, PWA, external recipients,
alert rules, notification preferences (see feature/notifications-pwa = builder L: bell fix, sound, PWA, Web Push, prefs).

## 0. A SaaS feature for EVERY workspace type (agency, direct business/brand, client portal, future types)
- No logic/table/component/permission tied to a workspace type. Code in shared workspace-level places, not under /agency.
  Per-type routes are thin wrappers/redirects to ONE component. Settings: /settings/workspace?tab=notifications
  (&section=internal|external). Personal prefs: /account/notifications (or per-type equivalent wrapper).
- No hardcoded workspace/client value. Every example in the order is an editable default.
- Terminology adapts per workspace type via i18n keys: «عميل / مشروع» (agency) vs «علامة / حملة» (direct business).
- Default roles per type, all editable: agency = المالية، مدير المشاريع، الإدارة، العميل، أخرى; business = المالك، التسويق،
  المالية، أخرى; client portal = صاحب الحساب، الفريق.
- Setting layers (inherit + override): 1) Platform (admin): event catalog, default templates per workspace type, phrasing
  dictionary, default disclaimer, footprint, plans, limits. 2) Workspace: timezone, work days, quiet hours, org domains,
  routing, thresholds, sender identity, CC/BCC, templates, currency, language, disclaimer. 3) User/subscription: user edits
  personal channels; an external subscription inherits from the workspace and overrides.
- New workspace of any type works on defaults; a first-run wizard starts with the responsible recipient (admin/owner) + org domains.
- Isolation: RLS on every new table; nothing visible outside its workspace (UI, API, notifications); signed links bound to workspace+recipient.
- AR + EN via translation keys; custom text overrides defaults.

## 1. Plans: free now, priceable later with NO code change
- Every feature has an entitlement key in a plan-features table: notifications.internal, notifications.push,
  notifications.sound, notifications.external, notifications.external.charts, notifications.external.cc_bcc,
  notifications.external.custom_subject, notifications.external.review_mode, followup.morning_brief, followup.tasks,
  branding.remove_platform_footprint.
- Default NOW: all enabled for all plans and all workspace types EXCEPT branding.remove_platform_footprint (disabled for all).
- Quantitative limits generous + editable per plan from admin: external recipients, monthly messages, CC per message, devices per user.
- Usage counters start now per workspace per month: external messages, recipients, push notifications, charts generated.
  Shown in admin and in a workspace usage page.
- When a feature later becomes paid: configurable grace period; «keep existing» (grandfathering) option; never break:
  the feature doesn't stop abruptly, data isn't deleted, settings become view-only with an «ترقية» button; an in-app
  heads-up goes to the workspace owner before the grace period ends.
- Entitlements verified SERVER-SIDE (API and scheduled jobs), not UI only.

## 2. Notification settings page structure
One page /settings/workspace?tab=notifications with two sections, each deep-linkable:
- internal (&section=internal): routing (event→role→channel), channels (in-app / mobile push / email), priorities, quiet
  hours, work days, default sound, follow-up assistant settings, link to alert-rules page as source of some events.
  Existing per-workspace notification settings migrate here with no loss.
- external (&section=external): recipients & subscriptions, events, general settings, log. Existing external recipients
  migrate here; the old page shows a one-line pointer to the new place.
- Header: toggle internal/external; per-section KPI strip. Internal: team unread, open follow-up tasks, push delivery
  failures. External: held for review, held for privacy, sent this week, failed, awaiting confirmation, unsubscribed this month.
  Plan usage indicator (recipients, monthly messages vs limit).
- Personal prefs: channels, sound, quiet hours, followed projects, morning brief.
- Same design system, RTL, 100% mobile, all browsers, no horizontal scroll.

## 3. Shared engine
- ONE event catalog; each event: scope (internal/external/both), priority (info/important/critical — internal only),
  default roles per workspace type, allowed channels, entitlement key, whether it creates a follow-up task,
  title/body templates per scope and language.
- One event → several deliveries: internal (center / push / email) and external (email); each scope applies its own rules.
- Routing: by role + project assignment (user gets only assigned projects or has «all projects»), then personal prefs,
  then plan entitlement.
- De-dup key: event + object + threshold + day. Grouping: same-type events in a short window → one grouped notification.
- Quiet hours + holidays: delivery deferred. Internal critical bypasses; external has NO bypass.
- Every notification carries project name and workspace name.

## 4. Log, sending, reliability (shared)
- ONE immutable (no delete/edit, not via UI or API) log row per delivery: scope, channel, recipient, project, workspace;
  status: sent / failed(+reason) / deferred / skipped(+reason) / held_for_review / held_for_privacy / blocked_by_plan;
  reference number, content version, provider acceptance time. This log IS the proof of notice; no recipient acknowledgement.
  CSV export.
- Subscription log: confirmation, internal approval + who approved, unsubscribe, reduce, no-confirmation-in-7-days.
- Mail provider: fix sending from the verified platform address (messages currently stuck «queued»); check SPF, DKIM,
  DMARC and REPORT what is missing WITHOUT changing DNS. Status «sent» only after provider acceptance.
- Failure: 3 attempts with growing back-off. Expired push subscription auto-deleted. No duplicate sends.
- Execution: existing light queue or batched cron; spread sending; respect plan limits per workspace.

## 5. Internal notifications
Default internal events (incl. sensitive operational ones): budget 75/90/100%, actual overrun, spend limit reached; sync
failure, link expiring/expired, late data; automation action executed, recommendation awaiting approval; campaign spending
without results / stopped spending / cost-per-result up / return down (per project rules); budget or limit created / edited
/ renewed / deleted with who and before/after; external message awaiting review / held for privacy / failed; external
recipient unsubscribed/reduced/no confirmation in 7 days («[name] ألغى استلام تقارير [المشروع] بتاريخ …»); new/archived
project, active project with no budget for the period; nearing plan limit, feature entitlement change, grace period ending.
Client-portal users: only their projects, privacy rules + positive phrasing, no sensitive operational events.
Center: side drawer, tabs الكل / غير مقروء / متابعة; filter by project/type/priority; each item: title per real event,
project + workspace, time, detail line, button opening the exact page; actions read / snooze (1h, tomorrow) / mute this
type for this project; «mark all read» marks ALL unread server-side, counter updates immediately; live update; unread
count in the icon, the tab title and the PWA icon badge.
Web Push: installable PWA, manifest, icons, service worker, VAPID; Android + desktop, iOS 16.4+ after install with a short
iPhone guide; permission after a user gesture with a short explanation; devices page with remove; payload = title +
project + one line, click opens the page.
Sound: short soft «bing» (local file < 20 KB), only important/critical, never in quiet hours, on/off + volume + «جرّب الصوت».

## 6. Follow-up assistant
Morning brief (any user responsible for budgets/projects, any workspace type): in the center + optional email, at a
user-set time (default 08:00 workspace timezone), user's projects only: what needs follow-up today; budgets nearing end with
«تكفي حتى»; projects with no budget for the period; recommendations awaiting approval; data/link problems; yesterday's
external messages sent/held/unsubscribed.
«المتابعة» task list (automatic): e.g. renew budget (covers 4 days), set budget for period, relink platform, review held
message, approve/reject recommendation. Per task: project, reason, date; button to the exact place; snooze; assign to another
user; auto-close on completion (budget added, sync succeeded); full history (opened/closed/by whom).
«Re-route in one click»: any internal report can be sent by the responsible user to an external recipient using an external
template, through the same checks + review + logging. NO automatic edits to budgets/campaigns by the assistant — notify and
create tasks only.

## 7. External notifications: philosophy
A «service report»: informational, supports growth + trust; NOT an alert; never burdens the recipient. Sent in the
workspace's name. FORBIDDEN: acknowledge buttons, replies, deadlines, «مطلوب منك», any action request, any wording that
shifts responsibility, any content that could cause escalation/claims/accusation/campaign-stop decisions/legal-financial
commitment. Optional workspace setting (off by default): ONE «طلب تواصل» link in the footer.
Wording: achievements first. Terms «على المسار» / «فرصة تحسين» / «يحتاج متابعة»; FORBIDDEN: فشل، خسارة، قصور، تجاوز،
إنذار، عاجل، خطأ (editable list). Calm status colours from the design system, never alarm red. A budget near its end is shown
as continuity: «تكفي حتى [تاريخ] للحفاظ على وتيرة النتائج». Recommendations = «اقتراحات للنمو», advisory, no promises.
Sensitive operational events (sync failure, link, limits, overrun, automation, campaign without results) are INTERNAL ONLY.
Missing data: defer 2 h, retry once; still missing → exclude the affected project with «قيد التحديث — يصلك في الملخص القادم».

## 8. External: project + workspace identity (prevents any impression of a leak)
Mandatory, cannot be removed:
- Sender display name «[اسم المساحة] عبر CampaignsHub». Subject «{project} — {workspace} | [custom] · CampaignsHub», max 90
  chars, truncate the CUSTOM part only.
- Header: workspace logo + name; badge «تقرير مشروع: [المشروع]» (term per workspace type); line «تديره: [المساحة]».
- Privacy line under the greeting: «هذه الرسالة مخصصة لك بصفتك [الدور] في مشروع [المشروع] لدى [المساحة]، وتشمل بيانات هذا المشروع فقط.»
- Multi-project message: only for org-internal roles; each card named by its project; header lists count + names.
- Client/account-owner role: ALWAYS a separate message per project; never another project's/client's name or number anywhere.
- Visible CC to an out-of-scope client → converted to BCC automatically with a note.
- Footer: «لماذا وصلتك هذه الرسالة؟ أنت مشترك في تقارير مشروع [المشروع] من [المساحة] منذ [التاريخ].» + manage/unsubscribe link, always.
- Automated pre-send check: any data of a project outside the recipient's subscription → «held_for_privacy», not sent.

## 9. External: recipients, subscriptions, review
Recipient: email, name (optional), «اسم المخاطبة» (optional; shown literally in the greeting, no title added), role (from the
workspace type's roles), language, display currency (default workspace currency), «role mailbox» checkbox; add-time hint suggests
a role mailbox (finance@) instead of a personal address. Mandatory: email, projects, one type. Several subscriptions per
recipient; projects from the same workspace only; ARCHIVED projects excluded automatically.
Client role: own projects only, simplified language, no internal numbers, no unapproved recommendations; showing the budget is
a per-recipient option.
Consent: double opt-in confirmation email MANDATORY for client role and any address outside the workspace org domains
(«[اسم المساحة] تدعوك لاستلام تقارير مشروع [اسم المشروع]»). For org-domain addresses an «approved internally» option replaces
it, logged with who approved and when. If the recipient is already a workspace user, suggest switching to internal
notifications. NOTHING is sent before confirmation/approval. States: pending / active / paused / cancelled.
Unsubscribe in one click in every message: this type / this project / all / reduce frequency; any unsubscribe or reduction
raises an internal notification and is logged. Management buttons: pause / resume / edit / duplicate / delete (with confirm).
Sender identity + CC/BCC (optional): Reply-To default = sender email; optional signature; CC/BCC per subscription or event
with a plan limit and an «أضف نسختي» button; copy delivery: all messages / events only / daily digest for the sender.
Technically: one unified header; each address gets its own copy (separate SMTP envelope) with its own unsubscribe link; a
«نسخة للاطلاع» band in CC copies; BCC never exposed; duplicates delivered once; CC from outside org domains goes through
subscription confirmation.
Review-before-send: message is held; internal notification + task to the sender with «أرسل» / «تخطَّ هذه المرة»; no decision
within the window (default 4 h) → skipped. ON by default for the client role and for the first 3 messages of any new subscription.
Automated pre-send checks (any failure → review instead of send): illogical numbers, mismatch with screen, currency without
rate, incomplete data, forbidden word, emoji, action-request phrases, data outside the subscription.
MIGRATION RULE (safety decision): existing external recipients move over WITHOUT deletion and WITHOUT any automatic
confirmation email going to real people on deploy: they become «pending confirmation» (sending paused) and the workspace
owner triggers «أرسل طلبات التأكيد» explicitly (single button, with count preview). Logged as a decision.

## 10. External: periodic digests and events
«أرسل [نوع الملخص] عن [الفترة] كل [التكرار] الساعة [الوقت]». Periods: أمس / آخر 7 أيام / هذا الأسبوع حتى أمس / الأسبوع الماضي /
هذا الشهر حتى أمس / الشهر الماضي / آخر 30 يوم / فترة الميزانية النشطة / من بداية السنة (reuse builder K's period resolver:
all rolling periods end yesterday, Riyadh/workspace timezone). Frequency: daily (with weekdays) / weekly / monthly; time and
timezone per subscription. Visual calendar preview; illogical combinations blocked with a «استخدم البديل» button.
Digest types (multi-select): growth & performance by each campaign's objective (sales: orders/return/cost per order; leads:
count/cost; traffic: clicks/cost per click; awareness: views/reach — NEVER a cost-per-result aggregated across objectives);
spend by platform with previous-period comparison; budgets & continuity (budget, used, remaining, pace, «تكفي حتى» from the last
3 COMPLETE days; advisory = daily average × remaining days); top campaigns; growth suggestions (approved only for client role);
achievements. Complete days only.
Default external events: target reached; cost/return improved above a set %; record number; new campaign launched; budget
renewed/updated; continuity planning (enough until a near date, default 5 days); end-of-month digest; start of month: approved
budgets + projects awaiting a budget, planning wording. Responsible-role template (admin/owner by workspace type) includes
month-close + weekly summary by default.

## 11. External: creative — identical to the system's identity
Email is a visual extension of the CampaignsHub UI. ONE design-tokens source extracted from the current code (colours of
the system/platforms/statuses, backgrounds, borders, radii, spacing, fonts) used by email AND UI; no colour/style outside it;
workspace identity colours apply with the UI's logic.
Charts of the same kinds as the system's: donut (platform split, total in the middle), line/area (trend, previous period faded),
bars, progress ring (budget/target), KPI cards (trend icon from the system icon library + sparkline), stacked bar.
Generation: SVG from the same chart config → PNG at 2x via resvg. Embedding: inline CID or a short-lived signed link, whichever is
better — record the decision. Cache per (project + period + type + theme). Batched; max 300 ms per chart. Fallback: table
equivalent on failure / blocked images / plan key off. Every chart has descriptive alt text with the numbers + a text
representation in the plain-text part. Dark mode: alternate image for the dark theme. Size: HTML < 100 KB, images < 400 KB.
Layout: 600 px, cards with the system's radii/shadows/spacing, RTL + LTR, dark mode, system font if mail-safe.
Order: 1 workspace+project header; 2 greeting «مرحبًا [اسم المخاطبة]،» / «مرحبًا،» (EN «Hello [name],»); 3 privacy line;
4 opening card: top achievement + big number + progress ring + one data-based sentence; 5 KPIs; 6 trend; 7 donut by platform;
8 a card per project (name, status chip, budget ring, sparkline, «تكفي حتى»); 9 top campaigns; 10 growth suggestions;
11 signature; 12 «لماذا وصلتك هذه الرسالة»; 13 footprint; 14 disclaimer.
Tone per role: finance = clear numbers; admin/owner = big picture + trends; project manager/marketing = progress; client =
simple language, celebrating results. Links: NO links to internal platform pages or ids. Allowed only: manage/unsubscribe,
footprint link, «طلب تواصل» if the workspace enabled it.

## 12. External: custom subject, footprint, currency
Subject template variables {project} {workspace} {period} {highlight} {recipient_role} {date}; mandatory structure per §8.
Defaults: «{project} — {workspace} | ملخص هذا الشهر: {highlight} · CampaignsHub»; «{project} — {workspace} | أفضل أسبوع للمشروع · CampaignsHub».
Mandatory footprint (not removable by the workspace; admin controls it via branding.remove_platform_footprint): subject
suffix + sender name; footer with small platform logo, «تم إنشاء هذا التقرير تلقائيًا بواسطة CampaignsHub — منصة إدارة وتحليل
الحملات الإعلانية», public-site link with UTM (utm_source=email&utm_medium=notification&utm_campaign={type}), an
admin-editable invitation line. Footprint click statistics in the admin dashboard.
Currency identical to the system: ONE conversion source = the central FxEngine (builders F/G; rates only from the admin
rates page, no fixed rate anywhere); numbers match the screen literally; spend in the project's display currency; budgets in
their currency with the equivalent; different original currencies converted BEFORE summing; never a value with another
currency's mark; totals after conversion only with the currency next to every number; FX rates in a small footer font with the
date; rate unavailable → review.

## 13. External: disclaimer
End of message, small quiet font, editable per workspace, not removable. Default: «تقرير آلي من [اسم المساحة] لأغراض الاطلاع
والمتابعة فقط، ولا يتطلب أي إجراء منك. الأرقام من المنصات الإعلانية وقت الإنشاء وقد تُحدَّث لاحقًا، والمرجع المالي النهائي هو
الفواتير الرسمية. الاقتراحات استرشادية.» + creation time, data sources, and a reference number linking the message to the log.

## 14. External section UI
Tabs: 1 Recipients & subscriptions; 2 Events; 3 General settings (sender, CC/BCC, org domains, timezone, work days, phrasing,
currency, disclaimer, roles, subject templates, «طلب تواصل»); 4 Log. 4-step wizard: 1 recipient + role; 2 template (cards with
thumbnails, per workspace type); 3 build the sentence + events + custom subject + CC/BCC; 4 projects + preview + test send.
Advanced options collapsed. A feature disabled by plan shows a lock + «ترقية». Live preview in a phone frame: light/dark,
AR/EN, subject + sender name like an inbox. Buttons: «اعرض الرسالة» (real data), «أرسل نسخة لي», «أرسل للمستلم الآن» (with confirm).

## 15. Admin page
Event catalog; templates per workspace type; phrasing dictionary; default disclaimer. Plan features: keys, limits, grace period,
«keep existing». Footprint. Platform stats: messages sent, failure rate, unsubscribe rate, usage per workspace/plan, footprint clicks.

## 16. Tests (mandatory)
Generalisation/plans: every feature works in an agency, a direct-business and a client-portal workspace, each with its own
terms and roles (Playwright desktop + mobile); disabling a key → UI shows «ترقية», API + scheduled jobs refuse, data kept; re-enable
→ restored; grace + grandfathering; counters correct and isolated per workspace; per-type routes open the same component.
Structure: section=internal / section=external deep links; settings + recipients migrated without loss.
Internal: unassigned user doesn't receive; client-portal user only own projects without sensitive events; mark-all-read with
300+ → counter 0; critical in quiet hours delivered, info deferred; 3 similar events → one grouped; Web Push on Chrome Android +
desktop, expired subscription deleted; sound only important/critical.
Follow-up: morning brief correct per user's projects; adding a budget or a successful sync closes the task; re-route passes checks +
review + logged; assistant never edits a budget/campaign.
External periods: «this month until yesterday + daily» on 2026-11-15 covers 1–14; on the 16th covers through the 15th; weekends
no send; «last week + weekly» respects week start.
External recipients: archived project excluded; unconfirmed recipient gets nothing and after 7 days an internal notice; cancelling a
type/project leaves the rest + internal notice; org-domain accepts «internally approved»; outside address/client forces confirmation.
Content: no acknowledge button / «مطلوب منك» / deadline; unsubscribe link always; forbidden word/emoji/action phrase → review;
sync failure or overrun → internal only.
Identity/privacy: project + workspace in subject, header, privacy line, footer; long subject truncates custom part only; a client
with 2 projects → 2 messages; another project's id in the data → held_for_privacy; visible CC out of scope → BCC.
Protection: missing data → defer then «قيد التحديث»; currency without rate → review; review with no decision → skipped + notice.
CC/BCC: empty fields → normal send; separate unsubscribe link per address; BCC never visible.
Charts: match the UI for the same project/period; render in Gmail, Outlook (web + desktop), Apple Mail, light + dark; images
blocked or key off → table fallback. Numbers: message == screen after conversion, max difference 0.01.
Log: immutable, not deletable/editable via UI or API. Isolation: no access to another workspace's notifications/recipients/tasks via UI or API.
Automated emoji scan on all notification strings and templates.

## Final report must include
What was done; decisions in ambiguous points; table of every new setting (level platform/workspace/user-subscription + default);
table of entitlement keys + default limits per plan; list of components that were tied to a workspace type and moved to the shared
level; screenshots (3 workspace types: both sections, center, push on Android/desktop, personal prefs, morning brief, follow-up
list, a locked «ترقية» feature); 7 external emails (finance, project manager, admin/owner, client, achievement, continuity planning,
month-end) light+dark, AR+EN; inbox screenshot (subject + sender); chart comparison email vs UI, tokens list, image-embedding
decision, mean render time and size; review mode, privacy hold, log, subscription log; message-vs-screen number table for a
non-USD project; mail provider status + SPF/DKIM/DMARC; where the VAPID keys live (names only); migrated data status; deferred.
