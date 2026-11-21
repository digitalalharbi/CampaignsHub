import { appendFileSync } from 'node:fs'
import type { Plugin } from 'vite'

/**
 * GATE-WK-ENGINE-FAULT-001 — the preview server's side of a WebKit «internal error» on the entry script.
 *
 * The open question is whether the connection the engine reuses was closed by the SERVER (a keep-alive race against Node's
 * 5 s default) or failed inside the ENGINE. With `E2E_CONN_LOG` naming a file, `vite preview` writes one line per connection:
 * when it opened and closed, how many requests it carried, the last request, and who ended it. Nothing changes otherwise —
 * no timeout is altered, no retry is added — so the next failing navigation can be matched to its connection by time.
 */
export function connectionLog(): Plugin {
  const file = process.env.E2E_CONN_LOG
  let seq = 0

  return {
    name: 'campaignshub-connection-log',
    configurePreviewServer(server) {
      const http = server.httpServer
      if (!file || !http) return
      const line = (s: string) => { try { appendFileSync(file, `${new Date().toISOString()} ${s}\n`) } catch { /* diagnostics never break the server */ } }
      line(`listen keepAliveTimeout=${(http as unknown as { keepAliveTimeout?: number }).keepAliveTimeout ?? 'n/a'}ms headersTimeout=${(http as unknown as { headersTimeout?: number }).headersTimeout ?? 'n/a'}ms`)

      http.on('connection', (socket) => {
        const id = ++seq
        const opened = Date.now()
        const peer = `${socket.remoteAddress ?? '?'}:${socket.remotePort ?? '?'}`
        let requests = 0
        let last = ''
        let endedBy = 'peer'
        socket.on('timeout', () => { endedBy = 'server-timeout' })
        socket.on('error', (e: Error) => { endedBy = `error:${(e as NodeJS.ErrnoException).code ?? e.message}` })
        ;(socket as unknown as { __onReq?: (u: string) => void }).__onReq = (u: string) => { requests += 1; last = u }
        socket.on('close', (hadError: boolean) => {
          line(`conn#${id} ${peer} lived=${Date.now() - opened}ms requests=${requests} last=${last} ended=${hadError ? 'error' : endedBy}`)
        })
      })
      http.on('request', (req) => {
        const s = req.socket as unknown as { __onReq?: (u: string) => void }
        s.__onReq?.(`${req.method} ${(req.url ?? '').slice(0, 80)}`)
      })
    },
  }
}
