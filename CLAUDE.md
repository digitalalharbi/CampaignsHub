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

## The Claude GitHub Action is PAUSED_BY_OWNER

`.github/workflows/claude.yml` works — it was proven end to end on PR #5 — and it is **not to be
used**. The owner paused it to avoid spending Anthropic API credits. Do not write `@claude` in an
issue or a pull request, and do not trigger a run any other way.

Nothing is removed: the GitHub App stays installed, `ANTHROPIC_API_KEY` stays in Actions secrets, and
the workflow file stays exactly as it is. This is a decision about USE, not a rollback, and it is
reversed by the owner saying so.

Development happens in a Claude Code conversation instead. The cycle below is unchanged — the work is
authored in the conversation and still reaches `main` only through a branch, a pull request and CI.

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
