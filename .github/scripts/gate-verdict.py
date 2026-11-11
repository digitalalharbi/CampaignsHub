#!/usr/bin/env python3
"""CI-SPEED-001 — the `gate (<browser>)` check: prove the browser's shards ran its WHOLE suite.

usage: gate-verdict.py <browser> <total> <dir>

<dir> holds one `gate-shard-<browser>-<i>` folder per shard, each with:
  full.json   `playwright test --project <browser> --list --reporter=json` — the suite this browser would have run
              in one job, listed by Playwright from the same config, on the shard's own checkout;
  run.json    the shard's own run, written by the JSON reporter alongside the usual `list` output.

Fails unless:
  1. every shard listed the SAME full suite;
  2. the shards' tests are pairwise disjoint and their union is EXACTLY that suite — every spec still runs in this
     browser, once (the `setup` project is a dependency and runs in every shard, as it ran once per job before);
  3. every test of every shard finished `expected` or `skipped` (a `test.skip` the spec itself declares) — never
     `unexpected`, never `flaky` (retries are 0 by policy, so `flaky` would mean the config changed under us), and
     never absent from its shard's run;
  4. every SKIPPED test is in this browser's baseline (`gate-allowed-skips.json`, the skips of the last green
     pre-sharding run). A shard starts from the fresh seed rather than from the residue of the files before it, and
     28 specs decide at runtime whether to `test.skip` — a test that used to run could otherwise turn into a skip and
     still satisfy (2). A skip outside the baseline fails, naming the test; adding one is a visible edit in review.
The shard JOB results (success / failure / cancelled / skipped) are checked by the workflow before this runs: a shard
that did not succeed is red already, and a shard that was skipped or cancelled is never read as a pass.

The per-shard reports are merged into `merged.json` (one list of every test, its shard and its outcome) and uploaded
with the verdict, so «which shard ran X, and how did it end» is one file.
"""
from __future__ import annotations

import json
import sys
from pathlib import Path


def tests(report: dict) -> dict[str, dict]:
    out: dict[str, dict] = {}

    # The id is `[project] file:line:column › describe › … › title` — the same text the `list` reporter prints, which
    # is what the skip baseline was read from. The top-level suite is the FILE, whose title is already in the id.
    def walk(suite: dict, titles: list[str], top: bool = False) -> None:
        here = titles + ([suite['title']] if suite.get('title') and not top else [])
        for spec in suite.get('specs', []):
            for t in spec.get('tests', []):
                if t.get('projectName') == 'setup':
                    continue
                key = f"[{t.get('projectName')}] {spec['file']}:{spec['line']}:{spec['column']} › " + ' › '.join(here + [spec['title']])
                if key in out:
                    raise SystemExit(f'duplicate test id within one report: {key}')
                out[key] = t
        for child in suite.get('suites', []):
            walk(child, here)

    for s in report.get('suites', []):
        walk(s, [], top=True)
    return out


def main(browser: str, total: int, base: Path) -> None:
    problems: list[str] = []
    fulls: dict[int, dict[str, dict]] = {}
    runs: dict[int, dict[str, dict]] = {}
    for i in range(1, total + 1):
        d = base / f'gate-shard-{browser}-{i}'
        for name, into in (('full.json', fulls), ('run.json', runs)):
            try:
                into[i] = tests(json.loads((d / name).read_text()))
            except FileNotFoundError:
                problems.append(f'shard {i}: no {name} — the shard did not reach (or finish) its run')
            except json.JSONDecodeError as e:
                problems.append(f'shard {i}: {name} is not valid JSON ({e})')

    if not fulls:
        problems.append('no shard listed the suite')
    else:
        ref_i, ref = next(iter(fulls.items()))
        for i, f in fulls.items():
            if set(f) != set(ref):
                problems.append(f'shard {i} listed a different suite than shard {ref_i} ({len(f)} vs {len(ref)} tests)')
        if not ref:
            problems.append(f'the {browser} suite lists no tests — a gate that tests nothing is not a pass')

        owner: dict[str, int] = {}
        merged = []
        outcomes: dict[str, int] = {}
        for i, run in sorted(runs.items()):
            for key, t in run.items():
                if key in owner:
                    problems.append(f'{key} ran in shard {owner[key]} AND shard {i}')
                owner[key] = i
                status = t.get('status', 'missing')
                if status != 'skipped' and not t.get('results'):
                    status = 'not-run'
                outcomes[status] = outcomes.get(status, 0) + 1
                if status not in ('expected', 'skipped'):
                    problems.append(f'shard {i}: {key} ended {status}')
                merged.append({'test': key, 'shard': i, 'status': status})
            print(f'shard {i}/{total}: {len(run)} tests')

        allowed = set(json.loads(Path(__file__).with_name('gate-allowed-skips.json').read_text()).get(browser, []))
        skipped = {r['test'].split('] ', 1)[1] for r in merged if r['status'] == 'skipped'}
        for t in sorted(skipped - allowed):
            problems.append(f'{t} SKIPPED in {browser} but ran in the pre-sharding baseline — make it run, or add it to '
                            f'.github/scripts/gate-allowed-skips.json in review')
        for t in sorted(allowed - skipped):
            print(f'::notice::{t} is in the {browser} skip baseline but ran this time')
        print(f'{browser}: skipped {len(skipped)}; baseline {len(allowed)}; outside baseline {len(skipped - allowed)}')

        dropped = sorted(set(ref) - set(owner))
        extra = sorted(set(owner) - set(ref))
        if dropped:
            problems.append(f'{len(dropped)} {browser} tests ran in no shard, e.g. {dropped[:3]}')
        if extra:
            problems.append(f'{len(extra)} tests ran that the {browser} suite does not list, e.g. {extra[:3]}')
        print(f'{browser}: full suite {len(ref)} tests; union of {total} shards {len(owner)}; outcomes {outcomes}')
        (base / 'merged.json').write_text(json.dumps(sorted(merged, key=lambda r: r['test']), indent=1) + '\n')

    if problems:
        for p in problems[:50]:
            print(f'::error::{p}')
        if len(problems) > 50:
            print(f'::error::… and {len(problems) - 50} more')
        sys.exit(1)
    print(f'gate ({browser}) verdict: every test of the suite ran exactly once in this browser, and none failed')


if __name__ == '__main__':
    if len(sys.argv) != 4:
        sys.exit(__doc__)
    main(sys.argv[1], int(sys.argv[2]), Path(sys.argv[3]))
