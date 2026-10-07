import { useProject } from '@/stores/project'
import { useUi, type Locale } from '@/stores/ui'
import { days as countedDays } from '@/lib/counted'
import { Panel } from '@/features/analytics/components'
import { EmptyState } from '@/components/ui/States'
import { StatCard, StatGrid } from '@/components/ui/StatCard'
import { PageIntro } from '@/components/ui/PageIntro'
import { money, moneyExact, num, percent } from '@/features/analytics/format'
import { ChartCard, RatioBars, StatusMixBar } from '@/features/analytics/charts'
import { providerLabel } from '@/features/campaigns/labels'
import { useRemoveSpendLimit, useSpendLimits, type SpendLimitReading } from './spendLimitsApi'
import { NewSpendLimitDialog } from './NewSpendLimitDialog'

/**
 * BUDGET-GOVERNANCE-001 — the workspace's own spend limits, and the sentence that keeps them honest.
 *
 * ## The one thing this page must never let a reader lose
 *
 * Nothing here stops an ad platform from spending. `unified_campaigns.total_budget` is the plan set
 * INSIDE the platform and the platform enforces it; a row on this page is a number an agency set for
 * itself, over a scope no single platform can even see. An operator who believes otherwise will not
 * go and pause the campaigns, and the money keeps going out with a green screen in front of it.
 *
 * So the note sits at the top, in the reader's language, and it comes from the API rather than being
 * written here — a copy in the browser is a copy that can drift from the one every other consumer is
 * told.
 *
 * ## «Unknown» is a state, not a blank
 *
 * Spend withheld for want of an exchange rate, or denominated differently from the limit, produces
 * no comparable figure. This page says so and says why. Showing 0% used would be reporting safety it
 * cannot see, which is the failure the whole feature exists to prevent, arriving through the feature
 * meant to prevent it.
 */
const SCOPE_LABEL: Record<string, { ar: string; en: string }> = {
  project: { ar: 'المشروع كاملًا', en: 'The whole project' },
  platform: { ar: 'منصة', en: 'Platform' },
  account: { ar: 'حساب إعلاني', en: 'Ad account' },
  campaign: { ar: 'حملة', en: 'Campaign' },
}

const STATE_TONE: Record<SpendLimitReading['state'], 'success' | 'warning' | 'danger' | 'neutral'> = {
  ok: 'success',
  approaching: 'warning',
  over: 'danger',
  unknown: 'neutral',
}

const STATE_LABEL: Record<SpendLimitReading['state'], { ar: string; en: string }> = {
  ok: { ar: 'ضمن الحد', en: 'Within the limit' },
  approaching: { ar: 'يقترب من الحد', en: 'Approaching the limit' },
  over: { ar: 'تجاوز الحد', en: 'Over the limit' },
  unknown: { ar: 'لا يمكن القياس', en: 'Cannot be measured' },
}

/** Why a figure is missing — the reason travels with the refusal, never a blank. */
const BASIS: Record<string, { ar: string; en: string }> = {
  partial: {
    ar: 'جزء من الإنفاق لم يُحوَّل لعدم توفر سعر صرف، فلا يوجد رقم واحد يُقارَن بالحد.',
    en: 'Part of the spend could not be converted for want of an exchange rate, so there is no single figure to compare.',
  },
  mixed_currency: {
    ar: 'الإنفاق المحجوب بأكثر من عملة، فلا يمكن جمعه في رقم واحد.',
    en: 'The withheld spend is in more than one currency, so it cannot be added into one figure.',
  },
  currency_mismatch: {
    ar: 'الحد بعملة والإنفاق بأخرى — القسمة بينهما ليست نسبة استهلاك.',
    en: 'The limit is in one currency and the spend in another — dividing them is not a utilisation.',
  },
  absent: { ar: 'لا يوجد إنفاق في هذه الفترة.', en: 'There is no spend in this period.' },
}

const PROJECTION: Record<string, { ar: string; en: string }> = {
  too_early: {
    ar: 'الفترة لم تمضِ بما يكفي لتقدير تاريخ — يوم واحد مضروب في ثلاثين ليس توقعًا.',
    en: 'Too little of the period has passed to state a date — one day multiplied by thirty is not a forecast.',
  },
  no_spend_rate: { ar: 'لا يوجد إنفاق بعد، فلا يوجد معدل يُبنى عليه تاريخ.', en: 'Nothing has been spent yet, so there is no rate to build a date on.' },
  not_within_period: { ar: 'لن يُبلغ الحد قبل نهاية هذه الفترة بالمعدل الحالي.', en: 'The limit will not be reached before this period ends at the current rate.' },
  already_reached: { ar: 'بُلغ الحد بالفعل.', en: 'The limit has already been reached.' },
  no_comparable_spend: { ar: 'لا يوجد رقم إنفاق يمكن قياسه.', en: 'There is no measurable spend figure.' },
}

export function SpendLimitsPage() {
  const projectId = useProject((s) => s.currentProjectId)
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const q = useSpendLimits(projectId)
  const limits = q.data?.limits ?? []
  /*
   * The currency to suggest for a new limit: whatever the existing ones are in, because a project
   * that already watches SAR is not about to set a USD cap, and a limit in a currency the project
   * does not report reads «unknown» by design.
   */
  const suggestedCurrency = limits[0]?.currency ?? 'SAR'

  /*
   * PRODUCT-VISUAL-001 §4 §19 — how many limits, and how many of them are a problem.
   *
   * The page listed every limit as a card and answered «how is this workspace doing» only by making
   * the reader count coloured cards. These four are the same readings the cards carry, counted —
   * and `unknown` is one of them rather than folded into «ok», because a limit whose spend could not
   * be compared is not a limit anybody has been told they are within.
   */
  const counts = {
    total: limits.length,
    ok: limits.filter((l) => l.state === 'ok').length,
    approaching: limits.filter((l) => l.state === 'approaching').length,
    over: limits.filter((l) => l.state === 'over').length,
    unknown: limits.filter((l) => l.state === 'unknown').length,
  }

  return (
    <div className="flex flex-col gap-4">
      <PageIntro
        testid="spend-limits-intro"
        title={ar ? 'حدود الإنفاق' : 'Spend limits'}
        purpose={ar
          ? 'حدود تضعها مساحة العمل لنفسها عبر المنصات — للمراقبة والتنبيه، لا للإيقاف.'
          : 'Limits this workspace sets for itself across platforms — for watching and warning, never for stopping.'}
        /* The page's one action, in the head — where every other surface puts its primary action. */
        actions={<NewSpendLimitDialog projectId={projectId} locale={locale} currency={suggestedCurrency} />}
        kpis={limits.length === 0 ? undefined : (
          <>
            <StatCard label={ar ? 'حدود مفعّلة' : 'Active limits'} value={counts.total.toLocaleString('en-US')} tone="brand" dot testid="spend-limits-kpi-total" />
            <StatCard label={ar ? 'تقترب من الحد' : 'Near the limit'} value={counts.approaching.toLocaleString('en-US')} tone={counts.approaching > 0 ? 'warning' : 'neutral'} dot testid="spend-limits-kpi-approaching" />
            <StatCard label={ar ? 'تجاوزت الحد' : 'Over the limit'} value={counts.over.toLocaleString('en-US')} tone={counts.over > 0 ? 'danger' : 'success'} dot testid="spend-limits-kpi-over" />
            {/*
              «Unknown» is a state and gets a card, not a silence: a limit whose spend could not be
              compared has told nobody they are within it.
            */}
            <StatCard label={ar ? 'غير معروفة' : 'Not comparable'} value={counts.unknown.toLocaleString('en-US')} tone="neutral" dot testid="spend-limits-kpi-unknown" />
          </>
        )}
      />

      {/*
        The same sentence was on screen three times.

        The Arabic dark review of `/app/spend-limits` showed «حدود تضعها مساحة العمل لنفسها عبر
        المنصات — للمراقبة والتنبيه» under the page title, again as this card's description, and the
        enforcement note below it said the same thing a third way. The page holds ONE section, so a
        card header restating the page header is not a heading — it is the page title printed twice.

        What survives is the narrow title, which says what the list IS rather than what the page is
        for, and the enforcement note, which is the API's own wording and the one statement with an
        operational consequence in it.
      */}
      <Panel
        title={ar ? 'الحدود المضبوطة' : 'Limits in place'}
        loading={q.isLoading}
        error={q.isError}
      >
        {/*
          The sentence, first and unmissable, from the API rather than from here.
        */}
        <p
          data-testid="spend-limits-enforcement"
          className="mb-4 rounded-xl border border-warning/40 bg-warning/5 p-3.5 text-sm text-text-secondary"
        >
          {ar ? q.data?.enforcement_note_ar : q.data?.enforcement_note_en}
        </p>

        {limits.length === 0 ? (
          <EmptyState
            title={ar ? 'لا حدود بعد' : 'No limits yet'}
            description={ar
              ? 'ضع حدًا لمشروع أو منصة أو حساب أو حملة، وسنراقب الإنفاق مقابله وننبّهك قبل بلوغه.'
              : 'Set a limit for a project, a platform, an account or a campaign, and we will watch spend against it and warn you before it is reached.'}
          />
        ) : (
          <ul className="flex flex-col gap-3">
            {limits.map((limit) => (
              <LimitCard key={limit.id} limit={limit} ar={ar} locale={locale} projectId={projectId} />
            ))}
          </ul>
        )}
      </Panel>

      {limits.length > 0 && (
        <div className="grid gap-4 lg:grid-cols-[1fr_1.4fr]">
          {/*
            VIZ-BUDGET-001 §2 — the readings come AFTER the limits they are about.

            The owner's rule: «the feature and the service come first on the page, clearly, without
            needing to scroll — and the analytical results after them.» The feature here is the
            limits: setting them, seeing them, changing them. These two charts first sat between the
            head and the list, so a page for MANAGING limits opened on a reading of limits and pushed
            the limits themselves below it.

            VIZ-BUDGET-001 — how much of the workspace is in each state, as one bar.

            The four KPI cards above answer «how many» four times. «How much of what I set is in
            trouble» is a fifth question none of them answers, and it is the one somebody opens this
            page to ask. The total is the denominator rather than the sum of the bands — they do cover
            it, because every limit carries exactly one state, and passing the total is what makes
            that a checked fact rather than an assumption.
          */}
          <ChartCard title={ar ? 'حالة الحدود' : 'How the limits stand'}>
            <StatusMixBar
              testId="limit-states"
              ar={ar}
              label={ar ? 'حدود' : 'Limits'}
              total={limits.length}
              bands={[
                { key: 'ok', label: ar ? 'ضمن الحد' : 'Within the limit', count: counts.ok, tone: 'success' },
                { key: 'approaching', label: ar ? 'تقترب من الحد' : 'Near the limit', count: counts.approaching, tone: 'warning' },
                { key: 'over', label: ar ? 'تجاوزت الحد' : 'Over the limit', count: counts.over, tone: 'danger' },
                /*
                 * Neutral, never green. A limit whose spend could not be compared has told nobody
                 * they are within it, and colouring it as success is the page asserting safety it
                 * has no reading for.
                 */
                { key: 'unknown', label: ar ? 'غير قابلة للمقارنة' : 'Not comparable', count: counts.unknown, tone: 'neutral' },
              ]}
            />
          </ChartCard>

          <LimitPace limits={limits} ar={ar} locale={locale} />
        </div>
      )}
    </div>
  )
}

function LimitCard({
  limit,
  ar,
  locale,
  projectId,
}: {
  limit: SpendLimitReading
  ar: boolean
  locale: 'ar' | 'en'
  projectId: string | null
}) {
  const remove = useRemoveSpendLimit(projectId)
  const scope = SCOPE_LABEL[limit.scope] ?? SCOPE_LABEL.project!
  const name = limit.scope === 'platform' && limit.scope_id
    ? providerLabel(limit.scope_id, locale)
    : (ar ? scope.ar : scope.en)

  return (
    <li data-testid={`spend-limit-${limit.id}`} className="rounded-2xl border border-border bg-surface p-4">
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <span className="text-sm font-bold text-text-primary">{name}</span>
        <span
          data-testid={`spend-limit-${limit.id}-state`}
          className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${
            STATE_TONE[limit.state] === 'danger' ? 'bg-danger/10 text-danger'
              : STATE_TONE[limit.state] === 'warning' ? 'bg-warning/10 text-warning'
                : STATE_TONE[limit.state] === 'success' ? 'bg-success/10 text-success'
                  : 'bg-surface-secondary text-text-secondary'
          }`}
        >
          {ar ? STATE_LABEL[limit.state].ar : STATE_LABEL[limit.state].en}
        </span>
        <span className="text-[11px] text-text-muted" dir="ltr">
          {limit.period.from} → {limit.period.to}
        </span>

        {/*
          «Remove» deactivates: the events written against this limit are its audit trail, and the
          endpoint keeps the row so last quarter's limit still sits beside what was spent against it.
        */}
        <button
          type="button"
          data-testid={`spend-limit-${limit.id}-remove`}
          disabled={remove.isPending}
          onClick={() => remove.mutate(limit.id)}
          className="ms-auto rounded-lg px-2 py-1 text-xs font-semibold text-text-muted transition-colors hover:bg-danger/10 hover:text-danger disabled:opacity-60"
        >
          {ar ? 'إزالة' : 'Remove'}
        </button>
      </div>

      <StatGrid min="9rem">
        <StatCard
          label={ar ? 'الحد' : 'Limit'}
          value={money(limit.amount, limit.currency)}
          exact={moneyExact(limit.amount, limit.currency ?? null)}
          testid={`spend-limit-${limit.id}-amount`}
        />
        <StatCard
          label={ar ? 'المصروف' : 'Consumed'}
          /* Never «0» for a figure nobody could compute — the em dash is the honest answer. */
          value={limit.consumed === null ? '—' : money(limit.consumed, limit.consumed_currency ?? limit.currency)}
          exact={limit.consumed === null ? undefined : moneyExact(limit.consumed, limit.consumed_currency ?? limit.currency ?? null)}
          testid={`spend-limit-${limit.id}-consumed`}
        />
        <StatCard
          label={ar ? 'المتبقي' : 'Remaining'}
          value={limit.remaining === null ? '—' : money(limit.remaining, limit.currency)}
          exact={limit.remaining === null ? undefined : moneyExact(limit.remaining, limit.currency ?? null)}
          tone={limit.remaining !== null && limit.remaining < 0 ? 'danger' : 'neutral'}
        />
        <StatCard
          label={ar ? 'الاستهلاك' : 'Utilisation'}
          value={limit.utilisation === null ? '—' : percent(limit.utilisation, 0)}
          testid={`spend-limit-${limit.id}-utilisation`}
        />
        <StatCard
          label={ar ? 'الإيقاع' : 'Pace'}
          /* >1 is ahead of plan, against the elapsed share of the limit — not against all of it. */
          value={limit.pace === null ? '—' : `${num(limit.pace * 100)}%`}
          hint={ar
            ? `مضى ${limit.elapsed_days} من ${countedDays(limit.period.days, 'ar')}`
            : `${limit.elapsed_days} of ${countedDays(limit.period.days, 'en')} elapsed`}
        />
      </StatGrid>

      {limit.basis !== 'comparable' && BASIS[limit.basis] && (
        <p data-testid={`spend-limit-${limit.id}-basis`} className="mt-3 text-xs text-text-secondary">
          {ar ? BASIS[limit.basis]!.ar : BASIS[limit.basis]!.en}
        </p>
      )}

      <p data-testid={`spend-limit-${limit.id}-projection`} className="mt-2 text-xs text-text-muted">
        {limit.projected_exhaustion.date !== null
          ? (ar
            ? `بالمعدل الحالي يُبلغ الحد في ${limit.projected_exhaustion.date}.`
            : `At the current rate the limit is reached on ${limit.projected_exhaustion.date}.`)
          : (ar
            ? PROJECTION[limit.projected_exhaustion.reason]?.ar ?? ''
            : PROJECTION[limit.projected_exhaustion.reason]?.en ?? '')}
      </p>

      {limit.thresholds.length > 0 && (
        <p className="mt-1 text-[11px] text-text-muted" dir="ltr">
          {(ar ? 'تنبيه عند: ' : 'Warn at: ') + limit.thresholds.map((t) => `${t}%`).join(' · ')}
        </p>
      )}
    </li>
  )
}

/**
 * VIZ-BUDGET-001 — «which of these is running hot», ranked against the plan.
 *
 * Pace is `consumed ÷ expected-by-now`: not how much of the limit is gone — that is `utilisation` —
 * but whether the spend is ahead of the clock, against the ELAPSED share of the limit. A workspace
 * with nine limits is nine cards to scan and nine figures to compare in your head against 1.0; the
 * line is drawn once here and every limit ranked against it, worst first.
 *
 * ## Why these may share one chart across currencies
 *
 * The ratio carries no unit. A limit set in riyals pacing at 1.3 and one set in dollars pacing at 1.3
 * are running hot by the same proportion, and comparing them breaks no money rule because there is no
 * money in the comparison — the same reasoning the client budget pace chart records.
 *
 * ## What it withholds
 *
 * A limit whose spend could not be compared carries `pace: null` — withheld, partial, or recorded in
 * another currency. There is no zero to draw: «pacing at 0» means «spending nothing», which is a claim
 * about a limit whose spend we could not read at all, and it would rank the limits we know least about
 * as the best-behaved ones. They are withheld and counted beneath the chart.
 */
function LimitPace({ limits, ar, locale }: { limits: SpendLimitReading[]; ar: boolean; locale: Locale }) {
  const drawable = limits
    .filter((l) => l.pace !== null && Number.isFinite(l.pace) && (l.pace as number) >= 0)
    .map((l) => ({ id: l.id, label: limitName(l, ar, locale), value: l.pace as number }))
  const withheld = limits.length - drawable.length

  return (
    <ChartCard
      title={ar ? 'سرعة الإنفاق مقابل الخطة' : 'Spending against the plan'}
      subtitle={ar
        ? 'المصروف ÷ المتوقَّع حتى الآن. الخط عند 1.0 هو إيقاع الفترة، وما فوقه أسرع منها.'
        : 'Spent ÷ expected by now. The line at 1.0 is the period’s own pace; past it is faster than that.'}
    >
      <RatioBars
        testId="limit-pace"
        ar={ar}
        rows={drawable}
        reference={1}
        referenceLabel={ar ? 'على الخطة' : 'On plan'}
      />
      {withheld > 0 && (
        <p data-testid="limit-pace-withheld" className="mt-3 text-sm text-text-muted">
          {ar
            ? `${withheld.toLocaleString('en-US')} من الحدود بلا سرعة قابلة للمقارنة — إنفاقها جزئي أو محجوب أو بعملة أخرى.`
            : `${withheld.toLocaleString('en-US')} limit(s) have no comparable pace — their spend is partial, withheld, or recorded in another currency.`}
        </p>
      )}
    </ChartCard>
  )
}

/** The same name the limit's own card carries, so the chart and the list name one thing once. */
function limitName(limit: SpendLimitReading, ar: boolean, locale: Locale): string {
  const scope = SCOPE_LABEL[limit.scope] ?? SCOPE_LABEL.project!

  return limit.scope === 'platform' && limit.scope_id
    ? providerLabel(limit.scope_id, locale)
    : (ar ? scope.ar : scope.en)
}
