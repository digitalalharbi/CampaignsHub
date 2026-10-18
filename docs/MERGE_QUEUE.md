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

## One-time Owner step to make it fully hands-off

Add a fine-grained personal access token (repository `CampaignsHub`: Contents, Pull requests and Actions — read and write) as the repository secret `MERGE_QUEUE_TOKEN`. Until then a stale PR needs `git merge origin/main && git push` from a person.
