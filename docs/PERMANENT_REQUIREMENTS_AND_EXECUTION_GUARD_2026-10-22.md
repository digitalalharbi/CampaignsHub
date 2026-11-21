# CampaignsHub — Permanent Requirements & Execution Guard
**المرجع الدائم لمنع سقوط المتطلبات وإغلاقها بالتحقق الفعلي**  
**Owner-approved product vision | Created: 2026-10-22 | Applies to EVERY Claude Code session and PR**

> **Non-negotiable:** This file is a **permanent execution guard and comprehensive requirement index**, NOT a replacement for the repository's canonical requirements matrix. Read it before planning or modifying the project, reconcile it against live Git, and enforce its acceptance rules after **every** delivery batch. Do not optimize for the latest chat message while forgetting older Owner instructions.

## 0. المهمة الأساسية — THE PRODUCT

**CampaignsHub = منظومة واحدة لإطلاق وإدارة ومتابعة وتحليل الحملات الإعلانية، وربط المتاجر، وتتبع روابط الإعلانات والمؤثرين، وقياس الأثر والإسناد والمردود، وإدارة أعمال الوكالة، وإصدار تقارير تفاعلية قابلة للمشاركة مع العملاء.**

The product must support the complete, connected journey:

`Client/Project → Authorized Ad Accounts → Launch → Campaign → Ad Group → Ad/Creative → Smart Link/Touchpoint → Lead/Store Order → Attribution/Reconciliation → Analytics/Recommendations → Client Report/Share Link → Weekly Delivery/Actions`

All observations must be real, scoped and explainable. **DATA → VISUAL SIGNAL → COMPARISON → SHORT INTERPRETATION → ACTION.** A collection of disconnected pages, APIs, adapters, mockups or demo dashboards does **not** fulfill the product goal.

### Fundamental rules

1. **CUMULATIVE REQUIREMENTS.** Every older decision remains binding except the precise part contradicted by a **later explicit Owner correction**. Preserve unaffected details. This file includes later corrections and must not be treated as the only original specification.
2. **IMPLEMENTATION TRUTH:** live Git + PR diffs + CI + deployed SHA + real Production behavior. Historical documents describe intentions, not current implementation state.
3. **NO FORGOTTEN WORK:** every instruction/defect must map to an existing canonical Matrix ID or receive a new unique row; never silently delete, close or downgrade a requirement.
4. **NO REWORK OF VERIFIED FEATURES** unless an actual defect or regression is demonstrated.
5. **NO FALSE COMPLETION:** documentation, green tests, PR approval, deployment and local preview alone do not prove functioning Production behavior.
6. **SMALL SHIPPABLE BATCHES:** finish one bounded implementation/PR, verify it, update the existing state, then choose the next incomplete item. Don't create a giant PR or have a session run uncontrolled for days.
7. **ONE PRODUCT, NOT FEATURE ISLANDS:** each module is visible in its relevant portals and connects to shared entities, permissions, evidence, filters and reports.
8. **PROTECT LIVE ACCOUNTS:** no ad spend, publishing, campaign mutation, bulk write or irreversible operation without explicit appropriate authorization; obey official provider capabilities and rules.

## 1. Sources, precedence, boot and continuity

### Read cumulatively

- `CampaignsHub_Execution_Master_Handoff_2026-09-25.md`
- `CampaignsHub_Conversation_Master_Context_2026-08-05.md` and later `(2)` / available updated variants
- `CampaignsHub_Conversation_Handoff_2026-09-10.md` / available variant
- `CampaignsHub_Master_Context_and_Instructions.md` / available variant
- All later Owner corrections and approved five-directive program (campaign pages, tracking, attribution, Athar/Thunder, granular visibility and product-wide design).
- Current repo: `digitalalharbi/CampaignsHub`.

### Living sources of execution truth

1. `origin/main` / HEAD, worktrees, uncommitted changes, open PRs and actual provider/Production observations.
2. `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md` (**only canonical requirements and statuses ledger**).
3. `docs/OWNER_OBSERVED_DEFECTS.md` (**Owner acceptance and defect evidence**, do not erase).
4. `docs/RESUME_STATE.md`, `docs/ACTIVE_EXECUTION_STATE.md` when present (short current operational handoff).
5. Historical handoffs for intent, original details and decisions.

**On every session:** `git fetch`; inspect HEAD, branches/worktrees, open PRs/CI/deploy and top unfinished matrix/defect rows. Correct **stale** `RESUME_STATE` first-screen headlines based on actual Git. Do not assume an old snapshot is current. Before compaction/interruption, commit/push safe work and write exact next step + blockers to existing state files. Preserve all local unfinished changes; do not duplicate PRs. Isolate test databases and working trees.

**Canonical statuses only:** `NOT_STARTED`, `IN_PROGRESS`, `PARTIAL`, `IMPLEMENTED_NOT_VERIFIED`, `VERIFIED`, `BLOCKED_EXTERNAL_CREDENTIALS`, `BLOCKED_OPERATIONAL_EVIDENCE`.

Clarifiers: Demo ≠ VERIFIED; a provider credential awaiting permission = `BLOCKED_EXTERNAL_CREDENTIALS`; an Owner login/live device/external transaction still needed = `BLOCKED_OPERATIONAL_EVIDENCE`; code shipped but not production-accepted = `IMPLEMENTED_NOT_VERIFIED`.

## 2. Execution & acceptance mechanism (MANDATORY)

### One existing ledger, NOT a competing backlog

For **every requirement** record/update in the canonical matrix (augment schema safely, do not duplicate rows):

`Requirement ID | Original Owner intent | Portal/screen | Backend | Frontend/UX | Integration/evidence | RBAC/Visibility | Tests | PR/merge SHA | Deploy SHA | Production acceptance | Status | Exact remaining gap | Next action/blocker`

For **every Owner-observed defect**, preserve its ID, reproduced symptoms, scope, code/merge/deploy evidence, **owner-visible Production acceptance** and unresolved condition in `OWNER_OBSERVED_DEFECTS.md`.

Validate Markdown table escaping (`\|` is content; bare `|` changes the schema), column counts and status-column integrity. Finish and enforce `MATRIX-INTEGRITY-001` / PR #795 equivalent. A status summary saying “507 verified” is NOT proof that every remaining row, portal and acceptance gate is complete.

**At the end of every bounded PR:**

1. Re-scan **ALL** canonical Matrix rows and Owner defects, not just the latest directive; classify `VERIFIED`, `PARTIAL`, `IMPLEMENTED_NOT_VERIFIED`, blocks and genuinely unstarted items.
2. Reconcile coverage of **every requirement group in Sections 3–17**, including cross-cutting requirements; attach IDs and meaningful acceptance criteria, not vague placeholder statuses.
3. Update the **existing rows only** for delivered work, exact evidence and remaining acceptance; don't label `VERIFIED` just because CI/merge/deploy succeeded.
4. Check PR backlog, dependencies and highest-severity executable gaps; execute next independently safe batch without waiting for routine approval.
5. Maintain short execution summary: `just shipped | Production evidence | open critical requirements by ID | actual blockers | next batch`. Avoid long repeated plans and idle “waiting” messages.

**Loop:** `observed defect / gap → fail-first non-vacuous regression test → smallest correct fix → focused tests + static/type/lint → required CI/browser tests → PR → merge → deploy → authenticated Production check → Matrix/Owner update → next batch`.

If a full CI gate runs, investigate/prepare genuinely independent work safely; don't contend on databases/worktrees. CI flakiness must be diagnosed from logs; a network `ECONNRESET` can justify a targeted retry, while a real failing assertion requires a fix. Never bypass protected branches or required checks.

## 3–16. The per-module requirement index

**Moved, not removed.** Sections 3 to 16 — portals and tenancy, navigation and language, campaign management,
dashboard and analytics, reports, store intelligence, smart tracking, attribution, influencers and creatives, agency
operations, budgets and billing, platform integrations, client-facing reports, and portal/production operations —
are in [`docs/PERMANENT_REQUIREMENTS_INDEX_2026-10-22.md`](PERMANENT_REQUIREMENTS_INDEX_2026-10-22.md), verbatim and
unedited (2026-11-06). **Every one of them binds exactly as it did when this was one file.**

They are a per-module index, read when you work on the module. The rules in this file — §0 to §2 and §17 to §18 —
are read on every message, which is why they stayed here and why `CLAUDE.md` still loads this file with `@`. Rule 3
of §0 is unaffected: no instruction or defect may be silently deleted, closed or downgraded, wherever it is written
down.

## 17. Permanent anti-forgetting completion gate

**Before declaring ANY batch complete, answer internally for EVERY section 3–16:**

- Are all its original requirements mapped to canonical matrix IDs, including older handoff details?
- Which requirements are actually VERIFIED on Production? Which are PARTIAL, unmerged, `IMPLEMENTED_NOT_VERIFIED`, blocked or unstarted?
- Does the implementation work from real data across required portals, permissions, exports and drilldowns?
- Is the specific Owner-observed defect closed by authenticated Production evidence, not just a test or screenshot?
- Are existing verified modules preserved without regressions? Are there unfinished branches/PRs containing useful work?
- Did the latest instruction conflict with a previous one? If yes, did we preserve all unaffected obligations and record the precise supersession?
- Is the next priority derived from **all incomplete requirements**, not the last conversational message?

**Project completion is not declared while non-external unfinished requirements or Owner defects remain.** Credential-gated work is not erased; its specific acceptance evidence stays open. Continue delivering small, independent, verified batches. Never inflate status or promise an asynchronous future deliverable without a real scheduled workflow.

## 18. Ultra-short instruction to start EVERY Claude Code session

> **RESUME FROM CURRENT GIT. Read `CampaignsHub_Permanent_Requirements_and_Execution_Guard_2026-10-22.md` + historical handoffs; reconcile ALL Matrix/Owner-defect IDs and unfinished PRs (not just the latest request). Execute the next highest-value incomplete batch: code → tests → CI → merge → deploy → real verification → ledger update. Preserve VERIFIED work, all older requirements and exact blockers. Continue with the next batch; keep status brief.**

---
**Owner's final product acceptance:** A real, visually excellent, commercially usable **all-in-one media buying, marketing tracking, commerce, attribution, operations and interactive reporting system**—not a demo and not a checklist of disconnected features.
