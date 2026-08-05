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
