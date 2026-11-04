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

Then read, in this order — they are the project's own record and they outrank any recollection:

- @docs/PERMANENT_REQUIREMENTS_AND_EXECUTION_GUARD_2026-10-22.md — the Owner's permanent, cumulative requirements index and completion gate: READ FIRST
- @docs/RESUME_STATE.md — the live state, under **START HERE**
- @docs/PROJECT_STATE.md — the recovery checkpoint: last verified commit, open pull requests, the
  commands that verify, the safe next action
- @docs/REQUIREMENTS_TRACEABILITY_MATRIX.md — every requirement, its status, its commit, its tests
- @docs/MASTER_EXECUTION_CONTRACT.md — the standing contract for how work is accepted
- @HANDOFF_MANIFEST.md — the map: architecture, portals, setup, integration readiness
- @docs/PRODUCTION_HANDOFF.md — what exists, what does not, and the money rules
- @docs/INTEGRATION_CREDENTIALS_CHECKLIST.md — every provider, its variables, its URLs, its state
- @docs/CHANGE_MANAGEMENT.md — how a change gets from an issue to production
- @docs/ENGINEERING_GUIDE.md — architecture, tenancy, security, frontend and the definition of done

Work from what those say. If a document contradicts the code, the code is the fact and the document
is a defect worth fixing.

## The owner's global policy, and what this repository overrides

`~/.claude/CLAUDE.md` carries the owner's operating policy for every project. **This file outranks it
on anything it addresses** — it fills gaps, it never overrides a decision made here. Two differences
are deliberate and binding:

- The global policy prefers a Claude Code cloud/remote session for long unattended work. **Not
  here.** The execution policy above is the decision: GitHub never invokes Claude or Anthropic for
  development, so the long-running path in this repository is the container plus CI/CD.
- The global policy asks for durable progress in `docs/PROJECT_STATE.md`. Here that file is the
  mechanical checkpoint only, and it sits *below* the matrix and `RESUME_STATE` in the order above.

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
complete English mirror. Four portals behind one `/login`. Details: @HANDOFF_MANIFEST.md.

## Running and verifying

```bash
cd backend  && php artisan test && vendor/bin/pint
cd frontend && npm run typecheck && npm test && npm run lint && npm run build
cd frontend && npm run gate > gate.log 2>&1; REAL_GATE_EXIT=$?
```

`REAL_GATE_EXIT` must be captured on its own line. Piping the gate into `tail` gives you `tail`'s
exit code, which is always 0, and that has produced a false green in this repository before.

## Permanent execution guard — read FIRST, every session

`docs/PERMANENT_REQUIREMENTS_AND_EXECUTION_GUARD_2026-10-22.md` is the Owner's cumulative requirements index and completion gate. Every session: `git fetch`; read the guard, `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md` (the only status ledger), `docs/OWNER_OBSERVED_DEFECTS.md` and `docs/RESUME_STATE.md`; reconcile ALL open rows and PRs, not only the latest message; then execute the next highest-value bounded batch (code → tests → CI → merge → deploy → real Production verification → update the EXISTING ledger rows). A later explicit Owner correction overrides only the precise part it contradicts. Never mark VERIFIED without Production evidence; never redo VERIFIED work.
