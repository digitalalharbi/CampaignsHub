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
760
759
761
```

## How a stale PR is refreshed without a secret

`main` requires every PR to be up to date. The queue updates the branch with the workflow's own `GITHUB_TOKEN`. A push made with that token starts no `pull_request` run (GitHub's rule against workflows triggering workflows), so the queue starts `ci.yml` by dispatch instead. A dispatched run's check runs are **not counted** by the PR (measured on #756: the checks existed on the commit and the PR reported none), so its last job, `report-status`, posts that same run's real verdicts as **commit statuses** with the names branch protection requires (`backend`, `frontend`, `image`, `gate (…)`). A failed job posts a failure; nothing is invented and no check is skipped. The queue then reads the PR's own view of its checks and merges only when all six are green for that commit.

If a PR is refreshed by hand (`git merge origin/main && git push`) the ordinary `pull_request` run does the same job, and no statuses are needed.

## Alternatives considered

- **GitHub's native merge queue — not available here.** It is offered to repositories owned by an organisation; this repository is owned by a user account (`digitalalharbi`). `ci.yml` keeps a `merge_group` trigger so it works unchanged if the repository ever moves to an organisation.
- **`MERGE_QUEUE_TOKEN` — not needed.** A personal access token would also work (a push made with it starts the normal run). Its minimum would be Contents, Pull requests and Workflows read/write on this repository only; the queue uses it automatically if the secret exists. Nothing requires one.
