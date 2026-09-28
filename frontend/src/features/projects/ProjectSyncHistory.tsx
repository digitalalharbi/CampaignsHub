import { useQuery } from '@tanstack/react-query'
import { listSyncRuns, type SyncRun } from './api'
import { Badge } from '@/components/ui/Badge'
import { Num } from '@/components/ui/Num'
import { Card, CardTitle } from '@/components/ui/Card'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { fmtClock } from '@/lib/datetime'
import { useUi } from '@/stores/ui'

/**
 * SYNC-001 — the run log the pipeline has always kept, on the page that asks about it.
 *
 * `SyncRunController::index` has served `projects/{project}/sync-runs` since the pipeline was built:
 * every attempt, its window, what each stage produced, its duration, its error, and a per-status
 * summary computed over the WHOLE filtered set rather than the page shown. Nothing in the frontend
 * read it. The one reference to it anywhere was an `invalidateQueries` call in
 * `PlatformIntegrationsPanel` — an invalidation of a query no component had registered, so pressing
 * «مزامنة الآن» refreshed nothing and the operator learnt only that a request had been accepted.
 *
 * This is the page an operator opens to ask why the figures stopped moving, and the answer was
 * already in the database and already on an endpoint. Registering the query under the SAME key the
 * panel invalidates is what makes «مزامنة الآن» report what it did.
 */

/** A status shows as itself when the product does not know it — never as a guess, never as blank. */
const STATUS: Record<string, { ar: string; en: string; tone: 'success' | 'danger' | 'warning' | 'info' | 'neutral' }> = {
  success: { ar: 'نجحت', en: 'Succeeded', tone: 'success' },
  failed: { ar: 'فشلت', en: 'Failed', tone: 'danger' },
  partial_mapping: { ar: 'مطابقة جزئية', en: 'Partial mapping', tone: 'warning' },
  /* Not an error and not amber: the provider simply had nothing in the window we asked about. */
  no_data: { ar: 'لا بيانات للفترة', en: 'No data for the period', tone: 'neutral' },
  running: { ar: 'قيد التنفيذ', en: 'Running', tone: 'info' },
  /*
   * Not `failed`: nothing broke, and the operator's next move — say which project this account
   * feeds — has nothing in common with the next move for a provider error. `SyncRunStatus` makes
   * exactly this distinction, and these six words are its whole vocabulary.
   */
  awaiting_assignment: { ar: 'بانتظار إسناد الحساب لمشروع', en: 'Awaiting project assignment', tone: 'warning' },
}

function statusLabel(status: string, ar: boolean): string {
  const known = STATUS[status]

  return known ? (ar ? known.ar : known.en) : status
}

/**
 * A stage reports a number or reports nothing. `null` means the run never reached that stage, and
 * «—» is the only honest thing to draw for it: a 0 there reads as «the provider sent no rows», which
 * is a different fact and one this row may not have.
 */
function stage(value: number | null, ar: boolean): { text: string; muted: boolean } {
  return value === null ? { text: ar ? 'لم يُقس' : 'Not measured', muted: true } : { text: String(value), muted: false }
}

function Run({ run, ar }: { run: SyncRun; ar: boolean }) {
  const status = STATUS[run.status]
  const rows = [
    [ar ? 'صفوف المنصة' : 'Provider rows', stage(run.provider_rows, ar)],
    [ar ? 'صفوف مقروءة' : 'Parsed', stage(run.parsed_rows, ar)],
    [ar ? 'مطابقة بحملات' : 'Matched to campaigns', stage(run.mapped_rows, ar)],
    [ar ? 'مؤشرات محفوظة' : 'Metrics stored', { text: String(run.metrics_imported), muted: false }],
  ] as const

  return (
    <li data-testid={`sync-run-${run.id}`} className="rounded-[9px] border border-border p-3">
      <div className="flex flex-wrap items-center gap-2">
        <Badge tone={status?.tone ?? 'neutral'}>{statusLabel(run.status, ar)}</Badge>
        <span className="text-sm font-semibold">{run.account ?? (ar ? 'حساب غير معروف' : 'Unknown account')}</span>
        {run.account_external_id && <span className="tnum text-xs text-text-muted">{run.account_external_id}</span>}
        {run.is_demo && <Badge tone="info">{ar ? 'تجريبي' : 'Demo'}</Badge>}
        {/*
          §8 — a run that answered the same thing every half hour is one row that says how many
          times it said it. Without the count the log would read as a single stale attempt.
        */}
        {run.repeats > 1 && (
          <span data-testid={`sync-run-repeats-${run.id}`} className="text-xs text-text-secondary">
            {ar ? `تكرّر ${run.repeats} مرة` : `Repeated ${run.repeats}×`}
          </span>
        )}
      </div>

      <dl className="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-text-secondary">
        {run.window_start && run.window_end && (
          <div>
            <dt className="inline text-text-muted">{ar ? 'الفترة' : 'Window'}: </dt>
            {/*
              The RUN is isolated, not the cell. `dir` on a `<dd>` re-bases its alignment too, so
              under Arabic the range lands on the opposite edge from «الفترة» — which is what
              `ltrNumeralAlignment` catches by name, and it caught this one.
            */}
            <dd className="inline"><Num className="tnum">{run.window_start} → {run.window_end}</Num></dd>
          </div>
        )}
        {rows.map(([label, value]) => (
          <div key={label}>
            <dt className="inline text-text-muted">{label}: </dt>
            <dd className={`inline ${value.muted ? 'text-text-muted' : 'tnum font-semibold'}`}>{value.text}</dd>
          </div>
        ))}
        {run.duration_seconds !== null && (
          <div>
            <dt className="inline text-text-muted">{ar ? 'المدة' : 'Duration'}: </dt>
            <dd className="tnum inline">{run.duration_seconds}{ar ? ' ث' : 's'}</dd>
          </div>
        )}
        {/* Shown only when it means something: one attempt is the ordinary case, not a fact. */}
        {run.attempts > 1 && (
          <div>
            <dt className="inline text-text-muted">{ar ? 'المحاولات' : 'Attempts'}: </dt>
            <dd className="tnum inline">{run.attempts}</dd>
          </div>
        )}
        {run.started_at && (
          <div>
            <dt className="inline text-text-muted">{ar ? 'بدأت' : 'Started'}: </dt>
            <dd className="tnum inline">{fmtClock(run.started_at)}</dd>
          </div>
        )}
      </dl>

      {/*
        The provider's own sentence, verbatim. Replacing it with a tidy generic one is what leaves an
        operator with «فشلت المزامنة» and nowhere to go — this text is the whole reason the row exists.
      */}
      {run.error && (
        <p
          data-testid={`sync-run-error-${run.id}`}
          /*
            `dir="auto"` because this sentence is the PROVIDER's and is usually English, while the
            page around it is Arabic. Inheriting RTL moved its full stop to the front — «.The
            provider rejected the access token (OAuthException 190)» — seen on the rendered page.
          */
          dir="auto"
          className={`mt-2 text-xs ${run.status === 'failed' ? 'text-danger' : 'text-warning'}`}
        >
          {run.error}
        </p>
      )}
    </li>
  )
}

export function ProjectSyncHistory({ projectId }: { projectId: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const log = useQuery({
    // The key `PlatformIntegrationsPanel` already invalidates after a sync is queued.
    queryKey: ['project', projectId, 'sync-runs'],
    queryFn: () => listSyncRuns(projectId),
    enabled: projectId !== '',
  })

  /* Normalised once, the way the page treats its bindings: a panel may not take the route down. */
  const runs = log.data?.runs ?? []
  const entries = Object.entries(log.data?.summary ?? {}).filter(([, count]) => count > 0)

  return (
    <Card>
      <CardTitle>{ar ? 'سجل المزامنة' : 'Sync history'}</CardTitle>

      {log.isLoading ? (
        <div className="mt-3 space-y-2">
          <Skeleton className="h-8 w-full" />
          <Skeleton className="h-8 w-full" />
        </div>
      ) : log.isError ? (
        <p className="mt-3 text-sm text-text-secondary">
          {ar ? 'تعذّر جلب سجل المزامنة.' : 'The sync log could not be loaded.'}
        </p>
      ) : runs.length === 0 ? (
        <div className="mt-3">
          <EmptyState
            title={ar ? 'لم تُنفَّذ أي مزامنة بعد' : 'No sync has run yet'}
            description={ar
              ? 'كل محاولة مزامنة لحسابات هذا المشروع تُسجَّل هنا — بما فيها المحاولات التي فشلت.'
              : 'Every sync attempt for this project’s accounts is recorded here, including the ones that failed.'}
          />
        </div>
      ) : (
        <>
          <div className="mt-3 flex flex-wrap gap-2">
            {entries.map(([status, count]) => (
              <Badge key={status} tone={STATUS[status]?.tone ?? 'neutral'}>
                {statusLabel(status, ar)}: <span className="tnum">{count}</span>
              </Badge>
            ))}
          </div>

          {/*
            OPS-LEDGER-001 — the cap says so. A log that silently stops at a hundred rows invites the
            reader to treat the oldest row shown as the first run there ever was.
          */}
          {(log.data?.runs_withheld ?? 0) > 0 && (
            <p data-testid="sync-runs-withheld" className="mt-2 text-xs text-text-muted">
              {/*
                ATTEMPTS READ, not rows drawn. The two differ because identical consecutive runs are
                collapsed into one row carrying its own count — 130 attempts came back as 4 rows here
                — so `runs.length` would have said «the most recent 4 of 130» about a response that
                had in fact read a hundred of them.
              */}
              {ar
                ? `يُعرض أحدث ${log.data!.runs_total - log.data!.runs_withheld} من أصل ${log.data!.runs_total} محاولة.`
                : `Showing the most recent ${log.data!.runs_total - log.data!.runs_withheld} of ${log.data!.runs_total} attempts.`}
            </p>
          )}

          <ul className="mt-3 space-y-2">
            {runs.map((run) => <Run key={run.id} run={run} ar={ar} />)}
          </ul>
        </>
      )}
    </Card>
  )
}
