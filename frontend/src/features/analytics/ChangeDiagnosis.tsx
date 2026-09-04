import { useMemo } from 'react'
import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react'
import { providerLabel } from '@/features/campaigns/labels'
import { canonicalPlatform } from '@/lib/platforms'
import { compact, money, num } from '@/features/analytics/format'
import { useUi } from '@/stores/ui'
import type { Decomposition, DriverRow, DriversPayload } from './api'

/**
 * ANALYTICS-DIFFERENTIATION-001 — the block that makes Analytics a different product.
 *
 * ## What this is for
 *
 * The dashboard answers «what is happening»: spend is 48.4K, up 14%. Analytics answers «why», and
 * why is a question about CONTRIBUTION — which of the things underneath moved, by how much, and in
 * which direction. That is arithmetic a reader cannot do from a total, and it is the one thing a
 * longer date range on the same cards will never provide.
 *
 * So this is deliberately NOT a row of KPI cards. Its shape is the argument:
 *
 *   - a WIDE card for the decomposition, because a comparison needs room for a bar, a figure and a
 *     name on one line, and because the reader is meant to read down it rather than across a row;
 *   - SQUARE cards for the focused signals beside it — what moved most, what moved against the
 *     account, what the product could not measure — because each is one number with one meaning;
 *   - a WIDE card for the timeline, because dates are read in sequence.
 *
 * ## Every block declines when its evidence is absent
 *
 * That is the requirement's acceptance criterion and it is most of the code here. A diagnostic
 * surface that always produces a finding teaches its reader to ignore it, so each card states the
 * server's own reason — «this metric has no parts that add to it», «there is no previous period»,
 * «no day departed from its own baseline» — rather than rendering an empty frame or, worse, a
 * plausible-looking list of zeros.
 */

/** The server's refusals, in the reader's language. Each names the ABSENCE, never apologises. */
const REASON: Record<string, { ar: string; en: string }> = {
  metric_is_not_additive: {
    ar: 'هذا مؤشر نسبي — لا تُجمع أجزاؤه. تكلفة النتيجة لحملة وأخرى لا تُساويان تكلفة الحساب، فلا معنى لنصيب كل منصة منها.',
    en: 'This is a ratio — its parts do not add up. One campaign’s cost per result and another’s do not sum to the account’s, so a share of it would mean nothing.',
  },
  no_previous_period: {
    ar: 'لا توجد فترة سابقة تُقاس عليها هذه الفترة، فلا يوجد تغيّر ليُفسَّر.',
    en: 'There is no previous period for this one to be measured against, so there is no change to explain.',
  },
  no_entity_reported_this_metric: {
    ar: 'لم تُبلِّغ أي منصة عن هذا المؤشر في الفترتين.',
    en: 'No platform reported this metric in either period.',
  },
  no_day_departed_from_its_own_baseline: {
    ar: 'لم يخرج أي يوم عن سلوك الفترة نفسها — لا يوجد ما يستدعي التحقيق.',
    en: 'No day departed from the period’s own behaviour — there is nothing here to investigate.',
  },
  window_too_short_to_have_a_baseline: {
    ar: 'الفترة أقصر من أن يكون لها خط أساس تُقاس عليه أيامها.',
    en: 'The window is too short for its days to have a baseline to be measured against.',
  },
}

function reasonText(reason: string | null, ar: boolean): string | null {
  if (reason === null) return null
  const r = REASON[reason]

  return r ? (ar ? r.ar : r.en) : reason
}

/** A metric's own unit, so a driver reads «+2,000 SAR» rather than a bare number. */
function formatFor(metric: string, currency: string | null): (n: number) => string {
  return metric === 'spend' || metric === 'revenue'
    ? (n: number) => money(n, currency ?? undefined)
    : (n: number) => num(n)
}

/** The platform's name, or the campaign's own — a driver is named by what it is. */
function driverName(d: DriverRow, by: string, ar: boolean): string {
  if (by === 'provider') return providerLabel(canonicalPlatform(d.key), ar ? 'ar' : 'en')

  return d.name ?? (ar ? 'غير معروف' : 'Unknown')
}

const METRIC_LABEL: Record<string, { ar: string; en: string }> = {
  spend: { ar: 'الإنفاق', en: 'Spend' },
  conversions: { ar: 'النتائج', en: 'Results' },
  clicks: { ar: 'النقرات', en: 'Clicks' },
  impressions: { ar: 'الظهور', en: 'Impressions' },
  revenue: { ar: 'الإيرادات', en: 'Revenue' },
}

const metricLabel = (m: string, ar: boolean) => (METRIC_LABEL[m] ? (ar ? METRIC_LABEL[m].ar : METRIC_LABEL[m].en) : m)

/** A card that says why it is empty instead of being empty. */
function Declined({ text, testid }: { text: string; testid: string }) {
  return (
    <p data-testid={testid} className="py-6 text-center text-sm leading-relaxed text-text-secondary">
      {text}
    </p>
  )
}

/**
 * The decomposition: one row per entity, ordered by how far it moved.
 *
 * The bar is the entity's share of the DISTANCE travelled, so a platform that rose 2,000 while
 * another fell 200 reads as 91% of what happened rather than 111% of the net. Direction is carried
 * by colour AND by an arrow, because a bar length alone cannot say which way.
 */
function Decomposed({ d, currency }: { d: Decomposition; currency: string | null }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const fmt = formatFor(d.metric, currency)
  const declined = reasonText(d.reason, ar)

  if (declined !== null) return <Declined text={declined} testid={`drivers-declined-${d.metric}`} />

  return (
    <div data-testid={`drivers-${d.metric}`} className="flex flex-col gap-2">
      {d.drivers.map((row) => (
        <div key={row.key} className="flex items-center gap-3">
          <span className="w-28 shrink-0 truncate text-sm font-semibold text-text-primary" title={driverName(row, d.by, ar)}>
            {driverName(row, d.by, ar)}
          </span>

          {/* The share of what happened, drawn — the bar is the argument, the figure is the evidence. */}
          <span className="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-secondary">
            <span
              className={`block h-full rounded-full ${row.direction === 'up' ? 'bg-brand-500' : 'bg-warning'}`}
              style={{ width: `${Math.round((row.share ?? 0) * 100)}%` }}
            />
          </span>

          <span
            dir="ltr"
            className={`tnum w-24 shrink-0 text-end text-sm font-bold ${row.direction === 'up' ? 'text-text-primary' : 'text-warning'}`}
          >
            {row.direction === 'up' ? '+' : '−'}
            {fmt(Math.abs(row.change))}
          </span>
        </div>
      ))}

      {/*
        Named, never dropped and never counted as zero — FX-001.

        A platform whose spend awaits an exchange rate did not contribute nothing to the movement, and
        silently excluding it would hand its share to whichever platform happened to be measurable.
      */}
      {d.unquantifiable.length > 0 && (
        <p data-testid="drivers-unquantifiable" className="mt-1 text-xs text-text-muted">
          {ar
            ? `لم تُحتسب ضمن التوزيع: ${d.unquantifiable.join('، ')} — مبالغ بانتظار سعر صرف، وليست صفرًا.`
            : `Not included in the split: ${d.unquantifiable.join(', ')} — amounts awaiting an exchange rate, not zeros.`}
        </p>
      )}
    </div>
  )
}

/** A square card: one signal, one figure, one meaning. */
function Signal({
  label,
  value,
  note,
  tone = 'neutral',
  testid,
}: {
  label: string
  value: string
  note?: string | null
  tone?: 'neutral' | 'up' | 'down'
  testid: string
}) {
  const Icon = tone === 'up' ? ArrowUpRight : tone === 'down' ? ArrowDownRight : Minus

  return (
    <div
      data-testid={testid}
      className="flex aspect-square flex-col justify-between rounded-2xl border border-border bg-surface p-4"
    >
      <span className="text-xs font-semibold leading-snug text-text-secondary">{label}</span>

      <span className="flex items-center gap-1.5">
        <Icon
          size={18}
          className={tone === 'up' ? 'text-success' : tone === 'down' ? 'text-warning' : 'text-text-muted'}
          aria-hidden
        />
        <span dir="ltr" className="tnum text-2xl font-extrabold leading-none text-text-primary">{value}</span>
      </span>

      <span className="min-h-[2.25rem] text-[11px] leading-snug text-text-muted">{note ?? ''}</span>
    </div>
  )
}

export function ChangeDiagnosis({
  data,
  currency,
  loading,
  error,
}: {
  data: DriversPayload | undefined
  currency: string | null
  loading?: boolean
  error?: boolean
}) {
  const ar = useUi((s) => s.locale) === 'ar'

  /*
   * The surface leads with the metric that MOVED, not with a default.
   *
   * «Spend» is the natural first request, but an account whose spend held steady while results
   * halved has a story that spend cannot tell. So the decompositions are ranked by relative movement
   * and the largest leads — which is the difference between a report and a diagnosis.
   */
  const ranked = useMemo(() => {
    if (data === undefined) return []

    return [data.drivers, ...data.also]
      .filter((d) => d.decomposable && d.reason === null && d.drivers.length > 0)
      .sort((a, b) => Math.abs(b.change_pct ?? 0) - Math.abs(a.change_pct ?? 0))
  }, [data])

  if (error) {
    return (
      <div data-testid="change-diagnosis" className="rounded-2xl border border-border bg-surface p-4">
        <Declined
          testid="drivers-error"
          text={ar ? 'تعذّر تحميل تحليل التغيّر.' : 'The change analysis could not be loaded.'}
        />
      </div>
    )
  }

  if (loading || data === undefined) {
    return <div data-testid="change-diagnosis" className="h-64 animate-pulse rounded-2xl border border-border bg-surface" />
  }

  const lead = ranked[0] ?? data.drivers
  const fmt = formatFor(lead.metric, currency)

  const biggest = lead.drivers[0]
  // The entity that moved AGAINST the account is the one a reader has to explain to somebody.
  const against = lead.drivers.find((d) => (lead.change >= 0 ? d.direction === 'down' : d.direction === 'up'))

  return (
    <section data-testid="change-diagnosis" className="grid gap-3 lg:grid-cols-3">
      {/*
        WIDE — the decomposition. Two thirds of the row, because a comparison needs the width and the
        reader is meant to read DOWN it rather than across a row of cards.
      */}
      <div className="rounded-2xl border border-border bg-surface p-4 lg:col-span-2">
        <div className="mb-1 flex flex-wrap items-baseline justify-between gap-2">
          <h3 className="text-base font-bold text-text-primary">
            {ar ? 'ما الذي تغيّر — ومن حرّكه' : 'What changed — and who moved it'}
          </h3>
          <span className="text-xs text-text-muted">
            {ar ? 'مقابل ' : 'vs '}
            {data.previous.from} → {data.previous.to}
          </span>
        </div>

        <p className="mb-3 text-sm text-text-secondary">
          {metricLabel(lead.metric, ar)}{' '}
          <span dir="ltr" className="tnum font-bold text-text-primary">{fmt(lead.current)}</span>
          {lead.change_pct !== null && (
            <>
              {' · '}
              <span dir="ltr" className={`tnum font-bold ${lead.change >= 0 ? 'text-success' : 'text-warning'}`}>
                {lead.change >= 0 ? '+' : '−'}
                {Math.abs(Math.round(lead.change_pct * 1000) / 10)}%
              </span>
            </>
          )}
        </p>

        <Decomposed d={lead} currency={currency} />
      </div>

      {/* SQUARE — the focused signals. One number each, and each one a different question. */}
      <div className="grid grid-cols-2 gap-3">
        <Signal
          testid="signal-biggest-mover"
          label={ar ? 'الأكبر تحريكًا' : 'Biggest mover'}
          value={biggest ? driverName(biggest, lead.by, ar) : '—'}
          tone={biggest?.direction === 'up' ? 'up' : biggest ? 'down' : 'neutral'}
          note={
            biggest
              ? `${biggest.direction === 'up' ? '+' : '−'}${fmt(Math.abs(biggest.change))} · ${Math.round((biggest.share ?? 0) * 100)}%`
              : null
          }
        />

        <Signal
          testid="signal-against"
          label={ar ? 'يتحرّك عكس الحساب' : 'Moving against the account'}
          value={against ? driverName(against, lead.by, ar) : '—'}
          tone={against ? (against.direction === 'up' ? 'up' : 'down') : 'neutral'}
          note={
            against
              ? `${against.direction === 'up' ? '+' : '−'}${fmt(Math.abs(against.change))}`
              : ar
                ? 'كل المنصات تتحرّك في الاتجاه نفسه.'
                : 'Every platform moved the same way.'
          }
        />

        <Signal
          testid="signal-anomalies"
          label={ar ? 'أيام تستدعي التحقيق' : 'Days worth investigating'}
          value={compact(data.timeline.points.length)}
          tone={data.timeline.points.length > 0 ? 'up' : 'neutral'}
          note={data.timeline.reason !== null ? reasonText(data.timeline.reason, ar) : null}
        />

        <Signal
          testid="signal-unmeasurable"
          label={ar ? 'خارج القياس' : 'Outside measurement'}
          value={compact(lead.unquantifiable.length)}
          note={
            lead.unquantifiable.length > 0
              ? lead.unquantifiable.join('، ')
              : ar
                ? 'كل الأرقام قابلة للمقارنة.'
                : 'Every figure is comparable.'
          }
        />
      </div>

      {/*
        WIDE — the timeline. Dates are read in sequence, so it takes the full row.

        Each entry names the day, what it was, what the days BEFORE it had been, and how far apart
        those are. A reader asking «when did this start» has the answer without eyeballing a line.
      */}
      <div className="rounded-2xl border border-border bg-surface p-4 lg:col-span-3">
        <h3 className="mb-1 text-base font-bold text-text-primary">
          {ar ? 'الأيام التي خرجت عن سلوك الفترة' : 'The days that departed from the period’s behaviour'}
        </h3>
        <p className="mb-3 text-xs text-text-muted">
          {ar
            ? 'كل يوم يُقاس على وسيط الأيام التي سبقته وحدها — لا على الفترة كاملة، لأن ما بعد اليوم لم يكن متاحًا لأحد وقتها.'
            : 'Each day is measured against the median of the days before it alone — not the whole window, because what came after was available to nobody at the time.'}
        </p>

        {data.timeline.points.length === 0 ? (
          <Declined
            testid="timeline-declined"
            text={reasonText(data.timeline.reason, ar) ?? (ar ? 'لا شيء لعرضه.' : 'Nothing to show.')}
          />
        ) : (
          <ul data-testid="change-timeline" className="flex flex-col gap-2">
            {data.timeline.points.slice(0, 8).map((p) => (
              <li
                key={`${p.date}-${p.metric}`}
                className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border border-border bg-surface-secondary px-3 py-2 text-sm"
              >
                <span dir="ltr" className="tnum font-semibold text-text-primary">{p.date}</span>
                <span className="text-text-secondary">{metricLabel(p.metric, ar)}</span>
                <span dir="ltr" className={`tnum font-bold ${p.direction === 'up' ? 'text-success' : 'text-warning'}`}>
                  {formatFor(p.metric, currency)(p.value)}
                </span>
                <span className="text-xs text-text-muted">
                  {ar ? 'المعتاد قبله ' : 'usual before it '}
                  <span dir="ltr" className="tnum">{formatFor(p.metric, currency)(p.baseline)}</span>
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  )
}
