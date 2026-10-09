# RESUME STATE — CampaignsHub internal closure

_Written by the autonomous closure run. When this file and Git disagree, Git is right._

## Where the tree is

| | |
|---|---|
| Base | `origin/main` |
| Last merged | #625 `Content: the table names the ad account and the delivery state` (verify the SHA with `git log origin/main`) |
| Merge lane | ONE at a time. A second open PR puts the first in `BEHIND` and re-runs its full three-browser gate (~35 min). |

## The rule this run follows

Prepare the next unit in its **own worktree** while a gate runs; do not push it until the lane is
clear. Then: merge → deploy → verify by served bundle → rebase the prepared unit on fresh main →
push → open its PR.

## Units prepared and WAITING for the lane

| Worktree | Branch | What it closes | State |
|---|---|---|---|
| `Developer/ch-share` | `feat/share-client-identity` | `SHARE-PREVIEW-CLIENT-IDENTITY-001` | committed locally, not pushed |
| `Developer/ch-report` | `feat/report-content-browser` | `REPORT-CONTENT-BROWSER-001` | committed locally, not pushed |

Each has its full test run recorded in its commit message. Rebase on fresh main, re-run the focused
suites, then push.

## Shipped in this run

| PR | Closes | Evidence |
|---|---|---|
| #622 | `GA4-INTEGRATION-001`, `GA4-NOT-BLENDED-001`, `GA4-SECRET-HANDLING-001` | deployed `fbed06e1`; bundle `index-BGdpDjyt.js` carries every GA4 marker |
| #623 | the orders half of `CONTENT-RESULT-AVAILABILITY-001` | deployed `c459c5b0`; bundle `index-3JUG41xq.js` |
| #624 | the ordering + badge half of `CONTENT-BROWSER-PARITY-001` | deployed `10c4a4fc` |
| #625 | the table's account + delivery columns | in CI at the time of writing |

## Next requirement IDs, in order

1. `REPORT-CLIENT-OUTCOME-001` — remove operator noise from the client report. **Investigated, not
   started.** See the warning below before touching it.
2. `CONTENT-RESULT-AVAILABILITY-001` — re-read it; the prominence half shipped in #623 and the row
   may now be closable on evidence rather than code.
3. `BRANDING-RENDER-EVIDENCE-001`, `BRANDING-HIERARCHY-001`, `BRAND-MARK-001` — branding closure.
4. The `IMPLEMENTED_NOT_VERIFIED` sweep: for each row, classify as credential-blocked,
   owner-session-blocked, or verifiable read-only — and verify the third kind immediately.

## A dead end already explored, so it is not explored twice

The preview report at `/r/<token>` shows **«الإنفاق 0 USD» above content cards reading 2,643 USD**,
which looks exactly like the headline-contradicts-content defect #621 fixed one rung down.

**It is a demo-estate artefact, not a product defect.** Every creative in the preview project is
`is_demo = true`: the content reader includes demo rows for a demo project and the metrics
aggregator excludes them, so the two disagree only where the data is seeded. Checked against the
database — the project has 36 campaign-grain and 54 creative-grain rows in the window — before
concluding. Do not "fix" it by changing demo policy.

## Local preview stack

`ga4-api` on 8121 and `ga4-web` on 5221 in `.claude/launch.json`, against the
`campaignshub_preview` database. Sign in as `agency@campaignshub.io` / `password`. A GA4 estate is
seeded there (3 properties, one bound, 98 measured rows) — in that PRIVATE database only, never in
a shared seeder.

## Standing constraints that cost time when forgotten

- One local test suite at a time: every worktree's phpunit points at `mediabuying_test`.
- Pint `bootstrap/app.php` after registering a command, or CI fails on style alone.
- `*/` inside a `/* */` comment closes it early — a path like `lang/*/x.php` in a docblock is a
  parse error.
- A client report carries **no campaign identity** (`CLIENT-REPORT-ENTITY-BOUNDARY-001`, owner,
  "do not ask again"), whatever a generic column list says.
