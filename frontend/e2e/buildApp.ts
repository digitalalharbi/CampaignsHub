import { execFileSync } from 'node:child_process'
import { readdirSync, statSync } from 'node:fs'

/**
 * GATE-BUILT-APP-001 — build the app BEFORE Playwright starts anything, because nothing later will do.
 *
 * ## Why this is called from the config file and not from `globalSetup`
 *
 * It was in `globalSetup` first, and that is too late. Playwright's own runner builds its startup
 * list as `createRemoveOutputDirsTask()`, then `createPluginSetupTasks(config)`, then
 * `config.globalSetups` — and a `webServer` entry IS a plugin. So the preview servers are started
 * first and `globalSetup` runs after them.
 *
 * In CI that is fatal and says so plainly: `vite preview` came up on :5273, Playwright waited sixty
 * seconds for the URL, and there was no `dist` for it to serve because the build had not run yet.
 * All three gates failed the same way in under three minutes.
 *
 * Locally the same code passed — off a `dist` left behind by an earlier manual build. That is the
 * shape of accidental evidence this file exists to remove: the gate and a local reproduction must
 * serve the same bytes, and they now do because the build happens in the one place that precedes
 * both, whoever invoked Playwright and however.
 */
export function buildApp(): void {
  /*
   * Opt out for a fast local loop, and never set by `npm run gate` or by CI.
   *
   * Re-running one spec against a `dist` from ten minutes ago is a reasonable thing to want; doing
   * it without knowing is not, so it says so on the way past.
   */
  if (process.env.E2E_SKIP_BUILD === '1') {
    process.stdout.write('\n[e2e] E2E_SKIP_BUILD=1 — serving whatever dist/ already holds ON PURPOSE\n')

    return
  }

  if (isCurrent()) {
    return
  }

  const began = Date.now()
  process.stdout.write('\n[e2e] building the app the gate will serve…\n')

  try {
    execFileSync('npm', ['run', 'build'], {
      cwd: new URL('..', import.meta.url).pathname,
      env: process.env,
      stdio: 'inherit',
    })
  } catch {
    // A gate with no app to serve must say so here, not as sixty seconds of «Timed out waiting for
    // config.webServer» followed by a page that rendered nothing.
    throw new Error('[e2e] the frontend build failed — the gate has nothing to serve and is aborted.')
  }

  process.stdout.write(`[e2e] built in ${Math.round((Date.now() - began) / 1000)}s\n`)
}

/** The newest mtime under `path`, skipping nothing: every file `vite build` reads is a source. */
function newestUnder(path: string): number {
  let newest = 0

  try {
    const entry = statSync(path)

    if (!entry.isDirectory()) {
      return entry.mtimeMs
    }

    for (const child of readdirSync(path)) {
      newest = Math.max(newest, newestUnder(`${path}/${child}`))
    }
  } catch {
    // A source that is not there cannot be newer than the build. `index.html` and the lockfile are
    // both listed by name below and both exist; this is for a path that legitimately may not.
    return 0
  }

  return newest
}

/**
 * Whether `dist` already reflects every source it is built from.
 *
 * `src` plus the three files outside it that change what is emitted. `node_modules` is deliberately
 * not walked — reinstalling dependencies is what `package-lock.json` stands for here, and walking a
 * quarter of a million files to ask a question the lockfile already answers would cost more than the
 * build it is trying to avoid.
 */
function isCurrent(): boolean {
  const here = new URL('..', import.meta.url).pathname
  const built = newestUnder(`${here}dist/index.html`)

  if (built === 0) {
    return false
  }

  const newestSource = Math.max(
    newestUnder(`${here}src`),
    newestUnder(`${here}index.html`),
    newestUnder(`${here}vite.config.ts`),
    newestUnder(`${here}package-lock.json`),
  )

  return built > newestSource
}
