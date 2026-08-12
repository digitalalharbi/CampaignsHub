# CHANGE MANAGEMENT — CampaignsHub

**`origin/main` is the system of record.** A change that is not on `main` did not happen; a change on
`main` that nobody wrote down happened badly. This file is how work gets from somebody noticing a
problem to it being fixed in production, and it applies to everyone — the owner, a hired developer,
and Claude.

---

## 1. The path, once

```
something is wrong or missing
        ↓
GitHub Issue            ← the only place a change starts
        ↓
branch                  ← claude/<issue>-<slug>  ·  fix/<issue>-<slug>  ·  feat/<issue>-<slug>
        ↓
implementation + tests   ← fail-first where a defect is claimed
        ↓
Pull Request            ← the template, filled in honestly
        ↓
CI green                ← backend + frontend; required, not advisory
        ↓
review                  ← conversations resolved, not dismissed
        ↓
merge to main
        ↓
deploy                  ← docs/DEPLOYMENT_CHECKLIST.md
        ↓
smoke test              ← §6 of that checklist, in the order given
        ↓
update docs/RESUME_STATE.md + the traceability matrix
```

No step is optional because it is a small change. A one-line fix that skips the test is how a
one-line defect ships twice.

## 2. Starting a change

Open an issue with the matching template — bug, improvement, integration activation, or production
incident. The templates exist so that a report carries what is needed to act **without re-explaining
the project**: the portal, the URL, what was expected, what happened, and whether money, tenant
isolation or a live integration is involved.

For a production problem, use **Production incident** and say plainly whether customers are affected
right now. That answer decides whether §5 applies.

## 3. Handing a change to Claude

> **PAUSED_BY_OWNER — 2026-08-12.** The `@claude` automation below is proven and switched off, to
> avoid spending Anthropic API credits. Do not mention `@claude` in an issue or a PR until the owner
> says otherwise. The App, the secret and `.github/workflows/claude.yml` all stay in place — this is
> a decision about use, not a rollback.
>
> In the meantime the same work happens in a Claude Code conversation: fetch `origin/main`, branch,
> implement, test, push, open a PR, let CI run, merge through the protected path. Every rule in this
> document applies unchanged; only the trigger is different.


In an issue or a pull request comment, address it:

```
@claude the client portal shows «لم تُرسل» for spend on project X since yesterday.
Root-cause it and open a PR.
```

The GitHub Action (`.github/workflows/claude.yml`) reacts to `@claude` in an issue, an issue comment,
a PR comment or a review comment. It reads the repository, works on a branch named
`claude/<issue>-<slug>`, and opens a pull request. **It never commits to `main`** — the branch
protection makes that impossible, and the workflow is not written to try.

What it will do: read the code, reproduce, write a failing test, fix, run the relevant suites, open a
PR with the template filled in.

What it will not do: invent a status, mark anything `LIVE_VERIFIED`, touch a credential, or redo
`VERIFIED` work without proving a defect first.

## 4. Reviewing and merging

A pull request is ready when:

- CI is green — backend (Pint, Larastan, `php artisan test`) and frontend (typecheck, lint, vitest,
  build). The three-browser Playwright gate runs locally before a release; CI covers the rest.
- The template is answered, including the sections that say "none" — «no migration» is information;
  a blank line is not.
- Every review conversation is resolved.
- If a requirement's status changed, `docs/RESUME_STATE.md` and
  `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md` are updated **in the same PR**. A status that lands a
  week later is a status nobody can trust.

Merge to `main`. Squash or merge commit — either is fine; the PR number in the message is what
matters, because it is the thread back to the reasoning.

## 5. Emergency hotfix

An emergency is: customers are affected right now, and the normal path is too slow. It is not: the
change is small, or it is late, or CI is annoying.

Even then, the documentation is not skipped — it moves after the fix instead of before it:

1. Branch `hotfix/<issue>-<slug>` from `main`.
2. The smallest change that stops the bleeding. Nothing else rides along.
3. Open the PR, say **HOTFIX** in the title, and merge as soon as CI is green.
4. If `main` protection must be overridden, only the repository owner may do it, and it is recorded
   in the issue: what was overridden, why, and at which commit.
5. **Within 24 hours**: the test that would have caught it, the root-cause note in the PR or issue,
   and the RESUME_STATE update. A hotfix without a follow-up test is the same incident scheduled
   again.

## 6. Secrets — the strategy, in full

**Nothing secret is ever committed.** The repository is public.

| Where a value lives | What goes there |
|---|---|
| `backend/.env.example`, `frontend/.env.example` | Variable **names** and safe placeholders. No values |
| GitHub **Actions secrets** | `ANTHROPIC_API_KEY` for the Claude workflow, and nothing else that is not needed by CI |
| GitHub **Environments** (`production`, `staging`) | Deployment credentials, scoped to the environment and to protected branches |
| The server's own environment | Every provider credential: gateway keys, OAuth client secrets, webhook secrets, mail credentials |

Rules that hold without exception:

- **No automation is granted write access to secrets.** A workflow may *read* the secrets it is given
  and nothing more. `secrets: write` is never requested.
- The Claude workflow receives exactly one secret, `ANTHROPIC_API_KEY`, and its permissions are the
  minimum the action needs to open a pull request. It gets no deployment credential and no provider
  credential, because it has no reason to hold one.
- A workflow triggered by an outside contributor's pull request does not receive secrets. That is
  GitHub's default and it is not relaxed here.
- Rotating a key means rotating it in the environment that holds it. It is never rotated by editing a
  file in this repository, because no file in this repository holds one.
- `php artisan production:check` reports the **shape** of a key — test, live, absent — and never its
  value. Keep it that way.

Which credential each provider needs, and what to do when one arrives:
`docs/INTEGRATION_CREDENTIALS_CHECKLIST.md`.

## 7. Repository access — who needs what

| Role | GitHub permission | Why |
|---|---|---|
| Owner | Admin | Rulesets, secrets, environments, emergency override |
| Application developer | **Write** | Branch, push a branch, open and merge a PR. That is the whole job |
| Reviewer | Write or Triage | Review and resolve conversations |
| Claude GitHub App | Contents + PR write, on this repository only | Open branches and pull requests |

**An application developer does not need Admin.** Write is enough to do every part of the workflow in
§1. Admin adds the ability to change the rules, delete the repository and read every secret — none of
which is part of building a feature. Grant it only when somebody must administer the repository, and
say so in the issue that grants it.

## 8. After a merge

- Deploy by `docs/DEPLOYMENT_CHECKLIST.md`. Two migrations rewrite existing rows; §7 of that file
  names them and says to back up first.
- Run the smoke tests in §6, in order. A payment path is only proven by a real payment.
- Update `docs/RESUME_STATE.md` START HERE with the new state, and the matrix if a status moved.
- If the change activated an integration, it becomes `LIVE_VERIFIED` **only** with real credentials,
  a real auth round trip, account discovery, a first live sync or payment, a real webhook, and the
  result visible in the product — recorded in the matrix with the evidence named.

## 9. What never changes on a whim

A frozen release tag, a canonical account address, the status vocabulary, and the rule that
`main` is protected. Changing any of those is its own issue, with its own reasoning, reviewed like
any other change.
