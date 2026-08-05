import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ScrollText } from 'lucide-react'
import { fetchAudit, type AuditCategory, type AuditEntry } from './api'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

/**
 * `/admin/audit` — the platform's own trail (ADMIN-001).
 *
 * Spans tenants on purpose. A per-tenant audit view cannot show the thing most worth auditing: an
 * action the platform owner took ACROSS tenants, such as suspending one.
 *
 * Entries are immutable and shown newest first, with the reason as first-class text rather than
 * buried in a diff — the reason is the part a person reads a year later, and an entry without one
 * explains nothing.
 *
 * OPS-002 added the four filters and the names. The trail runs to thousands of rows and `user.login`
 * alone is over half of them, so «show me every subscription change» was a question the page could not
 * answer by scrolling; and an entry that answered «who» with a UUID answered nobody, because the
 * reader had to go and look it up somewhere else, which in practice means they did not.
 */
const CATEGORIES: { key: AuditCategory | ''; ar: string; en: string }[] = [
  { key: '', ar: 'الكل', en: 'All' },
  { key: 'subscriptions', ar: 'الاشتراكات', en: 'Subscriptions' },
  { key: 'payments', ar: 'المدفوعات', en: 'Payments' },
  { key: 'approvals', ar: 'الموافقات', en: 'Approvals' },
  { key: 'permissions', ar: 'الصلاحيات', en: 'Permissions' },
]

export function AuditPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const [category, setCategory] = useState<AuditCategory | ''>('')
  const query = useQuery({ queryKey: ['admin', 'audit', category], queryFn: () => fetchAudit(category) })

  return (
    <div className="w-full">
      <header className="mb-5">
        <h1 className="font-heading text-3xl font-extrabold tracking-tight text-text-primary">
          {ar ? 'السجلات والتدقيق' : 'Logs & audit'}
        </h1>
        <p className="mt-1 text-sm text-text-secondary">
          {ar
            ? 'ما جرى على مستوى المنصة، عبر جميع المستأجرين — الأحدث أولًا.'
            : 'What happened at platform level, across every tenant — newest first.'}
        </p>
      </header>

      <div data-testid="audit-categories" className="mb-4 flex flex-wrap gap-2">
        {CATEGORIES.map((c) => (
          <button
            key={c.key || 'all'}
            type="button"
            data-testid={`audit-category-${c.key || 'all'}`}
            aria-pressed={category === c.key}
            onClick={() => setCategory(c.key)}
            className={`rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors ${
              category === c.key ? 'bg-brand-primary-soft text-brand-700' : 'text-text-secondary hover:bg-surface-hover'
            }`}
          >
            {ar ? c.ar : c.en}
          </button>
        ))}
      </div>

      {query.isPending && <div className="grid gap-2">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-16" />)}</div>}

      {query.isError && (
        <ErrorState error={query.error} ar={ar} title={ar ? 'تعذّر تحميل السجل.' : 'The audit trail could not be loaded.'} onRetry={() => void query.refetch()} />
      )}

      {query.data && query.data.entries.length === 0 && (
        <p className="rounded-2xl border border-dashed border-border px-4 py-12 text-center text-sm text-text-muted">
          {category === ''
            ? (ar ? 'لا إدخالات بعد.' : 'No entries yet.')
            : (ar ? 'لا إدخالات في هذا التصنيف. لم يحدث شيء منه بعد — وليس أن التصنيف معطّل.'
                  : 'No entries in this category. Nothing of this kind has happened yet — the filter is not broken.')}
        </p>
      )}

      {query.data && query.data.entries.length > 0 && (
        <ul data-testid="audit-entries" className="grid gap-2">
          {query.data.entries.map((e) => <Entry key={e.id} entry={e} ar={ar} />)}
        </ul>
      )}
    </div>
  )
}

function Entry({ entry, ar }: { entry: AuditEntry; ar: boolean }) {
  const change = describe(entry)

  return (
    <li className="rounded-xl border border-border bg-surface px-4 py-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="flex items-center gap-2 text-[13.5px] font-bold text-text-primary">
          <ScrollText size={14} className="shrink-0 text-text-muted" aria-hidden />
          <span dir="ltr">{entry.action}</span>
        </span>
        <span className="tnum text-[12px] text-text-muted" dir="ltr">
          {entry.created_at ? entry.created_at.slice(0, 19).replace('T', ' ') : '—'}
        </span>
      </div>

      {/*
        Who, and in whose workspace. A name where there is one; the row is simply omitted where the
        actor or workspace has since been deleted, rather than filled with «Unknown» — which reads as
        a name and is not one. Unattended lifecycle work has no actor at all, and «النظام» says so.
      */}
      <p className="mt-1 text-[12.5px] text-text-muted">
        <span className="font-semibold text-text-secondary">{entry.user_name ?? (ar ? 'النظام' : 'The system')}</span>
        {entry.tenant_name && <span> · {entry.tenant_name}</span>}
      </p>
      {change && <p className="mt-1 text-[13px] text-text-secondary" dir="ltr">{change}</p>}
      {entry.reason && (
        <p className="mt-1.5 rounded-lg bg-surface-secondary px-3 py-2 text-[13px] text-text-primary">
          {ar ? 'السبب: ' : 'Reason: '}{entry.reason}
        </p>
      )}
    </li>
  )
}

/** "active → suspended", when both sides are known. Never invented from one. */
function describe(entry: AuditEntry): string | null {
  const before = entry.before ?? {}
  const after = entry.after ?? {}
  const keys = [...new Set([...Object.keys(before), ...Object.keys(after)])]

  const parts = keys
    .filter((k) => String(before[k] ?? '') !== String(after[k] ?? ''))
    .map((k) => `${k}: ${String(before[k] ?? '—')} → ${String(after[k] ?? '—')}`)

  return parts.length > 0 ? parts.join(' · ') : null
}
