# Merge queue

One PR number per line, in merge order. `.github/workflows/merge-queue.yml` reads this file, takes the
first PR that is still open, and does exactly one thing for it per run:

1. **behind main** — updates its branch and starts CI on it (strict up-to-date is required);
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
