# CampaignsHub — session bootstrap

**`origin/main` on `https://github.com/digitalalharbi/CampaignsHub` is the single source of truth.**
Not a local branch, not a worktree, not anything said in an earlier conversation. Where memory and
Git disagree, Git is right and memory is stale.

## Start of every session

```bash
git fetch origin
git log --oneline -10 origin/main
git status
```

Then read, in this order — they are the project's own record and they outrank any recollection.

**Six of them are loaded automatically, and the rest are read on demand.** The difference is not a ranking of
importance; it is a budget, and §Context budget below says why it exists and what each one costs.

Loaded into every message (`@`):

- @docs/PERMANENT_REQUIREMENTS_AND_EXECUTION_GUARD_2026-10-22.md — **READ FIRST.** The Owner's permanent
  execution guard: §0 the product and the eight fundamental rules, §1 sources, precedence and the canonical
  statuses, §2 the acceptance mechanism, §17 the completion gate, §18 the session-start instruction
  (~2,700 tokens)
- @docs/RESUME_STATE.md — the live state, under the top **CURRENT STATE** block, and the roster of registered binding families (~3,700 tokens)
- @docs/PROJECT_STATE.md — the recovery checkpoint: last verified commit, open pull requests, the
  commands that verify, the safe next action (~1,600 tokens)
- @docs/MASTER_EXECUTION_CONTRACT.md — the standing contract for how work is accepted (~5,700 tokens)
- @docs/CHANGE_MANAGEMENT.md — how a change gets from an issue to production (~2,100 tokens)
- @docs/ENGINEERING_GUIDE.md — architecture, tenancy, security, frontend and the definition of done (~1,600 tokens)

Read on demand — **open these yourself, with a tool, at the point you need them.** Every one of them still binds;
what changed on 2026-11-06 is *when* it is read, never *whether* it applies. Each row carries its measured size
and why it is not `@`-loaded, and nobody should add an `@` here without reading §Context budget first:

| Document | What it is | Size | When to read it |
|---|---|---|---|
| `docs/PERMANENT_REQUIREMENTS_INDEX_2026-10-22.md` | §3–§16 of the guard: the per-module requirement index — portals and tenancy, navigation, campaigns, analytics, reports, stores, tracking, attribution, creatives, agency operations, budgets, integrations, client reports, production operations | 26 KB · ~6,600 tokens | **It binds exactly as it did when it was one file.** Whole, when planning a batch that spans modules; otherwise the sections for the modules you are touching. The guard's rules kept their `@`; this index is what moved |
| `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md` | Every requirement, its status, its commit, its tests. The only status ledger | **2.3 MB · ~567,000 tokens** | **Never open whole.** `grep` it for the requirement you are working on — `grep -n 'REQUIREMENT-ID' docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`, then read around the hit with `sed -n`. Opening it costs more than half the context window |
| `docs/OWNER_OBSERVED_DEFECTS.md` | The Owner's observed defects | 212 KB · ~53,000 tokens | `grep` it the same way. Never `@` it |
| `docs/RESUME_STATE_HISTORY.md` | Every earlier `RESUME_STATE` checkpoint, verbatim | 177 KB · ~44,000 tokens | When you need the reasoning behind something already landed, or a requirement's state at a date |
| `docs/INTEGRATION_CREDENTIALS_CHECKLIST.md` | Every provider, its variables, its URLs, its state | 28 KB · ~7,100 tokens | When you touch a provider, a credential or an integration |
| `HANDOFF_MANIFEST.md` | The map: architecture, portals, setup, integration readiness | 18 KB · ~4,400 tokens | When you need the shape of the system rather than its state |
| `docs/PRODUCTION_HANDOFF.md` | What exists, what does not, and the money rules | 10 KB · ~2,600 tokens | Before touching money, billing or a payment path |
| `docs/CampaignsHub_MASTER_FINAL_COMPLETE_2026-11-11.md` | **The Owner's unified, cumulative reference for every change from 2026-11-11.** Sections أ–ط are the binding corrections and the batch order (one small batch per order, then stop); Appendix A is the 16 tasks and the style guide that applies to every new string; Appendix B is the security contract in the next row | 82 KB · ~20,500 tokens | At the start of a batch: sections أ–ط whole, then only the task being worked. Never `@` it |
| `docs/CampaignsHub_SaaS_Structural_Security_And_Routing_Contract_2026-11-11.md` | The Owner's binding, cumulative structural and security contract (2026-11-11): layers, record types, «where does it go», P0 security 1–15, scenarios S01–S22, batches A–F. Its audit and per-scenario state: `docs/SAAS_STRUCTURE_AND_REQUEST_ROUTING_AUDIT.md` | 30 KB · ~7,500 tokens | Before touching a portal, a workspace boundary, a public intake, a registration, an invitation, the requests inbox or the influencer domain |
| `docs/DEPLOYMENT_CHECKLIST.md` | The deploy, the smoke tests in §6, and the two migrations in §7 that rewrite existing rows | 11 KB · ~2,800 tokens | Before every deploy, and before writing a migration. `docs/CHANGE_MANAGEMENT.md` §8 sends you here and that is unchanged |

Work from what those say. If a document contradicts the code, the code is the fact and the document
is a defect worth fixing.

## Context budget — measured 2026-11-06

An `@` reference does not point at a document. **It loads the whole file into the context of every message of every
session**, so its cost is its size multiplied by the length of the conversation, and almost all of that is the same
bytes read again on every turn.

This file used to `@` ten documents. Measured from disk on `origin/main` that morning:

| Document | Bytes | ~tokens, on every message |
|---|---|---|
| `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md` | 2,268,883 | ~567,200 |
| `docs/RESUME_STATE.md` | 188,349 | ~47,100 |
| `docs/PERMANENT_REQUIREMENTS_AND_EXECUTION_GUARD_2026-10-22.md` | 34,725 | ~8,700 |
| `docs/INTEGRATION_CREDENTIALS_CHECKLIST.md` | 28,298 | ~7,100 |
| `docs/MASTER_EXECUTION_CONTRACT.md` | 22,863 | ~5,700 |
| `HANDOFF_MANIFEST.md` | 17,619 | ~4,400 |
| `docs/PRODUCTION_HANDOFF.md` | 10,258 | ~2,600 |
| `docs/CHANGE_MANAGEMENT.md` | 8,223 | ~2,100 |
| `docs/PROJECT_STATE.md` | 6,291 | ~1,600 |
| `docs/ENGINEERING_GUIDE.md` | 6,222 | ~1,600 |
| **Total `@` autoload** | **2,591,731** | **~648,000** |

**That is 65% of a one-million-token context window spent before anybody types a word** — and spent again on every
message after it. The matrix alone was 87% of it: 2.3 MB of historical record, re-read on every turn, to answer
questions that a `grep` answers in a few hundred tokens.

The rule, and it is the owner's policy in `~/.claude/CLAUDE.md` §7 as well as this file's:

- **Total `@` autoload stays under ~15,000 tokens**, unless keeping a safeguard in front of every message costs
  more — and then the safeguard wins and the excess is named. It is now **~17,300**, and the ~2,700 of that
  which is the execution guard is the deliberate overrun: unhooking it would have put the total at ~14,600 and
  left the eight fundamental rules, the canonical statuses and the acceptance mechanism dependent on a session
  remembering to open a file. The guard was split instead, so the rules kept their `@` and the ~6,600-token
  requirement index did not. **The next lever, if this has to come down further, is
  `docs/MASTER_EXECUTION_CONTRACT.md` (~5,700):** its first eleven sections are the binding contract and its
  three addenda are product specification, so it splits along the same line. That is a separate change.
- **Never `@` a changelog, a traceability matrix, a requirements index, a defect log or any file over ~20 KB.** Name
  it in the read-on-demand table above instead, with its size, so the next session knows what restoring the `@` would
  cost.
- When a document must be read every session *and* is large, **the document is the defect**: split the live part out
  and `@` that. `docs/RESUME_STATE.md` was split that way on 2026-11-06 — the live checkpoints stayed, the earlier
  record moved verbatim to `docs/RESUME_STATE_HISTORY.md`, and nothing was edited or deleted.

**What this change does and does not claim.** The figures above and below are the measured weight of what loads
on every message, before and after — `wc -c` on the files themselves. They are not a claim about consumption:
**what this actually returns can only be measured on later sessions**, because the realised cost of context is
its weight multiplied by how long a conversation runs, and neither that nor the cache behaviour is measured
here. Do not restate the before/after as a percentage saved on a bill.

**Honest limit of these figures.** The token counts are derived from file size at roughly four bytes to the token, so
they carry about ±15%. The exact tokenisation is not measured here, and the point does not depend on it: the error bar
is a few per cent of a number that is two orders of magnitude too large.

## The owner's global policy, and what this repository overrides

`~/.claude/CLAUDE.md` carries the owner's operating policy for every project. **This file outranks it
on anything it addresses** — it fills gaps, it never overrides a decision made here. Two differences
are deliberate and binding:

- The global policy prefers a Claude Code cloud/remote session for long unattended work. **Not
  here.** The execution policy above is the decision: GitHub never invokes Claude or Anthropic for
  development, so the long-running path in this repository is the container plus CI/CD.
- The global policy asks for durable progress in `docs/PROJECT_STATE.md`. Here that file is the
  mechanical checkpoint only, and it sits *below* the matrix and `RESUME_STATE` in the order above.

One part of it is **adopted here rather than overridden**: §7, the context budget. §Context budget above is this
repository's own statement of it, with the measurement that made it necessary.

## Execution policy — owner decision, 2026-08-20

**ALL PRODUCT DEVELOPMENT EXECUTION HAPPENS DIRECTLY FROM THE CLAUDE CODE CONTAINER.**

**GitHub is source control, CI and deployment only. GitHub must never invoke Claude or Anthropic for
development.**

`AUTOPILOT-001` and `AUTOPILOT-CONTINUOUS-CHAIN-001` are cancelled and are no longer product
requirements. Do not build a replacement.

Both model-invoking workflows are disabled in the tree rather than deleted, so the decision is
visible where somebody would otherwise re-add it by accident:

- `.github/workflows/campaignshub-autopilot.yml` — the scheduled path. No `schedule`, no `develop`
  job.
- `.github/workflows/claude.yml` — the `@claude` mention path. Its four event triggers are gone, so
  writing `@claude` on an issue or a review does nothing.

Neither file contains `anthropics/claude-code-action`, `ANTHROPIC_API_KEY`, any `secrets.`
reference, or any `uses:` at all. Each keeps a manually dispatched no-op that states the position.

`scripts/autopilot/test-autopilot.sh` fails the build if either workflow regains an automatic
trigger, the action, the key, a secret reference or any action invocation — and separately asserts
that `ci.yml`, `deploy-production.yml` and `production-diagnostics.yml` still have jobs, so the
cancellation cannot be achieved by breaking the workflows that do real work.

The cycle is: change real product code in the container, test locally, commit, push, CI, merge,
deploy, verify, continue to the next unit.

Everything else in this document remains binding: Git outranks the matrix, which outranks
`RESUME_STATE`; VERIFIED work is never redone without a proven fail-first defect; nothing is claimed
LIVE_VERIFIED without real operational evidence; no change reaches `main` except through a branch, a
pull request and green CI; and backend rows or green API tests are never completion on their own — a
data feature is done when the chain reaches the rendered UI and, where possible, live evidence.

## Fast and economical execution — owner decision, 2026-11-13

Goal: the least time and tokens to a published, verified fix, without dropping a requirement or lowering quality or security. Nothing here weakens a gate, the matrix or the status vocabulary.

- **Model:** Sonnet by default, Opus only for proven complexity. No automatic subagents, no open-ended sessions. A new agent only to fix a proven stall.
- **Read less:** Git state and the files of the task only. Do not re-read long references or redo VERIFIED work without a reproduced defect.
- **Batch:** 4–5 small independent changes may share one PR (`ch-scripts/train.sh`, one CI cycle). Security, finance, results, alerts and any high-risk change stay separate.
- **Test:** targeted local tests for the touched files, then the full required CI. Never skip a gate; never re-run green tests without a reason; use safe parallelism and caches where supported, without weakening a test.
- **CI failure:** diagnose the specific cause, fix it, do not re-run at random.
- **Merge and deploy** through the Lander when conditions and permissions are met; never deploy twice, never wait for checks that are not required.
- **Monitoring:** alert only on a stalled queue, a failed deploy or real risk. Successes go to the progress panel, not to messages.
- **Docs:** matrix and `docs/RESUME_STATE.md` with the smallest necessary diff, evidence kept.
- **After each batch:** a five-line report (done, tests, PR/merge, deploy and verification, remaining), then stop.
- **Measure, never estimate as fact:** record wait time, CI time, run count and tokens per batch before and after any speed change. 2026-11-13 baseline: serial landing averaged 49 min per PR (7 landings: 81, 44, 78, 44, 10, 40, 47); 26 PRs were queued.

## How work reaches main

Every change — human or agent — takes the same path:

```
issue / change request → branch → implementation → tests → PR → CI green → review → merge
```

- Branch naming for agent work: `claude/<issue-number>-<short-slug>`, or a plain
  `fix/…` · `feat/…` · `chore/…` when the change did not start from an issue.
- **`git fetch origin` first, always**, and branch from the freshest `origin/main`. A local checkout
  that disagrees with GitHub is stale, not authoritative.
- After every merge, fetch again and take that as the state for the next piece of work.
- **Merges go through the Lander, one at a time; do not merge a PR yourself.** Every branch must be up to date with `main`, so a merge outside the queue sends the PR in progress through about an hour of CI again. Add the branch with `~/Developer/ch-scripts/lander/lander.sh add <branch> "<title>"`; `lander.sh status` shows the queue. `ch-scripts/` lives beside the repository and is not tracked in it. If you cannot reach it, ask the owner instead of merging.
- **No direct push to `main`.** The branch is protected. An emergency override is possible for the
  owner and must be documented afterwards in `docs/CHANGE_MANAGEMENT.md` §Emergency.
- A PR fills in the template honestly: root cause, scope, tests, security, tenant isolation,
  migrations, integrations, deployment, rollback, evidence.
- After a merge that changes a requirement's status, update `docs/RESUME_STATE.md` and
  `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md` in the same PR.

## Status vocabulary — use it exactly

| Status | Meaning |
|---|---|
| `VERIFIED` | Built, tested and proven here, with the evidence named |
| `IMPLEMENTED_NOT_VERIFIED` | Written, not yet proven. Not done |
| `READY_FOR_CREDENTIALS` | Complete. Supply the credential and it runs |
| `READY_FOR_CONFIGURATION` | Complete. Awaiting a decision, not a credential |
| `BLOCKED_EXTERNAL_CREDENTIALS` | Waiting on a credential only the operator can obtain |
| `BLOCKED_OPERATIONAL_EVIDENCE` | Code ready; missing evidence from a real environment |
| `LIVE_VERIFIED` | Real credentials **and** a real auth round trip **and** account discovery **and** a first live sync or payment **and** a real webhook **and** the result visible in the product |

**Never write `LIVE_VERIFIED` without that external evidence.** Nothing in this system holds it
today, for any provider, including the payment gateway — that is a statement about credentials, not
about completeness.

## Standing rules

- **Do not redo VERIFIED work** without a defect proven first. Prove it fail-first, then fix it.
- Passing tests are not proof of completeness; documentation is not a substitute for code. Do not
  report a task complete that has not been run.
- Honest states everywhere: nothing is recorded as sent, connected or paid without a real verified
  provider response.
- Never commit a secret. Never put a credential in the repository. `.env.example` carries variable
  names and safe placeholders only.
- Development-only affordances (dev OTP codes, portal dev tokens, `/dev/status`) stay hard-gated off
  in production.

## Stack, in one line

Laravel 12 · PHP 8.4 · PostgreSQL 16 · Redis · Sanctum SPA cookie auth · DDD under `app/Domains/*`
— and React 19 · TypeScript strict · Vite · TanStack Query · Tailwind v4, Arabic-first RTL with a
complete English mirror. Four portals behind one `/login`. Details: `HANDOFF_MANIFEST.md` (read on demand).

## Running and verifying

```bash
cd backend  && php artisan test && vendor/bin/pint
cd frontend && npm run typecheck && npm test && npm run lint && npm run build
cd frontend && npm run gate > gate.log 2>&1; REAL_GATE_EXIT=$?
```

`REAL_GATE_EXIT` must be captured on its own line. Piping the gate into `tail` gives you `tail`'s
exit code, which is always 0, and that has produced a false green in this repository before.

## Permanent execution guard — read FIRST, every session

**This document keeps its `@`, and that is deliberate.** It is the one file whose rules decide whether work counts
as done, so it is in front of a session on every message rather than depending on somebody remembering to open it.
What it cost to keep is written in §Context budget above: it is ~2,700 tokens of a ~17,300-token autoload, which is
over the ~15,000 target, and the safeguard was ranked above the number.

It fits because it was **split, not shortened** (2026-11-06): §3–§16, the per-module requirement index, moved
verbatim to `docs/PERMANENT_REQUIREMENTS_INDEX_2026-10-22.md` and are read when you work on the module they
describe. **They bind exactly as they did when it was one file.** What stayed here is the part that has to be read on
every message — §0 and its eight fundamental rules, §1 precedence and the canonical statuses, §2 the acceptance
mechanism, §17 the completion gate, §18 the session-start instruction. Nothing was edited, and reassembling the two
files reproduces the original byte for byte.

`docs/PERMANENT_REQUIREMENTS_AND_EXECUTION_GUARD_2026-10-22.md` is the Owner's cumulative requirements index and completion gate. Every session: `git fetch`; read the guard, `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md` (the only status ledger — `grep` it for the rows you are reconciling, never open it whole: it is 2.3 MB), `docs/OWNER_OBSERVED_DEFECTS.md` (212 KB — `grep` it the same way) and `docs/RESUME_STATE.md`; reconcile ALL open rows and PRs, not only the latest message; then execute the next highest-value bounded batch (code → tests → CI → merge → deploy → real Production verification → update the EXISTING ledger rows). A later explicit Owner correction overrides only the precise part it contradicts. Never mark VERIFIED without Production evidence; never redo VERIFIED work.
