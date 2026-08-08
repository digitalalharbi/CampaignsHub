#!/usr/bin/env node
/**
 * The gate — one isolated run per browser (E2E-ISO-002).
 *
 * ## What was wrong with a single invocation
 *
 * `globalSetup` seeds the database ONCE, and `workers: 1` then runs chromium, firefox and webkit
 * against it in sequence. So chromium meets the seed; firefox meets the seed plus everything
 * chromium created; webkit meets both. Three browsers were never running the same suite.
 *
 * That is not a theory about what might go wrong — it is written into the specs. `campaigns.spec.ts`
 * carries a comment explaining that a selector had to be pinned by name because a project «did not
 * exist yet when chromium ran», and the fix is described there as «the fourth time this suite has
 * outgrown a selector that guessed». Four workarounds for one cause.
 *
 * It also explains the shape of every order-dependent failure this repo has recorded: they are
 * firefox or webkit, never chromium, because chromium is the only browser that always runs against a
 * clean database.
 *
 * ## Why separate invocations rather than a reset between projects
 *
 * Resetting mid-run means dropping every table while a live `artisan serve` holds connections to
 * them. Postgres blocks the drop until those connections release, and the failure mode is a hung
 * gate rather than a clean error — the exact deadlock this repo hit earlier today when two suites
 * shared one database.
 *
 * Running Playwright once per project sidesteps that completely: each invocation reseeds BEFORE its
 * own servers start, which is what `globalSetup` was already built to do. Each browser also gets its
 * own browser-server lifecycle and its own Node process, so half an hour of accumulated memory in
 * one project cannot reach the next.
 *
 * ## The verdict
 *
 * Every project must exit 0. The script exits with the first non-zero code it saw, so the caller
 * still reads a real exit code and never a summary somebody wrote by hand.
 */
import { spawnSync } from 'node:child_process'

const PROJECTS = ['chromium', 'firefox', 'webkit']
const results = []

for (const project of PROJECTS) {
  process.stdout.write(`\n${'='.repeat(72)}\n[gate] ${project} — its own database, its own servers, its own browser\n${'='.repeat(72)}\n`)

  const run = spawnSync('npx', ['playwright', 'test', '--project', project, ...process.argv.slice(2)], {
    stdio: 'inherit',
    // Never inherited: a leftover skip would silently hand this project the previous one's data,
    // which is the entire failure this script exists to remove.
    env: { ...process.env, E2E_SKIP_RESET: '0' },
  })

  results.push({ project, code: run.status ?? 1 })
}

process.stdout.write(`\n${'='.repeat(72)}\n[gate] verdict\n${'='.repeat(72)}\n`)
for (const { project, code } of results) {
  process.stdout.write(`  ${code === 0 ? 'PASS' : 'FAIL'}  ${project}  (exit ${code})\n`)
}

const failed = results.find((r) => r.code !== 0)
process.stdout.write(failed ? `\n[gate] FAILED on ${failed.project}\n` : '\n[gate] all three browsers passed\n')
process.exit(failed ? failed.code : 0)
