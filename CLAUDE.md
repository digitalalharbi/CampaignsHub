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

- @docs/RESUME_STATE.md — the live state, under **START HERE**
- @docs/REQUIREMENTS_TRACEABILITY_MATRIX.md — every requirement, its status, its commit, its tests
- @docs/MASTER_EXECUTION_CONTRACT.md — the standing contract for how work is accepted
- @HANDOFF_MANIFEST.md — the map: architecture, portals, setup, integration readiness
- @docs/PRODUCTION_HANDOFF.md — what exists, what does not, and the money rules
- @docs/INTEGRATION_CREDENTIALS_CHECKLIST.md — every provider, its variables, its URLs, its state
- @docs/CHANGE_MANAGEMENT.md — how a change gets from an issue to production
- @docs/ENGINEERING_GUIDE.md — architecture, tenancy, security, frontend and the definition of done

Work from what those say. If a document contradicts the code, the code is the fact and the document
is a defect worth fixing.

## PAID AUTONOMOUS DEVELOPMENT IS CANCELLED BY OWNER — 2026-08-20

The owner cancelled the paid autonomous execution model. **`AUTOPILOT-001` and
`AUTOPILOT-CONTINUOUS-CHAIN-001` are no longer product requirements.**

No workflow in this repository may invoke a paid model automatically. Concretely:

- `.github/workflows/campaignshub-autopilot.yml` has no `schedule`, no `develop` job, no `uses:` and
  no `secrets.` reference — it cannot spend credit even if dispatched.
- `.github/workflows/claude.yml` stays in the tree and stays untriggered. Do not write `@claude`.
- `scripts/autopilot/test-autopilot.sh` fails the build if a schedule, a paid job, an action
  invocation or a secret reference is re-added to either file.

Development runs **directly from a Claude Code container**. GitHub is source control, CI and
deployment — not the development engine. The cycle is: change real product code in the container,
test locally, commit, push, CI, merge, deploy, verify, continue.

Everything else in this document remains binding: Git outranks the matrix, which outranks
`RESUME_STATE`; VERIFIED work is never redone without a proven fail-first defect; nothing is claimed
LIVE_VERIFIED without real operational evidence; and no change reaches `main` except through a
branch, a pull request and green CI.

Backend rows and green API tests are never completion on their own. A data feature is done when the
chain reaches the rendered UI and, where possible, live evidence.

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
