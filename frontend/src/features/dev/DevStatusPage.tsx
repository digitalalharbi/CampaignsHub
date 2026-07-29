import { useEffect, useState } from 'react'

/**
 * DEVELOPMENT-ONLY environment status. Polls the dev-only backend endpoint (hard-blocked in production).
 * Shows service liveness while developing. No secrets/tokens are displayed.
 */
type State = 'running' | 'degraded' | 'stopped' | 'awaiting_credentials' | string
interface Svc { state: State; [k: string]: unknown }
interface DevStatus {
  backend: Svc; database: Svc; redis: Svc; reports_queue: Svc; queue_worker: Svc; scheduler: Svc
  storage: Svc; chromium_renderer: Svc; last_migration: string | null; branch: string | null; commit: string | null
}

const DOT: Record<string, string> = {
  running: 'bg-success', degraded: 'bg-warning', stopped: 'bg-danger', awaiting_credentials: 'bg-warning',
}
const LABEL: Record<string, string> = {
  running: 'Running', degraded: 'Degraded', stopped: 'Stopped', awaiting_credentials: 'Awaiting Credentials',
}

interface RequirementBoard {
  available: boolean
  counts?: Record<string, number>
  total?: number
  open?: Array<{ id: string; status: string; title: string }>
}

export function DevStatusPage() {
  const [data, setData] = useState<DevStatus | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [at, setAt] = useState<string>('')

  useEffect(() => {
    let alive = true
    const load = async () => {
      try {
        const r = await fetch('/api/v1/dev/status', { credentials: 'include', headers: { Accept: 'application/json' } })
        if (!r.ok) throw new Error(`HTTP ${r.status}`)
        const j = await r.json()
        if (alive) { setData(j.data as DevStatus); setErr(null); setAt(new Date().toLocaleTimeString()) }
      } catch (e) {
        if (alive) setErr(String(e))
      }
    }
    load()
    const t = setInterval(load, 5000)
    return () => { alive = false; clearInterval(t) }
  }, [])

  const req = (data as unknown as { requirements?: RequirementBoard } | null)?.requirements

  const rows: { key: keyof DevStatus; name: string }[] = [
    { key: 'backend', name: 'Backend API' }, { key: 'database', name: 'Database (PostgreSQL)' },
    { key: 'redis', name: 'Redis' }, { key: 'queue_worker', name: 'Queue Worker' },
    { key: 'reports_queue', name: 'Reports Queue' }, { key: 'scheduler', name: 'Scheduler' },
    { key: 'storage', name: 'Storage' }, { key: 'chromium_renderer', name: 'Chromium PDF Renderer' },
  ]

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-5 p-6" dir="ltr">
      <header className="flex items-baseline justify-between">
        <h1 className="text-2xl font-extrabold tracking-tight text-text-primary">Dev Environment Status</h1>
        <span className="text-xs text-text-tertiary">{at && `updated ${at}`}</span>
      </header>
      {err && <p className="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">dev status unavailable: {err}</p>}
      {!data && !err && <p className="text-sm text-text-secondary">…</p>}
      {data && (
        <>
          <div className="overflow-hidden rounded-2xl border border-border">
            <table className="w-full text-sm">
              <tbody>
                {rows.map(({ key, name }) => {
                  const svc = data[key] as Svc
                  const st = svc?.state ?? 'stopped'
                  return (
                    <tr key={String(key)} className="border-b border-border last:border-0">
                      <td className="px-4 py-2.5 font-semibold text-text-primary">{name}</td>
                      <td className="px-4 py-2.5">
                        <span className="inline-flex items-center gap-2 text-text-secondary">
                          <span className={`h-2.5 w-2.5 rounded-full ${DOT[st] ?? 'bg-border'}`} />
                          {LABEL[st] ?? st}
                        </span>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
          <dl className="grid grid-cols-2 gap-2 text-xs text-text-secondary">
            <div><dt className="font-semibold">Branch</dt><dd>{data.branch ?? '—'}</dd></div>
            <div><dt className="font-semibold">Commit</dt><dd>{data.commit ?? '—'}</dd></div>
            <div className="col-span-2"><dt className="font-semibold">Last migration</dt><dd className="truncate">{data.last_migration ?? '—'}</dd></div>
          </dl>
        </>
      )}
    
      {/* DEVSTATUS-001: the requirement board, parsed from the traceability matrix by the backend so it
          can never drift from the document that governs the work. */}
      {req?.available && (
        <section data-testid="requirement-board" className="mt-6 rounded-2xl border border-border bg-surface p-4">
          <h2 className="font-bold text-text-primary">Requirement board</h2>
          <div className="mt-2 flex flex-wrap gap-1.5">
            {Object.entries(req.counts ?? {}).map(([status, count]) => (
              <span
                key={status}
                className={`rounded-full px-2.5 py-1 text-xs font-semibold ${
                  status === 'VERIFIED' ? 'bg-success/15 text-success'
                    : status.startsWith('BLOCKED') ? 'bg-warning/15 text-warning'
                    : status === 'NOT_STARTED' ? 'bg-surface-secondary text-text-muted'
                    : 'bg-info/15 text-info'
                }`}
              >
                {status} <span className="tnum">{count}</span>
              </span>
            ))}
            <span className="rounded-full bg-surface-secondary px-2.5 py-1 text-xs font-semibold text-text-secondary">
              total <span className="tnum">{req.total}</span>
            </span>
          </div>

          {(req.open ?? []).length === 0 ? (
            <p className="mt-3 text-sm text-success">Every requirement is VERIFIED.</p>
          ) : (
            <ul className="mt-3 space-y-1">
              {(req.open ?? []).map((r) => (
                <li key={r.id} className="flex flex-wrap items-baseline gap-2 border-b border-border py-1.5 last:border-0 text-sm">
                  <span className="font-mono text-xs font-bold text-text-primary">{r.id}</span>
                  <span className={`rounded px-1.5 text-[11px] font-semibold ${r.status.startsWith('BLOCKED') ? 'text-warning' : 'text-info'}`}>{r.status}</span>
                  <span className="min-w-0 flex-1 truncate text-text-secondary">{r.title}</span>
                </li>
              ))}
            </ul>
          )}
        </section>
      )}
</div>
  )
}
