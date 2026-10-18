# Merge queue

One PR number per line, in merge order. `.github/workflows/merge-queue.yml` reads this file, takes the
first PR that is still open, and does exactly one thing for it per run:

1. **behind main** — with the `MERGE_QUEUE_TOKEN` secret, updates its branch (the push starts the PR's CI); without it, warns and waits for a person to push the update (the repository token's pushes do not start pull_request CI, and a dispatched run is not counted by the PR);
2. **a check failed** — stops and fails the run, so the failure is reported and nothing merges;
3. **checks still running** — waits for the next run;
4. **all of backend, frontend, image and the three browser gates green** — squash-merges, starts the
   production deploy, and moves on to the next PR.

A merged or closed PR is skipped, so lines can stay. To change the order, edit this file in a PR.
The queue never bypasses a failing check and never merges a head whose checks are missing.

```
756
744
754
755
752
```

## Why a token, and the smaller alternative

`main` requires every PR to be up to date. Making a stale PR current means pushing to its branch, and a push made with the workflow's own `GITHUB_TOKEN` starts no `pull_request` run (GitHub's rule against workflows triggering workflows). A CI run started by dispatch instead is **not counted** by the PR — measured on #756: the checks existed on the commit and the PR reported none. So without something more, a stale PR needs a person's `git merge origin/main && git push`. Existing `GITHUB_TOKEN` permissions cannot change this; widening them does not either.

Two ways to remove the manual step. Neither is done by this repository's code alone, because both are repository settings or credentials the Owner owns:

1. **GitHub's native merge queue (preferred — no secret at all).** In Settings → Rules → the `main protection` ruleset, add **Require merge queue** (squash, group size 1, build concurrency 1, status-check timeout 90 minutes) and make `backend`, `frontend`, `image` and the three `gate` checks the required checks of the queue. `ci.yml` already runs on `merge_group`. GitHub then builds each PR on top of `main` itself, merges it when green, and this workflow becomes unnecessary.
2. **`MERGE_QUEUE_TOKEN` (fallback).** A fine-grained personal access token, resource owner `digitalalharbi`, **only** the repository `CampaignsHub`, with: Contents **read and write**, Pull requests **read and write**, Workflows **read and write** (a branch update that brings in a changed `.github/workflows/*` file is refused without it), Metadata read. Nothing else — no Actions, Administration, or organisation scope is needed, because a merge made with a user token triggers the push-based deploy by itself. Store it as the repository secret `MERGE_QUEUE_TOKEN`; the workflow uses it when present and falls back to a warning when it is not.

Until one of them exists, the queue still merges and reports; it just cannot refresh a stale PR.
