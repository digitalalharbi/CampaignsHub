import { execFileSync } from 'node:child_process'
import { E2E_BACKEND_ENV } from './env'

/**
 * Give the gate its own database, freshly seeded, before either server starts (E2E-ISO-001).
 *
 * Until this existed the Playwright `webServer` ran `php artisan serve` from `backend/` with no
 * environment of its own, so it read `.env` and served the DEVELOPMENT database. Every three-browser
 * run therefore left a complete registration journey behind — a user, a tenant, its client space, its
 * project — and the residue reached 485 tenants, 791 client spaces, 610 users and 2105 tasks.
 *
 * That never broke the gate, which is why it survived: each spec creates what it needs and asserts on
 * that. What it broke was every LIVE review of a list. The agency client picker rendered 269 options,
 * the tasks page showed 2105 rows, and a genuine client-scope leak sat inside those numbers for weeks
 * because no one could tell a wrong figure from a large one.
 *
 * Isolation here is three things, and all three are needed:
 *
 *   1. **A different database** — `mediabuying_e2e`, reset with `migrate:fresh --seed` on every run,
 *      so a run starts from the seed and nothing accumulates across runs.
 *   2. **Different ports** — :8100 and :5273 rather than :8000 and :5173. `reuseExistingServer` is on
 *      outside CI, so a dev stack left running on the usual ports would otherwise be ADOPTED by the
 *      gate and the isolated database would never be reached. Separate ports make that impossible
 *      rather than merely unlikely.
 *   3. **The environment passed to the server**, not a checked-in env file. Laravel's env repository
 *      is immutable, so a variable already present in the process environment wins over `.env` —
 *      which means the gate needs no `.env.e2e` on disk, and a clean checkout works unchanged.
 */
export default async function globalSetup() {
  /*
   * The app FIRST, because a gate with nothing to serve should say so before it rebuilds a database,
   * and because `E2E_SKIP_RESET` below returns early — a run that reuses the database still needs an
   * app.
   */
  buildTheApp()

  /*
   * The one way to NOT reseed, and it exists for exactly one purpose.
   *
   * Reproducing an order-dependent failure needs a run that starts from the state a previous run
   * left — which is the opposite of what this file is for. It is opt-in through the environment and
   * never set by `npm run gate`, so no ordinary invocation can reach it by accident.
   */
  if (process.env.E2E_SKIP_RESET === '1') {
    process.stdout.write('\n[e2e] E2E_SKIP_RESET=1 — reusing the existing database ON PURPOSE\n')

    return
  }

  const started = Date.now()
  process.stdout.write(`\n[e2e] preparing isolated database ${E2E_BACKEND_ENV.DB_DATABASE}…\n`)

  try {
    execFileSync('php', ['artisan', 'e2e:prepare'], {
      cwd: new URL('../../backend', import.meta.url).pathname,
      env: { ...process.env, ...E2E_BACKEND_ENV },
      stdio: 'inherit',
    })
  } catch {
    // Fail the whole run rather than let it fall through to whatever database happens to answer.
    // A gate that silently ran against development is exactly the failure this unit removes.
    throw new Error(
      `[e2e] could not prepare ${E2E_BACKEND_ENV.DB_DATABASE}. The run is aborted rather than left to ` +
        'fall back on the development database.',
    )
  }

  process.stdout.write(`[e2e] database ready in ${Math.round((Date.now() - started) / 1000)}s\n`)
}

/**
 * GATE-BUILT-APP-001 — the gate serves what production serves, built once before anything starts.
 *
 * Both frontend servers run `vite preview` over this one `dist`, so neither performs an on-demand
 * transform and neither owns a dependency cache the other can invalidate. That removes two whole
 * classes of gate failure at their source: the cold-transform timeout that hit `/agency/tasks` on
 * webkit five times, and GATE-VITE-001's two optimizers rewriting a shared `node_modules/.vite`.
 *
 * Built HERE rather than in each `webServer` command, because two servers each running `npm run
 * build` would race on the same output directory — one writing `dist` while the other serves it is
 * precisely the moving-ground problem this is meant to end.
 */
function buildTheApp(): void {
  /*
   * Opt out for a fast local loop, and never set by `npm run gate` or by CI.
   *
   * Re-running one spec against a `dist` from ten minutes ago is a reasonable thing to want; doing it
   * without knowing is not, so it says so on the way past.
   */
  if (process.env.E2E_SKIP_BUILD === '1') {
    process.stdout.write('[e2e] E2E_SKIP_BUILD=1 — serving whatever dist/ already holds ON PURPOSE\n')

    return
  }

  const began = Date.now()
  process.stdout.write('[e2e] building the app the gate will serve…\n')

  try {
    execFileSync('npm', ['run', 'build'], {
      cwd: new URL('..', import.meta.url).pathname,
      env: process.env,
      stdio: 'inherit',
    })
  } catch {
    // A gate with no app to serve must say so here, not as fifteen «page rendered nothing» failures.
    throw new Error('[e2e] the frontend build failed — the gate has nothing to serve and is aborted.')
  }

  process.stdout.write(`[e2e] built in ${Math.round((Date.now() - began) / 1000)}s\n`)
}
