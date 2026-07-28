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
