# START HERE — main `c22de566`, a drain in progress with eight PRs open

Read this file, then `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`, then `git log origin/main`.
**Do not re-audit the whole project.** Everything below is Git, GitHub or Production evidence, and
where a claim is weaker than it looks the weakness is stated rather than smoothed over.
Operational authority: `Git → REQUIREMENTS_TRACEABILITY_MATRIX.md → RESUME_STATE.md`.
`ACTIVE_EXECUTION_STATE.md` is supporting context and never overrides these.

---

## 1. Current state

```
origin/main = c22de566  (#172 merged and deployed)
production  = https://campaignshub.io/  200, serving assets/index-4_rnsuHH.js
open PRs    = 8  (#171, #173–#179)
```

The previous edition of this file said `main = b53c2df` and `open PRs = 0`. Twenty-nine PRs have
been merged into main since, so it had stopped being a description of anything. That is the failure
this file exists to prevent, and it is why the header now carries the SHA rather than a date.

## 2. Merged into main since `b53c2df`, in merge order

Deploy column: the GitHub Actions run id and its outcome, read back from the run rather than assumed
from a green merge. Rows merged before the current drain log began are marked as such rather than
given a deploy claim that was never recorded.

| PR | what it delivered | merge SHA | deploy |
|---|---|---|---|
| #141 | Authoritative reconciliation — the tracker catches up with Git and Production | `3673c575` | merged before this drain log |
| #142 | ANALYTICS-OBJECTIVE-SYSTEM-001 — five objectives, derived rather than invented | `beb35f43` | merged before this drain log |
| #143 | One objective control, and it narrows the query rather than the heading | `f003bac8` | merged before this drain log |
| #144 | A request that failed is not «لا توجد بيانات» | `0c45bef2` | merged before this drain log |
| #145 | A campaign row says whether it is still running, and the order stops depending on the database | `66a5ae7a` | merged before this drain log |
| #146 | Filters that survive a refresh, the Back button and a shared link | `5a47759a` | merged before this drain log |
| #147 | The campaigns workspace opens on what is running | `8437e100` | merged before this drain log |
| #153 | The selector stops loading the estate, and a report about July can include July's campaigns | `9304491f` | merged before this drain log |
| #154 | LEAD-DEDUP-001 — a duplicate election that does not depend on who ran it first | `3d231bab` | `33205643184` success |
| #156 | A digest may quote an approved recommendation, once somebody asks for it | `6ae02658` | `33210720954` success |
| #148 | A campaign row says what it produced, and never prints a coalesced zero as a result | `d5d41a5e` | `33215323336` success |
| #149 | The card says what the result cost, and finds that metric rather than mapping it | `baa128f7` | `33218976899` success |
| #157 | BRANDING-HIERARCHY-001 — the exported PDF stops announcing the product on a client's document | `7fbf83df` | `33222170055` success |
| #155 | The diagnostic layer gets a reader, and stops mis-reading two objectives | `59275a4c` | `33225796457` success |
| #158 | The drill-down is in the propagation harness, and a bad parent is refused | `aea10a9e` | `33228222428` success |
| #159 | The scheduler records that it ran, and says so when it cannot | `afd4b8be` | `33230356457` success |
| #163 | HIERARCHY-ENTITY-ANALYTICS-DRILLDOWN — campaign → ad set → ad → creative, as one path | `2487aff1` | `33232796933` success |
| #162 | ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001 — evidence-gated actions, and the same engine on all three surfaces | `ea9a38ad` | `33235197853` success |
| #160 | One unnameable connection stops taking down the integrations page | `096e9c1a` | `33237455579` success |
| #152 | A shared report link stops previewing as the product's marketing line | `b869d6d5` | `33240001997` success |
| #161 | REPORT-TITLE-METADATA-001 — the browser tab says what the product is sold on, in the language it is sold in | `280599e8` | `33242144918` success |
| #164 | Every surface must state the same unit, not just the same number | `72a20399` | `33245127774` success |
| #165 | A campaign row can show a trend, and refuses to invent one | `22445496` | `33248719747` success |
| #166 | The recommendations toggle gets a screen | `4139662d` | `33251279769` success |
| #170 | The campaigns workspace answers before the reader scrolls | `891a515a` | `33253679220` success |
| #168 | The morning digest goes out under the agency's name, not the product's | `6a468124` | `33256049773` success |
| #167 | A branding logo that fails to load hides itself, and the row stops understating | `52c7bce2` | `33258466125` success |
| #169 | Failed once and failing every night stop looking the same | `13861cd5` | `33261142644` success |
| #172 | Three panels were sent a filter and ignored it | `c22de566` | `33263725256` success |

Every one of these was rebased onto the then-current main, re-gated **on its exact head**, and merged
pinned with `--match-head-commit`. Serial merges mean each merge stales the others; that is the ship
rule working, not a fault.

**One anomaly, recorded rather than tidied.** After #165 the post-deploy production check returned
`000`. An immediate recheck returned 200, and every check since has. A `000` from curl is no HTTP
answer at all — an instrument failure — so it is not evidence of an outage, and it is not evidence of
health either. It is written down because a reader scanning for `200` should not have to wonder why
one row is different.

## 3. Open queue — eight PRs, each gated on its own head

| PR | requirement | what it closes |
|---|---|---|
| #171 | UX-MULTISELECT-SCALE-001 | the campaign filter searches on the server |
| #173 | PROVIDER-CROSS-SURFACE-PROPAGATION-001 | the harness reaches recommendations |
| #174 | BRANDING-HIERARCHY-001 | the client portal's logo could never load — it answered 401 |
| #175 | REPORT-TITLE-METADATA-001 | the shared-link preview card had three rules and no assertions |
| #176 | LEAD-DEDUP-001 | duplicates reach a screen; «received» and «unique» are both reported |
| #177 | governance | five private statuses normalised to the canonical seven |
| #178 | AUTOMATION-FIRST-OPERATIONS-001 | retry and backoff become a policy, not a habit |
| #179 | ANALYTICS-FILTER-TRUTH-001 | the drill-down applies the filters it is sent |

Held locally and NOT pushed, because it depends on #171 landing first:
`feat/campaign-options-client` — wires `FilterMulti` to the server option endpoint with a debounce,
a per-URL label memory, and a «searching» state distinct from «nothing matched».

## 4. Status truth — do not promote without the named evidence

| ID | status | what is still missing |
|---|---|---|
| MONEY-USD-002 | PARTIAL | Production write is done and proven idempotent. **Cross-surface parity unverified.** |
| AGGREGATION-TRUTH-001 | IMPLEMENTED_NOT_VERIFIED | Needs a real Production scope: ≥2 contributing platforms, ≥1 inactive, ≥1 active-but-broken proving fail-closed. A preview rehearsal does not count. |
| PROVIDER-CROSS-SURFACE-PROPAGATION-001 | IN_PROGRESS | Thirteen surfaces reconciled locally. Currency/timezone/attribution are asserted only indirectly, and the harness has never run against Snapchat's REAL rows — which is what the row's evidence bar asks for. |
| PROVIDER-LIVE-VERIFICATION-001 | BLOCKED_EXTERNAL_CREDENTIALS | Snapchat has real evidence. Meta, TikTok, Google Ads, X, LinkedIn, Salla and Zid have no credentials and no first real sync. OAuth success is not verification. |
| BRANDING-WHITE-LABEL-ENTITLEMENT-OBS | `BLOCKED_PRODUCT_DECISION` on `c22de566`, becoming NOT_STARTED in #177 | `BrandingSetting.white_label` is stored and echoed back and nothing consults a subscription entitlement. Needs a commercial decision from the owner: is portal (and report) branding a paid tier, and if so which plans carry it. |

## 5. How this session works

`/tmp/drain2.sh` runs under `nohup` with its log at `/tmp/drain2.log` and its parked list at
`/tmp/drain2.parked`. It merges the first eligible root pinned to that PR's exact head, verifies the
deploy by reading the run back, then **batch-rebases every remaining head at once** so all gates run
in parallel rather than one behind another.

Two rules it follows that are worth keeping in any replacement:

- A network failure is retried, never parked. `dial tcp: i/o timeout` and `Recv failure` are
  instrument failures — NO RESULT — and treating one as a verdict is how a healthy PR gets closed.
- Every rebase is audited before it is pushed: no matrix row id may be lost, and a branch may never
  **gain** a file it did not have. The second guard exists because a branch silently acquiring
  another PR's file is the signature of the wrong tree being replayed, and it happened once.

## 6. Practices this session earned the hard way

- **Verify every guard by deleting it.** Nine tests written this session passed with their guard
  removed and were rebuilt. A test that cannot fail is a claim, not evidence.
- **Verify the injection reached the code.** One guard "passed" because the page under test mocks the
  module the defect was injected into. The line was untested while looking covered.
- **A pass after a fail on the same SHA proves non-determinism, not correctness.**
- **Absence of evidence is not evidence of absence.** Several suspected defects this session turned
  out to be correct code — the shared-link preview route, `projectIndex`'s unused `$project`, the
  creative library's filters. Each was checked before anything was written.
