#!/usr/bin/env python3
"""CI-SPEED-001 — split the backend suite across parallel jobs WITHOUT changing what runs.

usage (from `backend/`):
  backend-shard.py plan <shard> <total> <outdir>    write this shard's phpunit.xml and its evidence
  backend-shard.py verdict <total> <dir>            the `backend` check: prove the shards ran the whole suite

## What a shard is

PHPUnit itself names the suite: `phpunit --list-test-files` and `--list-tests` against the repository's own
`phpunit.xml`. Nothing here globs for files or guesses which classes are tests — the list is the one PHPUnit would
have run in a single process.

Those files are dealt into <total> shards (largest first, onto the lightest shard; deterministic, so every shard
computes the same partition without talking to the others). A shard's config is `phpunit.xml` with TWO changes: the
<testsuites> block lists that shard's files, in the order PHPUnit discovered them; and the bootstrap is the
repository's bootstrap followed by loading the other shards' test files, so every class a full run declares
before its first test is declared here too (see plan()).
Every <php> env and ini is byte-for-byte the repository's. `php artisan test` then runs exactly as before; it always passes
`--configuration=phpunit.xml`, so the shard's config is written over the runner's copy of that file.

## What the verdict proves (and fails on)

  1. every shard saw the SAME full test list (a shard on a different commit, or a different config, fails);
  2. the shards' test ids are pairwise disjoint and their union is EXACTLY the full list — nothing dropped,
     nothing run twice;
  3. each shard's junit log records as many executed test cases as that shard was given — a shard that
     silently ran nothing (the `artisan test --parallel` trap) or stopped early cannot pass;
  4. no junit log records a failure or an error.
The job results themselves (success / failure / cancelled / skipped) are checked by the workflow before this runs;
anything but `success` from any shard is already red.
"""
from __future__ import annotations

import json
import os
import re
import subprocess
import sys
import xml.etree.ElementTree as ET
from pathlib import Path


def phpunit(*args: str) -> list[str]:
    out = subprocess.run(['vendor/bin/phpunit', *args], check=True, capture_output=True, text=True).stdout
    return [line[3:].rstrip('\n') for line in out.splitlines() if line.startswith(' - ')]


def partition(files: list[str], weight: dict[str, int], total: int) -> list[list[str]]:
    bins: list[list[str]] = [[] for _ in range(total)]
    load = [0] * total
    # Largest first onto the lightest shard (LPT). Ties broken by name, so every shard computes the same answer.
    for f in sorted(files, key=lambda f: (-weight[f], f)):
        i = min(range(total), key=lambda k: (load[k], k))
        bins[i].append(f)
        load[i] += weight[f]
    order = {f: n for n, f in enumerate(files)}
    return [sorted(b, key=order.__getitem__) for b in bins]


def plan(shard: int, total: int, outdir: Path) -> None:
    if not 1 <= shard <= total:
        sys.exit(f'shard {shard} is not in 1..{total}')
    outdir.mkdir(parents=True, exist_ok=True)
    root = Path.cwd().resolve()

    files = [str(Path(p).resolve().relative_to(root)) for p in phpunit('--list-test-files')]
    tests = phpunit('--list-tests')
    if not files or not tests:
        sys.exit('PHPUnit listed no test files or no tests — refusing to plan an empty shard')

    # Weight = the class's measured seconds on CI (`backend-test-seconds.json`); a class with no measurement yet is
    # estimated from its test count at the suite's measured mean seconds-per-test. Only the BALANCE between shards
    # depends on this — a stale or missing number can make one shard slower, never drop or repeat a test.
    per_class: dict[str, int] = {}
    for t in tests:
        cls = t.split('::', 1)[0]
        per_class[cls] = per_class.get(cls, 0) + 1
    seconds = {k: float(v) for k, v in json.loads(
        (Path(__file__).with_name('backend-test-seconds.json')).read_text()).items() if not k.startswith('_')}
    per_test = sum(seconds.values()) / max(1, sum(per_class.get(c, 0) for c in seconds))
    weight: dict[str, int] = {}
    for f in files:
        cls = 'Tests\\' + f.removeprefix('tests/').removesuffix('.php').replace('/', '\\')
        weight[f] = max(1, round(10 * seconds.get(cls, per_class.get(cls, 1) * per_test)))

    mine = partition(files, weight, total)[shard - 1]
    if not mine:
        sys.exit(f'shard {shard}/{total} was dealt no files — lower the shard count')

    original = Path('phpunit.xml').read_text()
    block = '<testsuites>\n        <testsuite name="Shard">\n' + ''.join(
        f'            <file>{f}</file>\n' for f in mine) + '        </testsuite>\n    </testsuites>'
    shard_xml, n = re.subn(r'<testsuites>.*?</testsuites>', lambda _: block, original, count=1, flags=re.S)
    if n != 1:
        sys.exit('phpunit.xml has no <testsuites> block to replace')

    # A full run LOADS every test file while it builds the suite, before the first test executes — so a helper class
    # declared inside one test file (e.g. `ConfiguredAlertEmailProvider` in AlertDispatchTest.php, bound by name in
    # NotificationPreferencesCentreTest) exists for every test. A shard that loaded only its own files broke exactly
    # that (first CI run of this change: 3 failures in shard 3). So the shard's bootstrap is the repository's
    # bootstrap followed by `require_once` of every test file of the OTHER shards; PHPUnit then loads this shard's own
    # files itself while building the suite. Before the first test runs, the same classes are declared as in the
    # single-process run.
    #
    # The shard's OWN files must not be preloaded: PHPUnit 11 finds a test class by diffing the declared classes around
    # its own `require_once`, and for an already-loaded file it can miss the class and only WARN («Class X cannot be
    # found») — the class's tests silently vanish and the run still exits 0. (Measured: preloading everything dropped
    # 160 of 7,982 tests from the listing.) The verdict's per-shard junit count exists for exactly this kind of thing.
    boot = re.search(r'bootstrap="([^"]+)"', original)
    if not boot:
        sys.exit('phpunit.xml names no bootstrap')
    Path('phpunit.shard-bootstrap.php').write_text(
        "<?php\n// Generated by .github/scripts/backend-shard.py on the CI runner; never committed.\n"
        f"require_once __DIR__.'/{boot.group(1)}';\n"
        + ''.join(f"require_once __DIR__.'/{f}';\n" for f in files if f not in set(mine)))
    shard_xml = shard_xml.replace(boot.group(0), 'bootstrap="phpunit.shard-bootstrap.php"', 1)
    Path('phpunit.shard.xml').write_text(shard_xml)
    shard_tests = phpunit('--configuration', 'phpunit.shard.xml', '--list-tests')

    missing = sorted(set(shard_tests) - set(tests))
    if missing:
        sys.exit(f'the shard lists tests the full suite does not: {missing[:5]}')

    (outdir / 'full-tests.txt').write_text('\n'.join(tests) + '\n')
    (outdir / f'shard-{shard}-files.txt').write_text('\n'.join(mine) + '\n')
    (outdir / f'shard-{shard}-tests.txt').write_text('\n'.join(shard_tests) + '\n')

    # From here on the runner's phpunit.xml IS the shard's. The repository file is untouched; this is a CI checkout.
    os.replace('phpunit.shard.xml', 'phpunit.xml')
    print(f'shard {shard}/{total}: {len(mine)} of {len(files)} files, {len(shard_tests)} of {len(tests)} tests '
          f'(weight {sum(weight[f] for f in mine)} of {sum(weight.values())})')


def verdict(total: int, base: Path) -> None:
    problems: list[str] = []
    full_lists: dict[int, list[str]] = {}
    shard_tests: dict[int, list[str]] = {}
    for i in range(1, total + 1):
        d = base / f'backend-shard-{i}'
        try:
            full_lists[i] = (d / 'full-tests.txt').read_text().split('\n')[:-1]
            shard_tests[i] = (d / f'shard-{i}-tests.txt').read_text().split('\n')[:-1]
        except FileNotFoundError as e:
            problems.append(f'shard {i}: no evidence ({e.filename}) — it did not reach its test step')
            continue

        junit = d / 'junit.xml'
        if not junit.exists():
            problems.append(f'shard {i}: no junit log — its tests did not run to completion')
            continue
        top = ET.parse(junit).getroot()
        suite = top if top.tag == 'testsuite' else top.find('testsuite')
        ran = int(suite.get('tests', -1)) if suite is not None else -1
        failures = sum(int(s.get('failures', 0)) + int(s.get('errors', 0)) for s in ([suite] if suite is not None else []))
        cases = len(top.findall('.//testcase'))
        print(f'shard {i}: given {len(shard_tests[i])} tests; junit records {ran} ({cases} test cases), '
              f'{failures} failures/errors')
        if ran != len(shard_tests[i]) or cases != len(shard_tests[i]):
            problems.append(f'shard {i}: given {len(shard_tests[i])} tests but junit records {ran} / {cases} test cases')
        if failures:
            problems.append(f'shard {i}: {failures} failures/errors in junit')

    if full_lists:
        reference = next(iter(full_lists.values()))
        for i, lst in full_lists.items():
            if lst != reference:
                problems.append(f'shard {i} saw a different full suite ({len(lst)} vs {len(reference)} tests)')
        seen: dict[str, int] = {}
        for i, lst in shard_tests.items():
            for t in lst:
                if t in seen:
                    problems.append(f'{t} is in shard {seen[t]} AND shard {i}')
                seen[t] = i
        dropped = sorted(set(reference) - set(seen))
        extra = sorted(set(seen) - set(reference))
        if dropped:
            problems.append(f'{len(dropped)} tests of the full suite ran in no shard, e.g. {dropped[:3]}')
        if extra:
            problems.append(f'{len(extra)} tests ran that the full suite does not list, e.g. {extra[:3]}')
        print(f'full suite: {len(reference)} tests; union of {total} shards: {len(seen)} distinct tests')

    if problems:
        for p in problems:
            print(f'::error::{p}')
        sys.exit(1)
    print('backend verdict: every test of the full suite ran exactly once, and passed')


if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else ''
    if cmd == 'plan' and len(sys.argv) == 5:
        plan(int(sys.argv[2]), int(sys.argv[3]), Path(sys.argv[4]))
    elif cmd == 'verdict' and len(sys.argv) == 4:
        verdict(int(sys.argv[2]), Path(sys.argv[3]))
    else:
        sys.exit(__doc__)
