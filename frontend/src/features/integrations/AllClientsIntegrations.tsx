import { useEffect, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Check } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { FitPage } from '@/components/fit/FitPage'
import { Pager } from '@/components/fit/Pager'
import { useFitRows } from '@/components/fit/useFitRows'
import { Skeleton } from '@/components/ui/States'
import { ProviderMark } from '@/components/ProviderMark'
import { providerLabel } from '@/features/campaigns/labels'
import { clients as countedClients } from '@/lib/counted'
import { sortByPlatform } from '@/lib/platforms'
import { useUi } from '@/stores/ui'
import { accountHealthLabel } from './accountHealth'
import { fetchClientsIntegrationOverview, type ClientIntegrationRow } from './api'
import { dataAge, syncFailureLabel } from './syncFailureLabel'

/**
 * INTEGRATIONS-ALL-CLIENTS-001 — every client the agency reaches, at once, with a «يحتاج إجراء» filter.
 *
 * One read (`/integrations/clients-overview`); a row says how many sources the client has, which platforms, and — when something
 * needs a hand — which platform and why, in the words of the sync's own record. Opening a row makes that client the working one.
 */
/** One row is a single 56px line: the name, the platforms, the state, and the issues said in one truncated sentence. */
const ROW_HEIGHT = 60

export function AllClientsIntegrations({ onPick, viewSwitch }: { onPick: (clientId: string) => void; viewSwitch?: ReactNode }) {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const [onlyAction, setOnlyAction] = useState(false)
  const q = useQuery({ queryKey: ['integrations-clients-overview'], queryFn: fetchClientsIntegrationOverview, retry: false })
  const rows = q.data ?? []
  const needs = rows.filter((r) => r.needs_action).length
  const shown = onlyAction ? rows.filter((r) => r.needs_action) : rows
  /* AGENCY-FIT-001 — a page of whole rows that fit the screen; the rest is a page away. A phone is not locked and shows them all. */
  const fit = useFitRows({ rowHeight: ROW_HEIGHT, reserve: 0, min: 3, max: 40, fallback: 1000 })
  const perPage = fit.locked ? fit.rows : 1000
  const [page, setPage] = useState(1)
  const pages = Math.max(1, Math.ceil(shown.length / perPage))
  const current = Math.min(page, pages)
  useEffect(() => { setPage(1) }, [onlyAction])
  const pageRows = shown.slice((current - 1) * perPage, current * perPage)

  return (
    <FitPage
      testid="all-clients-integrations"
      dir={ar ? 'rtl' : 'ltr'}
      introTestid="integrations-intro"
      title={ar ? 'التكاملات' : 'Integrations'}
      info={ar ? 'كل عميل تصل إليه، وحالة مصادره — افتح عميلًا ليصبح هو العميل الحالي.' : 'Every client you reach, and the state of their sources — open one to make it the working client.'}
      actions={viewSwitch}
      toolbar={
        <div className="flex flex-wrap items-center gap-2" dir={ar ? 'rtl' : 'ltr'}>
          <button
            type="button"
            aria-pressed={onlyAction}
            onClick={() => setOnlyAction((v) => !v)}
            data-testid="all-clients-filter-action"
            className={`rounded-full border px-3 py-1 text-sm font-semibold transition-colors ${onlyAction ? 'border-warning bg-warning/10 text-warning' : 'border-border bg-surface text-text-secondary hover:bg-surface-hover'}`}
          >
            {ar ? 'يحتاج إجراء' : 'Needs action'} · <span className="tnum" data-testid="all-clients-needs-count">{needs}</span>
          </button>
          <span className="text-xs text-text-muted" data-testid="all-clients-total">{countedClients(rows.length, locale)}</span>
        </div>
      }
    >
      <div className="flex min-h-0 flex-1 flex-col" dir={ar ? 'rtl' : 'ltr'}>
        {q.isLoading ? <Skeleton className="h-32 w-full" /> : q.isError ? (
          <p role="alert" className="text-sm font-semibold text-danger">{ar ? 'تعذّر تحميل العملاء' : 'Could not load the clients'}</p>
        ) : shown.length === 0 ? (
          <p className="rounded-2xl border border-dashed border-border px-6 py-8 text-center text-xs text-text-secondary" data-testid="all-clients-empty">
            {onlyAction ? (ar ? 'لا يوجد عميل يحتاج إجراء' : 'No client needs action') : (ar ? 'لا عملاء' : 'No clients')}
          </p>
        ) : (
          <>
            <div ref={fit.ref as never} className="min-h-0 flex-1">
              <ul className="flex flex-col gap-2">
                {pageRows.map((r) => <Row key={r.client_id} row={r} ar={ar} locale={locale} onPick={onPick} />)}
              </ul>
            </div>
            {fit.locked && <Pager testid="all-clients-pager" page={current} pages={pages} onPage={setPage} total={shown.length} ar={ar} hideWhenSingle />}
          </>
        )}
      </div>
    </FitPage>
  )
}

function reasonText(i: ClientIntegrationRow['issues'][number], ar: boolean): string {
  switch (i.reason) {
    case 'failed': return syncFailureLabel(i.error_category, ar)
    case 'access_lost': return accountHealthLabel('access_lost', ar)
    case 'never': return ar ? 'لم تتزامن بعد' : 'Never synced'
    default: return `${ar ? 'آخر مزامنة ناجحة' : 'Last good sync'} ${dataAge(i.last_synced_at, ar)}`
  }
}

function Row({ row, ar, locale, onPick }: { row: ClientIntegrationRow; ar: boolean; locale: 'ar' | 'en'; onPick: (id: string) => void }) {
  const issues = row.issues.map((i) => `${providerLabel(i.provider, locale)}${i.account ? ` · ${i.account}` : ''}: ${reasonText(i, ar)}`)

  return (
    <li className="min-w-0" data-testid={`all-clients-row-${row.client_id}`} data-needs-action={row.needs_action ? 'true' : 'false'}>
      <button type="button" onClick={() => onPick(row.client_id)} title={issues.join('\n')} className="flex h-[3.25rem] w-full min-w-0 items-center gap-3 rounded-2xl border border-border bg-surface px-4 text-start transition-colors hover:bg-surface-hover" data-testid={`all-clients-open-${row.client_id}`}>
        <span className="flex min-w-0 flex-1 flex-col justify-center">
          <span className="truncate text-sm font-bold leading-tight text-text-primary">{row.client_name}</span>
          {/* The issues, said once and cut at the edge: the whole list is the row's tooltip. */}
          {issues.length > 0 && <span className="truncate text-xs leading-tight text-text-secondary">{issues.join(' — ')}</span>}
        </span>
        <span className="flex shrink-0 items-center gap-1">{sortByPlatform(row.platforms, (k) => k).map((k) => <ProviderMark key={k} provider={k} size={16} />)}</span>
        {row.sources === 0 ? (
          <Badge tone="neutral">{ar ? 'لا مصادر' : 'No sources'}</Badge>
        ) : row.needs_action ? (
          <Badge tone="warning" data-testid={`all-clients-badge-${row.client_id}`}>{ar ? 'يحتاج إجراء' : 'Needs action'}</Badge>
        ) : (
          <Badge tone="success" data-testid={`all-clients-badge-${row.client_id}`}><Check size={12} aria-hidden /> {ar ? 'سليم' : 'Healthy'}</Badge>
        )}
      </button>
    </li>
  )
}
