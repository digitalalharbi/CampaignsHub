import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { fetchFollowUpWorkspace, type FollowUpSummary } from './api'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { PageIntro } from '@/components/ui/PageIntro'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { Select } from '@/components/ui/Select'
import { EmptyState } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

/**
 * LEAD-OPERATIONS-001 — the screen the follow-up figures were computed for.
 *
 * `FollowUpWorkspace` shipped, was tested, and was called by nobody: the endpoint has been serving
 * unassigned, overdue, never-contacted, the rates and the median first response since it landed, and
 * the only reader was the daily digest. A manager who wanted the same answer inside the product had
 * to open an email.
 *
 * ## What needs a person comes first
 *
 * Three figures on this screen are work — leads with no owner, follow-ups past their date, people
 * nobody has called. The other twelve describe the period. Presenting them as one grid of numbers is
 * how a dashboard becomes wallpaper, so the three sit at the top, in their own block, and are the
 * only things on the page coloured as a warning.
 *
 * ## Two figures that mean less than they look
 *
 * **A rate whose denominator was zero is absent, never «0%».** «0% contacted» out of no leads is a
 * verdict on nothing, and a manager who reads it as a verdict acts on it.
 *
 * **Overdue is not scoped to the window.** A promise made three weeks ago is overdue today, and a
 * reader filtering to «this week» must not thereby stop seeing it. The payload states which scope it
 * used and this screen prints that statement rather than leaving a reader to assume the two agree.
 */
const RANGES = [7, 30, 90] as const

export function FollowUpWorkspacePage() {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const [days, setDays] = useState<number>(30)

  const range = useMemo(() => {
    const to = new Date()
    const from = new Date(to)
    from.setDate(from.getDate() - (days - 1))

    return { from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) }
  }, [days])

  const query = useQuery({
    queryKey: ['leads', 'workspace', range.from, range.to],
    queryFn: () => fetchFollowUpWorkspace(range),
  })

  const summary = query.data?.summary
  const owners = query.data?.by_owner ?? null

  /** A count, always. Zero is a measurement here — no lead arrived is a real answer. */
  const count = (n: number): string => n.toLocaleString('en-US')

  /** A rate, or nothing at all. Null means nobody could measure it, which is not zero. */
  const rate = (n: number | null | undefined): string =>
    n === null || n === undefined ? '—' : `${Math.round(n * 100)}%`

  const attention = summary
    ? [
        summary.unassigned > 0 && {
          key: 'unassigned',
          label: ar ? 'بلا مسؤول' : 'No owner',
          value: summary.unassigned,
        },
        summary.overdue > 0 && {
          key: 'overdue',
          label: ar ? 'متابعة متأخرة' : 'Overdue follow-up',
          value: summary.overdue,
        },
        summary.not_contacted > 0 && {
          key: 'not_contacted',
          label: ar ? 'لم يُتواصل معه' : 'Never contacted',
          value: summary.not_contacted,
        },
      ].filter((x): x is { key: string; label: string; value: number } => x !== false)
    : []

  const figures: Array<{ key: keyof FollowUpSummary | 'first_response'; label: string; value: string }> = summary
    ? [
        { key: 'received', label: ar ? 'وصل' : 'Received', value: count(summary.received) },
        { key: 'contacted', label: ar ? 'تم التواصل' : 'Contacted', value: count(summary.contacted) },
        { key: 'qualified', label: ar ? 'مؤهَّل' : 'Qualified', value: count(summary.qualified) },
        { key: 'appointments', label: ar ? 'مواعيد' : 'Appointments', value: count(summary.appointments) },
        { key: 'won', label: ar ? 'مكسوب' : 'Won', value: count(summary.won) },
        { key: 'lost', label: ar ? 'مفقود' : 'Lost', value: count(summary.lost) },
        { key: 'contact_rate', label: ar ? 'نسبة التواصل' : 'Contact rate', value: rate(summary.contact_rate) },
        {
          key: 'qualification_rate',
          label: ar ? 'نسبة التأهيل' : 'Qualification rate',
          value: rate(summary.qualification_rate),
        },
        { key: 'win_rate', label: ar ? 'نسبة الكسب' : 'Win rate', value: rate(summary.win_rate) },
        {
          key: 'first_response',
          label: ar ? 'وسيط زمن أول رد' : 'Median first response',
          value:
            summary.first_response.median_minutes === null
              ? '—'
              : ar
                ? `${count(summary.first_response.median_minutes)} دقيقة`
                : `${count(summary.first_response.median_minutes)} min`,
        },
      ]
    : []

  return (
    <section className="space-y-5" data-testid="followup-workspace">
      <PageIntro
        testid="followup-intro"
        title={ar ? 'متابعة العملاء المحتملين' : 'Lead follow-up'}
        purpose={
          ar
            ? 'ما حدث بعد وصول العميل المحتمل: من يملكه، ومن تواصل معه، وما تأخّر.'
            : 'What happened after the lead arrived — who owns it, who called it, and what is late.'
        }
      />

      <div className="flex flex-wrap items-end gap-3">
        <label className="flex flex-col gap-1">
          <span className="text-text-secondary text-xs">{ar ? 'الفترة' : 'Period'}</span>
          <Select
            value={String(days)}
            data-testid="followup-range"
            options={RANGES.map((d) => ({
              value: String(d),
              label: ar ? `آخر ${d} يومًا` : `Last ${d} days`,
            }))}
            onChange={(e) => setDays(Number(e.target.value))}
          />
        </label>
      </div>

      {query.isError && (
        <QueryFailure
          error={query.error}
          ar={ar}
          onRetry={() => void query.refetch()}
          fallbackTitle={ar ? 'تعذّر تحميل المتابعة' : 'The follow-up figures could not be loaded'}
          testId="followup-error"
        />
      )}

      {summary && (
        <>
          {attention.length > 0 ? (
            <div
              className="border-warning/40 bg-warning/5 flex flex-wrap gap-6 rounded-lg border p-4"
              data-testid="followup-attention"
            >
              {attention.map((item) => (
                <div key={item.key} data-testid={`followup-attention-${item.key}`}>
                  <div className="text-warning tnum text-2xl font-bold">{count(item.value)}</div>
                  <div className="text-text-secondary text-xs">{item.label}</div>
                </div>
              ))}
            </div>
          ) : (
            /*
             * «Nothing needs you» is a result, and saying it is the difference between a screen a
             * manager trusts and one they assume is broken.
             */
            <p className="text-text-secondary text-sm" data-testid="followup-attention-clear">
              {ar ? 'لا شيء متأخر ولا شيء بلا مسؤول.' : 'Nothing is overdue and nothing is unowned.'}
            </p>
          )}

          <dl
            className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5"
            data-testid="followup-figures"
          >
            {figures.map((f) => (
              <div key={String(f.key)} data-testid={`followup-figure-${String(f.key)}`}>
                <dt className="text-text-secondary text-xs">{f.label}</dt>
                <dd className="tnum text-text-primary text-lg font-semibold">{f.value}</dd>
              </div>
            ))}
          </dl>

          {/*
            The scope statement, printed rather than assumed — LEAD-OPERATIONS-001.

            Overdue is asked of the whole open pipeline while everything beside it is asked of the
            window. A reader who assumes the two agree will read «3 overdue» as three from this week.
          */}
          <p className="text-text-muted text-xs" data-testid="followup-scope">
            {summary.overdue_scope === 'all_open'
              ? ar
                ? 'المتأخر محسوب على كامل المسار المفتوح، لا على الفترة المختارة وحدها.'
                : 'Overdue counts the whole open pipeline, not just the selected period.'
              : ar
                ? 'المتأخر محسوب على الفترة المختارة.'
                : 'Overdue counts the selected period.'}
          </p>

          {owners !== null && owners.length > 0 && (
            <div data-testid="followup-owners">
              <h2 className="text-text-primary mb-2 text-sm font-semibold">
                {ar ? 'حسب المسؤول' : 'By owner'}
              </h2>
              <MetricTable
                head={[
                  ar ? 'المسؤول' : 'Owner',
                  ar ? 'وصل' : 'Received',
                  ar ? 'تم التواصل' : 'Contacted',
                  ar ? 'متأخر' : 'Overdue',
                  ar ? 'نسبة التواصل' : 'Contact rate',
                ]}
                rows={owners.map((row) => [
                  <span key="n" className="font-semibold">
                    {row.owner_name ?? (ar ? 'بلا مسؤول' : 'Unassigned')}
                  </span>,
                  <span key="r" dir="ltr">{count(row.received)}</span>,
                  <span key="c" dir="ltr">{count(row.contacted)}</span>,
                  <span key="o" dir="ltr" className={row.overdue > 0 ? 'text-warning' : undefined}>
                    {count(row.overdue)}
                  </span>,
                  <span key="cr" dir="ltr">{rate(row.contact_rate)}</span>,
                ])}
                values={owners.map((row): SortValues => [
                  row.owner_name ?? '',
                  row.received,
                  row.contacted,
                  row.overdue,
                  // A rate nobody could measure does not order the table either.
                  row.contact_rate,
                ])}
                initialSort={{ column: 3, dir: 'desc' }}
              />
            </div>
          )}
        </>
      )}

      {!query.isLoading && !query.isError && summary && summary.received === 0 && summary.overdue === 0 && (
        <EmptyState
          title={ar ? 'لا عملاء محتملون في هذه الفترة' : 'No leads in this period'}
          description={
            ar
              ? 'لم يصل أي عميل محتمل خلال الفترة المختارة، ولا توجد متابعات متأخرة.'
              : 'No lead arrived in the selected period, and nothing is overdue.'
          }
        />
      )}
    </section>
  )
}
