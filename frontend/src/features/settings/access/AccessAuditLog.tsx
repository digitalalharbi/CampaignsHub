import { useState } from 'react'
import { ChevronDown, History } from 'lucide-react'
import { fmtDate } from '@/lib/datetime'
import { useAccessAudit, type AccessData, type AuditChange } from './accessApi'
import { ACTION_LABEL, CHANGE_FIELD_LABEL } from './labels'

/**
 * ACCESS-SECTIONS-001 — «سجل الصلاحيات»: every permission change in the workspace, compact. One line each — what,
 * whom, by whom, when — and the fields that moved (before → after; lists as + added / − removed). Folded by default
 * so the board stays the first thing on the page.
 */
export function AccessAuditLog({ data, ar }: { data: AccessData; ar: boolean }) {
  const [open, setOpen] = useState(false)
  const q = useAccessAudit()
  const entries = q.data?.entries ?? []
  const names = new Map<string, string>([
    ...(data.sections ?? []).map((s) => [s.key, ar ? s.ar : s.en] as [string, string]),
    ...data.categories.map((c) => [c.slug, ar ? c.name_ar : c.name_en] as [string, string]),
    ...data.clients.map((c) => [c.id, c.name] as [string, string]),
  ])
  const name = (v: unknown) => (v === null || v === undefined || v === '' ? '—' : names.get(String(v)) ?? String(v))

  return (
    <section className="rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]" data-testid="access-audit">
      <button type="button" onClick={() => setOpen((o) => !o)} aria-expanded={open} data-testid="access-audit-toggle"
        className="flex w-full items-center justify-between gap-2 text-start">
        <span className="flex items-center gap-1.5 text-sm font-bold text-text-primary"><History size={14} aria-hidden /> {ar ? 'سجل الصلاحيات' : 'Access log'}</span>
        <span className="flex items-center gap-1 text-xs text-text-muted">
          <span className="tnum">{entries.length.toLocaleString('en-US')}</span>
          <ChevronDown size={14} aria-hidden className={open ? 'rotate-180' : ''} />
        </span>
      </button>
      {open && (
        entries.length === 0
          ? <p className="mt-2 text-xs text-text-muted">{ar ? 'لا تغييرات بعد' : 'No changes yet'}</p>
          : (
            <ul className="mt-2 divide-y divide-border text-xs" data-testid="access-audit-rows">
              {entries.map((e, i) => (
                <li key={i} className="py-1.5">
                  <div className="flex flex-wrap gap-x-2 text-text-secondary">
                    <span className="font-semibold text-text-primary">{ar ? (ACTION_LABEL[e.action]?.ar ?? e.action) : (ACTION_LABEL[e.action]?.en ?? e.action)}</span>
                    {e.target && <span dir="auto">· {e.target}</span>}
                    {e.by && <span>· {ar ? 'بواسطة' : 'by'} {e.by}</span>}
                    <span className="tnum">· {fmtDate(e.at)}</span>
                  </div>
                  {e.changes.length > 0 && (
                    <ul className="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-text-muted">
                      {e.changes.map((c) => <li key={c.field}><Change c={c} ar={ar} name={name} /></li>)}
                    </ul>
                  )}
                </li>
              ))}
            </ul>
          )
      )}
    </section>
  )
}

function Change({ c, ar, name }: { c: AuditChange; ar: boolean; name: (v: unknown) => string }) {
  const label = CHANGE_FIELD_LABEL[c.field] ? (ar ? CHANGE_FIELD_LABEL[c.field].ar : CHANGE_FIELD_LABEL[c.field].en) : c.field
  if (c.added !== undefined || c.removed !== undefined) {
    return (
      <span>
        {label}:
        {(c.added ?? []).map((v) => <span key={`+${v}`} className="ms-1 text-success">+{name(v)}</span>)}
        {(c.removed ?? []).map((v) => <span key={`-${v}`} className="ms-1 text-danger">−{name(v)}</span>)}
      </span>
    )
  }
  return <span>{label}: {name(c.from)} {ar ? '←' : '→'} {name(c.to)}</span>
}
