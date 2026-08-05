/**
 * The one place that says where the gate runs and which database it touches (E2E-ISO-001).
 *
 * Imported by `playwright.config.ts`, by `global-setup.ts` and by the specs' API helpers, so the
 * origin a spec sends as `Origin`/`Referer` cannot drift from the origin Sanctum is told to accept.
 * That pair silently decides whether a request is session-authenticated at all: a mismatch does not
 * error, it returns 401, which reads as an authentication defect and is not one.
 */

/** Deliberately NOT :5173 and :8000 — see `global-setup.ts` for why sharing them defeats isolation. */
export const E2E_FRONTEND_PORT = 5273
export const E2E_BACKEND_PORT = 8100

export const E2E_ORIGIN = `http://localhost:${E2E_FRONTEND_PORT}`
export const E2E_API_TARGET = `http://127.0.0.1:${E2E_BACKEND_PORT}`

/**
 * Handed to `php artisan` — for the prepare step and for the server the gate talks to.
 *
 * Laravel's env repository is immutable, so anything set here wins over `backend/.env` and no
 * `.env.e2e` file has to exist on disk. Nothing secret belongs in this object: it is committed, and
 * every value in it is a local address or a database name.
 */
export const E2E_BACKEND_ENV: Record<string, string> = {
  /*
   * `APP_ENV` deliberately stays `local`.
   *
   * The safety property this unit needs is the DATABASE NAME, and `e2e:prepare` enforces that
   * directly by refusing any database not ending in `_e2e` — it does not rely on the environment
   * name for anything. Meanwhile `ConditionalThrottle` exempts `local` alone, and the whole suite was
   * written against an unthrottled server: at `workers: 1` a three-browser run replays the sign-in
   * and registration endpoints hundreds of times, and turning the limiter on would produce 429s that
   * are an artefact of running the tests rather than a defect in the product. Whether the gate should
   * exercise rate limiting is a real question, but it is a change to what the gate MEANS and belongs
   * in its own unit, not smuggled in beside a database rename.
   */
  APP_ENV: 'local',
  DB_DATABASE: 'mediabuying_e2e',
  APP_URL: E2E_API_TARGET,
  // Sessions, cache and queues all live in Redis and are all keyed by this prefix. Without its own,
  // the gate's queue worker would drain jobs a developer's own stack had queued, and vice versa —
  // isolation of the database alone would leave the two runs sharing everything else.
  REDIS_PREFIX: 'campaignshub-e2e-',
  // Sanctum treats a request as stateful only when its Origin matches this list; the gate's frontend
  // is on 5273, so the default 5173 entry from `.env` would refuse every authenticated call.
  SANCTUM_STATEFUL_DOMAINS: `localhost:${E2E_FRONTEND_PORT},127.0.0.1:${E2E_FRONTEND_PORT}`,
  SESSION_DOMAIN: 'null',
  // The gate walks the paid-signup journey, which needs a provider it can actually settle against.
  SUBSCRIPTION_PROVIDER: 'sandbox',
}
