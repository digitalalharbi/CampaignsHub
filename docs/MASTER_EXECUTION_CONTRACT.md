# MASTER EXECUTION CONTRACT — CampaignsHub (permanent, binding)

> The single durable source of truth for ALL accumulated instructions in this project. Latest explicit
> correction overrides ONLY the conflicting part of earlier instructions; every non-conflicting requirement
> stays binding. Requirements are never dropped by inference or by conversation length. Passing tests ≠
> requirements met. Track every requirement in `REQUIREMENTS_TRACEABILITY_MATRIX.md`. Do NOT substitute docs
> for code: after updating these two files, work in code the same session.

## Fixed goal
Turn CampaignsHub into a professional, integrated center to **manage, monitor, review, and analyze ALL paid
advertising campaigns from one place** across **Snapchat, TikTok, Meta, Google Ads, X, LinkedIn**, with
campaigns linked to projects, clients, creatives, alerts, tasks, reports, finance, and integrations.

## Status vocabulary (matrix)
`NOT_STARTED · IN_PROGRESS · PARTIAL · IMPLEMENTED_NOT_VERIFIED · VERIFIED · BLOCKED_EXTERNAL_CREDENTIALS`.
Never `DONE/COMPLETED/FINISHED` without real code + passing test + browser review + commit + evidence.

## Per-requirement definition of done (by nature of the unit)
Database · Models · API · Validation · Permissions · Tenant Isolation · Frontend · Search · Filters ·
Classification (taxonomy-fed where manageable) · Details page/Drawer · Working actions (full flow, real
backend + activity log) · Related-entity links · Loading/Empty/Error · Responsive · RTL/LTR · Light/Dark ·
Targeted tests · Live browser review · Clean commit. NOT achievement: a doc, an unused component, an empty
page, an unwired endpoint, demo data alone, text-only changes, a green build, old tests, a button without a
full flow, an integration card without OAuth+sync, a pretty UI without backend.

## Honest integration states (only)
غير مربوط · جاهز للربط · بانتظار بيانات الاعتماد · تطوير جزئي · جاهز للاختبار · قيد الاتصال · متصل فعليًا ·
قيد المزامنة · متزامن فعليًا · صلاحية منتهية · صلاحيات ناقصة · فشل الربط · بيانات قديمة · وضع تجريبي. Never show
متصل/متزامن/Paid/Sent/Live/Connected/Synced without a real operation. Demo tag: «وضع تجريبي — لا توجد بيانات حقيقية».
No public-page internal jargon (SaaS/Tenant/Workspace/Operations Console/للمشتركين/للوكالات/تسجيل دخول النظام/دخول مساحة العمل).

## Modules & requirement-ID prefixes
A HOME (marketing homepage) · B AUTH (register/login/client portal) · C DASH (dashboard command center) ·
D CAMPAIGN (list/classify/compare) · E CAMPAIGN-DETAIL · F ANALYTICS (metric normalization) · G CREATIVE
(ad content performance) · H CLIENT / PROJECT · I PROJECT-INTEGRATION · J AD platform integrations
(INTEGRATION-META/GOOGLE/TIKTOK/SNAPCHAT/X/LINKEDIN) · K REPORT · L ALERT · M NOTIFICATION · N MESSAGE ·
O TASK · P FILE · Q REQUEST (services/portal) · R FINANCE (quotes/invoices/payments) · S TAX (taxonomy +
option manager) · T FORMS (input UX) · U PERM (permissions + tenant isolation) · V DEMO (demo data) ·
W UIQA (RTL/LTR + light/dark + responsive) · X QA (tests/preview/install/handover).

## Conflict & ambiguity policy
Newest explicit instruction wins on the conflicting part only. Unclear requirement → pick the interpretation
that best serves the fixed goal, record the decision in the matrix, implement without waiting (unless it needs
a secret/external credential). No external credentials → build the full activatable structure + labeled Demo
Mode + status `BLOCKED_EXTERNAL_CREDENTIALS`, continue.

## Execution order (do all; never drop earlier requirements)
1 Unified Campaign Overview → 2 Marketing homepage alignment → 3 Dashboard command center → 4 Campaign
classification & comparison → 5 Campaign details → 6 Creatives & content performance → 7 Alerts → 8 Messages
→ 9 Finance → 10 Tasks → 11 Files → 12 Clients & projects relationships → 13 Project integrations → 14 Six
ad-platform integrations → 15 Analytics & metric normalization → 16 Reports → 17 Requests & client portal →
18 Taxonomy adoption & option manager → 19 Forms UX → 20 Demo-data upgrade → 21 Cross-module relationships →
22 Visual review → 23 Full regression → 24 Clean install → 25 Final handover. A module already done in a prior
phase is re-verified against its requirement IDs (don't trust the report), then move on.

## Commit policy
One functional, tested unit per commit (`feat(alerts): build actionable alerts command center`, …). No
docs-only, WIP, broken-test, or unrelated-change commits (this contract + matrix are the sanctioned exception,
created once, and only alongside real code the same session). After each commit: targeted BE/FE tests +
typecheck + browser review + clean tree + update the matrix + proceed automatically.

## Continuity & context (70% rule)
Durable source = this file + the matrix + RESUME_STATE.md + git history (never conversation memory alone). At
70% context: finish the current unit, test it, browser-review, commit, clean tree, write the Exact Next
Requirement ID in RESUME_STATE, hand off. Next session resumes from the matrix — no re-analysis. Never stop at
a doc/audit; the Alerts unit (and any unit) must be functionally complete before any checkpoint.

## Preview (always up) + /dev/status
http://localhost:5173 · http://127.0.0.1:8000 · /dev/status. `/dev/status` should show: Current Requirement
ID, Current Module, Last Green Commit, Working Tree, FE/BE/DB/Redis/Queue/Scheduler, Last Tests, Preview,
Exact Next Requirement ID, Open/Verified requirement counts.

## Final closure gate
No handover while any requirement is NOT_STARTED/IN_PROGRESS/PARTIAL/IMPLEMENTED_NOT_VERIFIED. Then run BE full
+ FE typecheck/lint/tests/build + Chromium/Firefox/WebKit + desktop/tablet/mobile + RTL/LTR + light/dark +
migrate:fresh --seed + clean install. Require Failed=0, Flaky=0, Retries=0, Unexplained-skipped=0, Open=0,
Unverified=0, tree CLEAN. Final report lists every requirement ID = VERIFIED with its commit, test, reviewed
route, and any remaining external credential.

---

# ADDENDUM — Paid, self-serve SaaS (2026-07-31, binding, cumulative)

Ratified verbatim by the user on 2026-07-31 and **added to** everything above; nothing here replaces
an earlier instruction. The goal it states: CampaignsHub becomes a **self-operating, paid SaaS** for
running and reviewing every paid ad campaign from one place — with independent portals per user type,
and real registration, plans, payment, approval, permissions and operation. Not interfaces over
nothing.

## The five portals (final; see also ADR 0002)

1. `/admin/*` — the platform owner's console: tenants, agencies, advertisers, users, **plans,
   pricing, subscriptions, payments, invoices, taxes, offers, discounts**, account states,
   approvals, global permissions, system settings, identity, the marketing site, portals,
   taxonomies, integrations, audit log, operational alerts and service status.
2. `/app/*` — the advertiser / e-commerce space, under «كل حملاتك الإعلانية المدفوعة في مكان واحد»:
   projects, campaigns, ad accounts, **ad sets, ads**, content, budgets, objective-aware analytics,
   reports, alerts, tasks, files, team, integrations, **subscription** and workspace settings.
3. `/agency/*` — the multi-client agency system: clients, per-client projects and campaigns, ad
   accounts, agency team, roles + client scopes, approvals, reports, tasks, files, conversations,
   quotes, **the agency's invoices to its own clients**, payments, White Label and isolated client
   portals.
4. `/influencers/*` — influencers & UGC: campaigns, brief, nominations, influencers, creators,
   agreements, content, deliverables, reviews, approvals, scheduling, **links and codes**, results,
   reports, files and conversations — a different experience and permission set for brand / agency /
   influencer / creator.
5. `/portal/*` — request tracking and client spaces: submission, reference number, status and stages,
   quote, approval, invoice, **payment**, files, conversations, deliverables and notifications —
   without exposing full campaign-management tooling to someone who only needs to follow a service.

## Registration, plans and approval

**Filling in a registration form opens nothing.** The gated path is:

account type / portal → plan → inactive account → email verification → mobile verification →
review and approval where required → payment → **server-verified** payment → tenant + workspace +
membership created → portal and permitted features enabled → onboarding for that account type.

Account and subscription states, applied explicitly: `Draft`, `Email Verification Required`,
`Mobile Verification Required`, `Pending Approval`, `Approved Awaiting Payment`, `Payment Pending`,
`Active`, `Past Due`, `Suspended`, `Rejected`, `Cancelled`, `Expired`.

**No membership, permission or portal access is granted before the activation conditions are met.**

Approval policy is configurable from `/admin` per account type and plan: manual approval before
payment; pay first then review; automatic activation after verified payment; manual activation only;
trial period when enabled; request further information or documents; reject with a reason; suspend
and reactivate. From `/admin` the owner must be able to review advertiser and agency registrations,
accept / reject / request more information, see the chosen plan, see verification and payment state,
change the plan before activation, grant an exceptional period or discount, suspend or cancel, and
decide who may self-register versus who needs an invitation or prior approval.

## Plans engine

A central plans engine, **not fixed arrays**. Each plan actually determines: permitted portals; user
count; client count; project and campaign counts; ad-account and integration counts; report and
schedule counts; file storage; advanced features; White Label; custom domain; data-retention period;
support level; usage and API limits; currencies and billing cycle; monthly or annual; free trial when
enabled.

Entitlements and usage limits are enforced in the **backend**, not by hiding buttons. On exceeding a
limit: block the action clearly, show current usage against the limit, offer an upgrade, and never
delete the user's data or abruptly deny reading it.

## Payment

A real payment layer that can bind one or more providers without tying system logic to any one of
them: checkout session, payment intent, **signed webhooks**, idempotency, successful/pending/failed
payments, auto-renewal, cancellation, upgrade and downgrade, proration when enabled, refunds,
chargebacks, retries, invoices and receipts, taxes and currencies, and a complete event log.

**Returning from a payment page is not proof of success.** A subscription activates only after a
verified webhook or a server-to-server check. Never write `Paid`, `Active`, `Refunded` or `Sent`
unless it actually happened. With no provider credentials: build the structure, interfaces, webhooks
and tests against a clearly-labelled sandbox or mock, classify the state **Awaiting Credentials**,
do not claim real payment works, and continue everything else.

Keep separate, in accounting and in function — never merged into one misleading figure:
CampaignsHub subscription revenue from tenants · the agency's invoices to its clients · request
service payments · payments to influencers and creators.

## Self-operation

Account and workspace creation once conditions are met; features enabled and disabled by plan;
auto-renewal; expiry-approaching and payment-failure alerts; a manageable grace period; access
suspended at its end **with data preserved**; reactivation after payment; invoices and receipts sent
when a mail provider exists; scheduled jobs and reports; queue, scheduler and webhook monitoring; and
an audit trail for every subscription, payment, approval and permission change.

## Ad integrations

Complete the six platforms — Meta, Google Ads, TikTok, Snapchat, X, LinkedIn — along the path:
OAuth → account discovery → account selection → bind to project → campaign discovery → ad sets, ads
and creatives → sync → metric normalisation → analytics → reports → alerts. State is reported
honestly as `Live`, `Awaiting Credentials`, `Demo`, `Disconnected` or `Error`. **A demo sync is not a
connection.**

## Whole-system development

Nothing left cosmetic or partial, from the first request to the last setting: home page, registration
and sign-in · requests and services · clients · projects · campaigns · ad sets and ads · content ·
analytics · reports · integrations · alerts · conversations · tasks · files · subscriptions and
finance · influencers and UGC · approvals · settings · taxonomies and translations · administration
and audit.

Every portal gets its own dashboard, menu and taxonomy, services suited to its users, its own
workspace settings, real permissions and data isolation, loading / empty / error states, working
search, filters, views, details and actions, and a professional, un-branching design.

## Simplification

At most two menu levels. Merge near-duplicate sections without removing their functions. Use tabs,
drawers and progressive disclosure. No standalone page for a trivial option. Never show a section
that does not belong to the portal, plan or permission. Keep advanced functions available without
complicating the basic interface. **Never copy the same dashboard or menu between portals.** System
settings live in `/admin/settings`; each workspace's settings live inside its portal; personal,
security and session settings live **only** under the account icon.

## Mandatory live review

Registration for every account type · plan selection · approval and rejection · successful, pending
and failed payment · activation, renewal, suspension and reactivation · sign-in and routing to the
correct portal · plan permissions · usage limits and upgrade · team and client invitations · portal,
tenant and client switching · isolation across URL, API and identifier tampering · every link, button
and form · saving and retrieving data · each portal's files, conversations, reports and invoices.

Across desktop, tablet and mobile · RTL and LTR · light and dark · Chromium, Firefox and WebKit ·
backend, frontend and E2E · queue, scheduler and webhooks · fresh migration + seed · upgrade
migration · clean install.

## Closing condition

Return only once every non-external requirement is complete: all portals working to their purpose;
registration, plans, approval and payment actually applied; zero missing pages, settings or
functions; zero dead links, buttons or routes; zero misleading placeholders; zero requirements left
NOT_STARTED / PARTIAL / IMPLEMENTED_NOT_VERIFIED that are not externally blocked; zero failing,
flaky or retried tests; a clean working tree; and a single final report separating what is genuinely
implemented, what is Demo, what is Awaiting Credentials, and what is Blocked Operational Evidence.

As context fills: do not stop mid-work and do not return — finish the current unit, test it, make a
clean commit, update RESUME_STATE and the matrix precisely, compact, then resume from the first
requirement that is not VERIFIED.
