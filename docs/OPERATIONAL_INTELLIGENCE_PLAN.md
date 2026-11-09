# خطة مركز الذكاء التشغيلي / Operational Intelligence Plan

Matrix row `OPERATIONAL-INTELLIGENCE-P1-001` · ledger row 164 · written against `feat/email-digests-operational` (#997). Additive: no parallel system; reports stay the data source.

## القاعدة الصارمة: الدليل قبل التوصية / The evidence rule

Every alert and insight carries an `evidence` payload: `metric`, `window_days`, `before`, `now`, `threshold` (+unit), `completeness` (`complete | unverified | partial`), `sufficient`, `missing[]`. Numbers are only ones the rule wrote into the event (`AlertAnatomy::evidence`); an absent number stays `null`, never `0`.
When `sufficient` is false (a missing before/now/window/threshold, a partial window, or a type with no defined evidence): priority is **P3**, **no recommended action and no impact sentence**, and the card says «بيانات غير كافية». Suppression itself stays with the evaluators, which already withdraw a verdict on incomplete windows (`costPerIncreases`); ROAS and no-results have no such guard, so they are marked `unverified`, not «complete».

**Priority (deterministic):** thin evidence → P3 · `critical` → P1 · deviation ≥ 2× the rule threshold → P1 · `warning` → P2 · else P3.
**Cell status (campaign × platform):** open P1/P2 alert on the cell → `مشكلة` · no metric row for that campaign+platform in the last 3 days → `لا بيانات` · open P3 or snoozed → `مراقبة` · else `سليم`. «سليم» needs recent data AND nothing open. A campaign-level alert marks all of its cells (it cannot be attributed to one platform); a connection alert names its provider and marks only that column.
**Ranking of «يحتاج قرارك الآن»:** open, undecided/in-progress events: priority, then severity, then newest, then id; top 15, the rest counted; healthy cells are a count, never listed.

## قواعد إضافية من المالك / Owner constraints folded in (2026-11-09)

- **Scale and replacement of the morning review:** one operator, up to 30 projects × 5 platforms. The board classifies up to 500 campaigns, shows the 5 most urgent exceptions and ≤ 8 flagged matrix rows (toggles reveal the rest); healthy is a count.
- **Inventory («جرد») rule:** *active* = `projects.status = 'active'` and `unified_campaigns.status = 'active'`. Every active project in reach appears exactly once in `projects[]` with status = worst of its cells. A project with no active campaign naming a platform has nothing to judge: it is listed in `unchecked[]` («لم يُفحص») and counted in `coverage` («checked/total»), never «سليم», never omitted. Test: `test_every_active_project_is_accounted_for_once…`.
- **Direct action:** each item carries `path` to the campaign screen, a one-line action in plain words (suppressed when evidence is thin), a one-tap «بدء المعالجة», and a tappable heat-map cell that opens the alert. The campaign page has no `platform` parameter and the product has no stored platform-console URL: both deferred, not faked.
- **Automation (read-only):** each item states what the automation did or proposes, from `action_proposals` linked through `external_campaigns`: `awaiting_approval` («ينتظر موافقتك»), `done_automatically` («تم تلقائيًا»), `in_flight`, else `needs_decision` («يحتاج قرارك»). Nothing executes, approves or changes spend. Automation *rule runs* are not recorded per campaign, so a rule's own outcome is deferred.
- **Minimal text:** icons, colour, deltas (`Delta`), counts and one-line cards; no prose blocks. Filters by priority and platform; status change in one tap.

## الفجوات حسب الرؤية / Gap analysis (strict: exists / missing / smallest step)

| Vision | Exists (file) | Missing | Smallest additive step |
|---|---|---|---|
| Daily/weekly/monthly summaries by project, portfolio, platform | `OperationalDigestModel::project/portfolio`, `DigestDispatcher`, `DailyDigest` (#997) | In-system twin of the digest; **portfolio is per project (one status each), decisions capped 8 (≤2 per project), no campaign × platform view — does not scale to ~75 cells (15 campaigns × 5 platforms)** | **P1 (done):** `AttentionBoard` + `GET alerts/attention`, matrix on the Alerts page. P2: feed the digest's decision cards from the same board so mail and screen agree |
| Interactive charts, comparisons, trends, budgets, funnel, content | Analytics/Reports pages, `charts.tsx`, `MetricsAggregator` | Nothing new needed for P1 | P3: link cells to Analytics with `platform`; `CampaignDetailPage` has no `platform` param yet (link goes to the campaign) |
| Change analysis, probable causes, growth/waste | `ChangeTimeline`, `metric_anomaly`, `DigestRecommendations`, `PlainFindings` | Causes ranked with evidence; waste/opportunity detectors | P2: causes list built only from measured deltas (spend/CTR/CVR/freshness), each with its own `evidence`; no cause without a measured delta |
| Smart alerts: problem, evidence, impact, action, owner, priority | `AlertEvaluator`, `AlertEventText::say` (wording), `AlertController` | Stored anatomy, owner, P1–P3 | **P1 (done):** `AlertAnatomy`, nullable columns on `alert_events`, derived on read for old rows |
| Decision log, follow-up, impact of actions | `AlertEvaluator::resolve/snooze`, task link (`TaskSources`) | Status workflow, audit, before/after | **P1 (done):** `alert_event_decisions` (append-only), `AlertDecisions`, `AlertImpact` (`insufficient_data` until the N-day window completes) |
| KPIs by project objective | `OperationalDigestModel::BY_OBJECTIVE`, `CampaignObjective` | Alerts do not yet choose their metric by objective | P2: default rule set per objective (sales → ROAS/CPA, leads → CPL, traffic → CPC/visits) |
| Short professional email opening the system | `AlertBundleMail`, `OperationalDigestMail`, `MailDeepLinks` | Priority and thin-evidence line | **P1 (done, small):** priority closes the context line; thin evidence replaces the recommendation |
| Reuse report links, no duplication | `MailDeepLinks`, live report link | — | Board paths reuse `/app/campaigns/{p}/{c}` |
| Numbers real, missing data stated, no unproven advice | `PlainFindings`, coverage guards | Uniform contract | The evidence rule above; test `test_priority_is_deterministic_and_evidence_gated` |

## ما سُلِّم في المرحلة 1 / Delivered in phase 1

Migration (nullable, additive) · `AlertAnatomy`, `AlertDecisions`, `AlertImpact`, `AttentionBoard` · endpoints under existing alerts routes (`alerts.view` reads, `alerts.manage` writes; no new permission; reach/ceiling as the ledger): `GET alerts/attention`, `GET alerts/events/{id}`, `POST …/decision|assign|note`; resolve/snooze now leave a log line · assignee must be an active member of the same tenant with `alerts.view` and the alert's project inside their reach · UI: board, priority chip, details drawer (five fields, decision controls, impact, log) with existing components, no chart library.

## المتبقي / Remaining (phases 2+)

Trend and probable-cause analysis; growth/waste opportunities; custom KPI sets by objective; interactive charts in the drawer; `platform` deep-link param on the campaign page; digest decision cards fed from `AttentionBoard`; daily-digest «جرد» line («30 من 30 مشروعًا مفحوصة» + «لم يُفحص») and the same three states in the mail (the mail still uses #997's per-project list); platform-console link and rule-run outcomes per campaign; impact for connection-type alerts (a token has no campaign metric: reported `not_measurable`); per-platform attribution of campaign-level alerts (needs the evaluator to split by provider).
