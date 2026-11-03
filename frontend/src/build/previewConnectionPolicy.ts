import { appendFileSync, mkdirSync, writeFileSync } from 'node:fs'
import { dirname } from 'node:path'
import type { Plugin } from 'vite'

/**
 * GATE-WK-ENGINE-FAULT-001 — EXPERIMENT, not a claimed fix.
 *
 * `vite preview` is a plain Node `http.Server`, and Vite sets no keep-alive options on it, so it runs
 * with Node's defaults: `keepAliveTimeout` 5 s, `headersTimeout` 60 s. The WebKit gate leg
 * intermittently fails with «WebKit encountered an internal error» on the app's own entry script.
 * The proposed (UNPROVEN) server-side cause is the classic keep-alive race: the server closes an idle
 * kept-alive socket at 5 s at the same instant the engine reuses it for the next request. This plugin
 * raises both limits above any reuse window the engine uses (65 s / 66 s, the usual «longer than the
 * client and any proxy» pair), and — separately, when `E2E_PREVIEW_CONN_LOG` names a file — records
 * every connection close with its idle time and request count, so the next failure can be matched
 * against it:
 *   - a `close` line with idle ≈ the old 5000 ms right before a `[wk-evidence] FAILED-REQUEST ENTRY`
 *     line → server-side race (the experiment should make those lines disappear);
 *   - no close, no clientError, and the failure persists → engine-side network error; revert this.
 *
 * Preview only. The production origin is nginx, not this server, and `npm run dev` is untouched.
 */
export const PREVIEW_KEEP_ALIVE_TIMEOUT_MS = 65_000
export const PREVIEW_HEADERS_TIMEOUT_MS = 66_000

interface SocketLike {
  once(event: 'close', listener: (hadError: boolean) => void): unknown
}
interface ServerLike {
  keepAliveTimeout: number
  headersTimeout: number
  on(event: string, listener: (...args: never[]) => void): unknown
}

export function formatSocketClose(id: number, hadError: boolean, requests: number, idleMs: number, ageMs: number): string {
  return `${new Date().toISOString()} close socket=${id} hadError=${hadError} requests=${requests} idle_ms=${Math.round(idleMs)} age_ms=${Math.round(ageMs)}`
}

/** Applies the limits and, optionally, the connection log. Exported for the unit test. */
export function applyPreviewConnectionPolicy(server: ServerLike, logPath?: string): void {
  server.keepAliveTimeout = PREVIEW_KEEP_ALIVE_TIMEOUT_MS
  server.headersTimeout = PREVIEW_HEADERS_TIMEOUT_MS
  if (logPath === undefined || logPath === '') return

  mkdirSync(dirname(logPath), { recursive: true })
  const write = (line: string) => appendFileSync(logPath, `${line}\n`)
  writeFileSync(
    logPath,
    `${new Date().toISOString()} start keepAliveTimeout=${server.keepAliveTimeout} headersTimeout=${server.headersTimeout}\n`,
  )

  let nextId = 0
  const requests = new WeakMap<object, number>()
  const lastActive = new WeakMap<object, number>()
  const opened = new WeakMap<object, number>()

  server.on('connection', ((socket: SocketLike & object) => {
    const id = ++nextId
    opened.set(socket, Date.now())
    lastActive.set(socket, Date.now())
    socket.once('close', (hadError: boolean) => {
      const now = Date.now()
      write(formatSocketClose(id, hadError, requests.get(socket) ?? 0, now - (lastActive.get(socket) ?? now), now - (opened.get(socket) ?? now)))
    })
  }) as never)
  server.on('request', ((req: { socket: object }, res: { once(e: 'finish', l: () => void): unknown }) => {
    requests.set(req.socket, (requests.get(req.socket) ?? 0) + 1)
    lastActive.set(req.socket, Date.now())
    res.once('finish', () => lastActive.set(req.socket, Date.now()))
  }) as never)
  server.on('clientError', ((err: { code?: string; message: string }) => {
    write(`${new Date().toISOString()} clientError code=${err.code ?? '-'} message=${JSON.stringify(err.message)}`)
  }) as never)
}

export function previewConnectionPolicy(): Plugin {
  return {
    name: 'campaignshub:preview-connection-policy',
    configurePreviewServer(server) {
      applyPreviewConnectionPolicy(server.httpServer as unknown as ServerLike, process.env.E2E_PREVIEW_CONN_LOG)
    },
  }
}
