import { useMemo, useState } from 'react'
import { canonicalPlatform } from '@/lib/platforms'
import { readMoney, type MoneyTotals } from '@/lib/money/contract'
import { providerLabel } from '@/features/campaigns/labels'
import { campaignResult, type ResultFamily } from '@/features/campaigns/campaignResult'
import { canonicalObjectiveLabel } from '@/features/campaigns/canonicalObjectives'
import { relevanceOf, type CampaignRelevance } from '@/features/campaigns/campaignRelevance'
import { ChartCard, PlatformDonutChart, ProgressRing, RankingBarChart, RatioBars, SpendEfficiencyScatter, StatusMixBar, type MixTone } from './charts'
import { compact, money, percent, ratio } from './format'
import type { AccountRow, BudgetRow, CampaignRow, EntityRow, PlatformBudgetRow } from './api'

/**
 * ANALYTICS-VISUAL-DECKS-001 — Owner 2026-11-18: «حوّل كل قسم في التحليلات إلى رسوم بيانية ومقارنات،
 * بنفس فكرة النظرة العامة والتقرير اللحظي».
 *
 * Every drill-down tab opened on a table. The overview and the live client report open on the SHAPE of
 * the period — donuts, ranked bars, rings — and the rows come after, as the thing the reader checks the
 * shape against. These decks give the other tabs the same order: picture first, table second.
 *
 * ## What they may and may not do
 *
 * - **No new request.** Each deck draws the rows its tab already holds, so a deck can never disagree with
 *   the table beneath it (REPORT-DETAIL-PARITY-001: one number, many surfaces).
 * - **Money through the contract.** A share needs every part. A row whose spend cannot be stated in the
 *   reporting currency makes a money chart DECLINE with the count, never divide by an incomplete total.
 * - **Missing is never zero.** A row with no reported value is left off a chart and counted, never drawn
 *   as an empty bar or a point in the «cheap» corner.
 * - **Results are compared inside one objective only.** A lead and a purchase are not the same unit, so
 *   cost per result is plotted for one result family at a time, chosen by the reader.
 */

type Ar = { ar: boolean }

function DeckGrid({ children, testid }: { children: React.ReactNode; testid: string }) {
  return <div data-testid={testid} className="grid min-w-0 gap-4 lg:grid-cols-2">{children}</div>
}

function Declined({ text, testid }: { text: string; testid: string }) {
  return (
    <p data-testid={testid} className="flex min-h-[160px] items-center justify-center rounded-xl border border-dashed border-border px-4 text-center text-sm text-text-muted">
      {text}
    </p>
  )
}

function ExcludedNote({ count, ar, testid, why }: { count: number; ar: boolean; testid: string; why?: { ar: string; en: string } }) {
  if (count <= 0) return null
  const reason = why ?? { ar: 'لم تُبلّغ المنصة القيمة اللازمة.', en: 'the platform did not report the value it needs.' }
  return (
    <p data-testid={testid} className="mt-2 text-xs text-text-muted">
      {ar ? `${count} خارج الرسم: ${reason.ar}` : `${count} left off the chart: ${reason.en}`}
    </p>
  )
}

/**
 * Spend per row through the money contract. `declined` is the count of rows holding real money that
 * cannot be stated in one unit — any of them and a share is not drawable.
 */
export function readSpends<T>(rows: T[], totals: (r: T) => MoneyTotals, currency: string | null, ar: boolean) {
  let declined = 0
  const read = rows.map((r) => {
    const m = readMoney(totals(r), 'spend', currency, ar)
    if (m.kind === 'withheld' || m.kind === 'unavailable') declined += 1
    return { row: r, amount: m.kind === 'converted' || m.kind === 'zero' ? m.amount : null }
  })
  return { read, declined }
}

function declinedText(count: number, ar: boolean): string {
  return ar
    ? `لا يمكن رسم الحصص: إنفاق ${count} لم يُحوَّل إلى عملة التقرير، والحصة دونه ستكون أكبر من حقيقتها.`
    : `Shares cannot be drawn: spend on ${count} could not be stated in the report's currency, and shares without it would be too large.`
}

function groupSum<T>(items: Array<{ row: T; amount: number | null }>, keyOf: (r: T) => string) {
  const map = new Map<string, number>()
  for (const i of items) {
    if (i.amount === null || i.amount <= 0) continue
    const k = keyOf(i.row)
    map.set(k, (map.get(k) ?? 0) + i.amount)
  }
  return [...map.entries()].sort((a, b) => b[1] - a[1])
}

/* ───────────────────────────── Campaigns ───────────────────────────── */

const RELEVANCE_TONE: Record<CampaignRelevance, MixTone> = { serving: 'success', idle: 'warning', stopped: 'neutral' }
const RELEVANCE_LABEL: Record<CampaignRelevance, { ar: string; en: string }> = {
  serving: { ar: 'تعمل', en: 'Serving' },
  idle: { ar: 'متوقفة عن الصرف', en: 'Idle' },
  stopped: { ar: 'موقوفة', en: 'Stopped' },
}

const FAMILY_WORDS: Partial<Record<ResultFamily, { ar: string; en: string }>> = {
  sales: { ar: 'المبيعات', en: 'Sales' },
  leads: { ar: 'العملاء المحتملون', en: 'Leads' },
  traffic: { ar: 'الزيارات', en: 'Traffic' },
  awareness: { ar: 'الوعي', en: 'Awareness' },
  engagement: { ar: 'التفاعل', en: 'Engagement' },
  video: { ar: 'المشاهدات', en: 'Video' },
  app: { ar: 'التطبيق', en: 'App' },
}

export function CampaignVisualDeck({ rows, currency, windowEnd, ar }: { rows: CampaignRow[]; currency: string | null; windowEnd: string } & Ar) {
  const lang = ar ? 'ar' : 'en'
  const { read, declined } = useMemo(() => readSpends(rows, (r) => r as unknown as MoneyTotals, currency, ar), [rows, currency, ar])

  const byObjective = groupSum(read, (r) => (r.objective ? canonicalObjectiveLabel(r.objective, lang) : (ar ? 'غير محدد' : 'Not set')))
  const byPlatform = groupSum(read, (r) => canonicalPlatform(r.provider))

  /* Efficiency, one result family at a time. */
  const families = useMemo(() => {
    const out = new Map<ResultFamily, Array<{ id: string; name: string; spend: number; costPer: number }>>()
    const missing = new Map<ResultFamily, number>()
    for (const { row, amount } of read) {
      const res = campaignResult(row.objective, row)
      if (res.family === 'unknown') continue
      if (amount === null || amount <= 0 || res.value === null || res.value <= 0) {
        missing.set(res.family, (missing.get(res.family) ?? 0) + 1)
        continue
      }
      const list = out.get(res.family) ?? []
      list.push({ id: row.campaign_id, name: row.campaign_name ?? '—', spend: amount, costPer: (amount / res.value) * res.per })
      out.set(res.family, list)
    }
    const order = [...out.entries()].sort((a, b) => b[1].reduce((s, p) => s + p.spend, 0) - a[1].reduce((s, p) => s + p.spend, 0)).map(([f]) => f)
    return { out, missing, order }
  }, [read])
  const [family, setFamily] = useState<ResultFamily | null>(null)
  const chosen = family && families.out.has(family) ? family : families.order[0] ?? null

  const relevance = useMemo(() => {
    const counts: Record<CampaignRelevance, number> = { serving: 0, idle: 0, stopped: 0 }
    for (const r of rows) counts[relevanceOf(r, windowEnd)] += 1
    return counts
  }, [rows, windowEnd])

  if (rows.length === 0) return null

  return (
    <DeckGrid testid="campaign-visual-deck">
      <ChartCard title={ar ? 'الإنفاق حسب الهدف' : 'Spend by objective'} subtitle={ar ? 'لمن اشتُريت الميزانية' : 'What the budget was bought for'}>
        {declined > 0
          ? <Declined testid="deck-objective-declined" text={declinedText(declined, ar)} />
          : byObjective.length === 0
            ? <Declined testid="deck-objective-empty" text={ar ? 'لا إنفاق في هذه الفترة.' : 'No spend in this period.'} />
            : <PlatformDonutChart colorBy="series" currency={currency ?? ''} data={byObjective.map(([name, value]) => ({ name, value }))} centerLabel={ar ? 'الأهداف' : 'Objectives'} centerValue={String(byObjective.length)} height={240} />}
      </ChartCard>

      <ChartCard title={ar ? 'الإنفاق حسب المنصة' : 'Spend by platform'} subtitle={ar ? 'حصة كل منصة من حملات الفترة' : 'Each platform’s share of the period'}>
        {declined > 0
          ? <Declined testid="deck-platform-declined" text={declinedText(declined, ar)} />
          : byPlatform.length === 0
            ? <Declined testid="deck-platform-empty" text={ar ? 'لا إنفاق في هذه الفترة.' : 'No spend in this period.'} />
            : <PlatformDonutChart currency={currency ?? ''} data={byPlatform.map(([key, value]) => ({ key, name: providerLabel(key, lang), value }))} centerLabel={ar ? 'المنصات' : 'Platforms'} centerValue={String(byPlatform.length)} height={240} />}
      </ChartCard>

      <ChartCard
        className="lg:col-span-2"
        title={ar ? 'الإنفاق مقابل تكلفة النتيجة' : 'Spend against cost per result'}
        subtitle={ar ? 'أعلى اليمين أولًا: إنفاق كبير بتكلفة نتيجة مرتفعة. المقارنة داخل هدف واحد فقط.' : 'Top-right first: high spend at a high cost per result. Compared inside one objective only.'}
        action={families.order.length > 1 ? (
          <div className="flex flex-wrap gap-1" role="group" aria-label={ar ? 'الهدف' : 'Objective'}>
            {families.order.map((f) => (
              <button
                key={f}
                type="button"
                data-testid={`deck-family-${f}`}
                aria-pressed={chosen === f}
                onClick={() => setFamily(f)}
                className={`rounded-full px-2.5 py-1 text-xs font-semibold ${chosen === f ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-text-secondary hover:text-text-primary'}`}
              >
                {FAMILY_WORDS[f]?.[lang] ?? f}
              </button>
            ))}
          </div>
        ) : undefined}
      >
        <SpendEfficiencyScatter ar={ar} currency={currency} points={chosen ? families.out.get(chosen) ?? [] : []} />
        <ExcludedNote ar={ar} testid="deck-scatter-excluded" count={chosen ? families.missing.get(chosen) ?? 0 : 0} />
      </ChartCard>

      <ChartCard className="lg:col-span-2" title={ar ? 'حالة الحملات في الفترة' : 'Campaign state in the period'} subtitle={ar ? 'كم منها يعمل الآن، وكم توقف' : 'How many are serving, and how many stopped'}>
        <StatusMixBar
          ar={ar}
          testId="deck-relevance"
          total={rows.length}
          bands={(['serving', 'idle', 'stopped'] as CampaignRelevance[]).map((k) => ({ key: k, label: RELEVANCE_LABEL[k][lang], count: relevance[k], tone: RELEVANCE_TONE[k] }))}
        />
      </ChartCard>
    </DeckGrid>
  )
}

/* ───────────────────────────── Accounts ───────────────────────────── */

export function AccountVisualDeck({ rows, currency, ar }: { rows: AccountRow[]; currency: string | null } & Ar) {
  const lang = ar ? 'ar' : 'en'
  const { read, declined } = useMemo(() => readSpends(rows, (r) => r as unknown as MoneyTotals, currency, ar), [rows, currency, ar])
  if (rows.length === 0) return null

  const name = (r: AccountRow) => `${r.account_name ?? (ar ? 'حساب لم يعد متاحًا' : 'Account no longer available')} · ${providerLabel(r.provider, lang)}`
  const share = read.filter((x) => x.amount !== null && x.amount > 0).map((x) => ({ name: name(x.row), value: x.amount as number }))

  const imp = rows.reduce((s, r) => s + (r.impressions ?? 0), 0)
  const clk = rows.reduce((s, r) => s + (r.clicks ?? 0), 0)
  const ctrRows = rows.filter((r) => typeof r.ctr === 'number' && (r.impressions ?? 0) > 0)
  const cpmRows = read.filter((x) => x.amount !== null && (x.row.impressions ?? 0) > 0)
  const spendAll = cpmRows.reduce((s, x) => s + (x.amount as number), 0)
  const impAll = cpmRows.reduce((s, x) => s + (x.row.impressions ?? 0), 0)

  return (
    <DeckGrid testid="account-visual-deck">
      <ChartCard title={ar ? 'حصة كل حساب من الإنفاق' : 'Each account’s share of spend'}>
        {declined > 0
          ? <Declined testid="deck-account-declined" text={declinedText(declined, ar)} />
          : share.length === 0
            ? <Declined testid="deck-account-empty" text={ar ? 'لا إنفاق في هذه الفترة.' : 'No spend in this period.'} />
            : <PlatformDonutChart colorBy="series" currency={currency ?? ''} data={share} centerLabel={ar ? 'الحسابات' : 'Accounts'} centerValue={String(share.length)} height={240} />}
      </ChartCard>
      <ChartCard title={ar ? 'نسبة النقر لكل حساب' : 'Click-through rate per account'} subtitle={ar ? 'الخط المتقطع: متوسط كل الحسابات' : 'Dashed line: all accounts together'}>
        {imp > 0 ? (
          <RatioBars
            ar={ar}
            testId="deck-account-ctr"
            reference={clk / imp}
            referenceLabel={ar ? `المتوسط ${percent(clk / imp, 2)}` : `Average ${percent(clk / imp, 2)}`}
            format={(v) => percent(v, 2)}
            /* A higher CTR is the better side, so the default «over is danger» is inverted. */
            toneFor={(v) => (v >= (clk / imp) * 1.05 ? 'success' : v >= (clk / imp) * 0.95 ? 'warning' : 'danger')}
            rows={ctrRows.map((r, i) => ({ id: `${r.account_id ?? 'x'}-${i}`, label: name(r), value: r.ctr as number }))}
          />
        ) : <Declined testid="deck-account-ctr-empty" text={ar ? 'لا ظهور في هذه الفترة.' : 'No impressions in this period.'} />}
      </ChartCard>
      {declined === 0 && impAll > 0 && (
        <ChartCard className="lg:col-span-2" title={ar ? 'تكلفة الألف ظهور (CPM)' : 'Cost per thousand impressions (CPM)'} subtitle={ar ? 'الأقصر أرخص وصولًا' : 'Shorter is cheaper reach'}>
          <RatioBars
            ar={ar}
            testId="deck-account-cpm"
            reference={spendAll / (impAll / 1000)}
            referenceLabel={ar ? `المتوسط ${money(spendAll / (impAll / 1000), currency ?? undefined)}` : `Average ${money(spendAll / (impAll / 1000), currency ?? undefined)}`}
            format={(v) => money(v, currency ?? undefined)}
            rows={cpmRows.map((x, i) => ({ id: `${x.row.account_id ?? 'x'}-${i}`, label: name(x.row), value: (x.amount as number) / ((x.row.impressions ?? 0) / 1000) }))}
          />
        </ChartCard>
      )}
    </DeckGrid>
  )
}

/* ───────────────────────────── Ad sets / ads ───────────────────────────── */

export function EntityVisualDeck({ rows, currency, level, ar }: { rows: EntityRow[]; currency: string | null; level: 'ad_set' | 'ad' } & Ar) {
  const { read, declined } = useMemo(() => readSpends(rows, (r) => r as unknown as MoneyTotals, currency, ar), [rows, currency, ar])
  if (rows.length < 2) return null

  const noun = level === 'ad' ? (ar ? 'الإعلانات' : 'ads') : (ar ? 'المجموعات' : 'ad groups')
  const label = (r: EntityRow) => r.name ?? (ar ? 'لم يعد اسمه محفوظًا' : 'Name no longer held')
  const top = read
    .filter((x) => x.amount !== null && x.amount > 0)
    .sort((a, b) => (b.amount as number) - (a.amount as number))
    .slice(0, 8)
    .map((x) => ({ label: label(x.row).slice(0, 28), spend: x.amount as number, platform: canonicalPlatform(x.row.provider ?? '') }))

  const imp = rows.reduce((s, r) => s + (r.impressions ?? 0), 0)
  const clk = rows.reduce((s, r) => s + (r.clicks ?? 0), 0)
  const ctrRows = rows
    .filter((r) => typeof r.ctr === 'number' && (r.impressions ?? 0) >= 1000)
    .sort((a, b) => (b.impressions ?? 0) - (a.impressions ?? 0))
    .slice(0, 10)

  return (
    <DeckGrid testid={`entity-visual-deck-${level}`}>
      <ChartCard title={ar ? `أعلى ${noun} إنفاقًا` : `Top ${noun} by spend`} subtitle={ar ? 'أعلى ثمانية، بلون منصتها' : 'Top eight, in their platform colour'}>
        {declined > 0
          ? <Declined testid="deck-entity-declined" text={declinedText(declined, ar)} />
          : top.length === 0
            ? <Declined testid="deck-entity-empty" text={ar ? 'لا إنفاق في هذه الفترة.' : 'No spend in this period.'} />
            : <RankingBarChart horizontal colorByPlatform currency={currency ?? ''} data={top} bars={[{ key: 'spend', name: ar ? 'الإنفاق' : 'Spend', kind: 'money' }]} height={Math.max(180, top.length * 34)} />}
      </ChartCard>
      <ChartCard title={ar ? `نسبة النقر في ${noun}` : `Click-through rate across ${noun}`} subtitle={ar ? 'الأكثر ظهورًا (ألف ظهور فأكثر) مقابل المتوسط' : 'The most seen (1,000+ impressions) against the average'}>
        {imp > 0 && ctrRows.length > 0 ? (
          <RatioBars
            ar={ar}
            testId={`deck-entity-ctr-${level}`}
            reference={clk / imp}
            referenceLabel={ar ? `المتوسط ${percent(clk / imp, 2)}` : `Average ${percent(clk / imp, 2)}`}
            format={(v) => percent(v, 2)}
            toneFor={(v) => (v >= (clk / imp) * 1.05 ? 'success' : v >= (clk / imp) * 0.95 ? 'warning' : 'danger')}
            rows={ctrRows.map((r) => ({ id: r.entity_id, label: label(r), value: r.ctr as number }))}
          />
        ) : <Declined testid="deck-entity-ctr-empty" text={ar ? 'لا صف بظهور كافٍ للمقارنة.' : 'No row has enough impressions to compare.'} />}
      </ChartCard>
    </DeckGrid>
  )
}

/* ───────────────────────────── Budget ───────────────────────────── */

function paceTone(pace: number | null): 'brand' | 'warning' | 'danger' | 'success' {
  if (pace === null) return 'brand'
  return pace > 1.2 ? 'danger' : pace > 1.05 ? 'warning' : pace >= 0.8 ? 'success' : 'brand'
}

export function BudgetVisualDeck({ platforms, campaigns, ar }: { platforms: PlatformBudgetRow[]; campaigns: BudgetRow[] } & Ar) {
  const lang = ar ? 'ar' : 'en'
  const rings = platforms.filter((p) => p.consumed_pct !== null)
  const paced = campaigns.filter((c) => typeof c.pace === 'number')
  const unpaced = campaigns.length - paced.length
  if (rings.length === 0 && paced.length === 0) return null

  return (
    <DeckGrid testid="budget-visual-deck">
      <ChartCard title={ar ? 'استهلاك ميزانية كل منصة' : 'Budget consumed per platform'} subtitle={ar ? 'لون الحلقة من سرعة الصرف: أحمر يتجاوز الخطة' : 'Ring colour follows pace: red overruns the plan'}>
        {rings.length === 0
          ? <Declined testid="deck-budget-rings-empty" text={ar ? 'لا منصة لها ميزانية قابلة للمقارنة بإنفاقها.' : 'No platform has a budget comparable with its spend.'} />
          : (
            <div className="flex flex-wrap items-start justify-center gap-6">
              {rings.map((p) => (
                <div key={p.provider} data-testid={`deck-budget-ring-${canonicalPlatform(p.provider)}`} className="flex flex-col items-center gap-2">
                  <ProgressRing size={112} value={p.consumed_pct as number} tone={paceTone(p.pace)} sublabel={p.pace === null ? undefined : `${ratio(p.pace)}`} />
                  <span className="text-sm font-semibold text-text-primary">{providerLabel(canonicalPlatform(p.provider), lang)}</span>
                  <span className="tnum text-xs text-text-muted" dir="ltr">
                    {p.spent === null ? '—' : compact(p.spent)} / {compact(p.budget)} {p.budget_currency ?? ''}
                  </span>
                </div>
              ))}
            </div>
          )}
      </ChartCard>
      <ChartCard title={ar ? 'سرعة صرف الحملات' : 'Campaign pace'} subtitle={ar ? 'الخط المتقطع = على الخطة تمامًا (1.0×)' : 'Dashed line = exactly on plan (1.0×)'}>
        <RatioBars
          ar={ar}
          testId="deck-budget-pace"
          reference={1}
          referenceLabel={ar ? 'على الخطة' : 'On plan'}
          rows={paced
            .sort((a, b) => (b.pace as number) - (a.pace as number))
            .slice(0, 10)
            .map((c) => ({ id: c.campaign_id, label: c.campaign_name, value: c.pace as number }))}
        />
        <ExcludedNote ar={ar} testid="deck-budget-unpaced" count={unpaced} why={{ ar: 'لا تُقاس سرعتها (عملة مختلفة أو لا ميزانية أو لا إنفاق).', en: 'their pace cannot be measured (another currency, no budget or no spend).' }} />
      </ChartCard>
    </DeckGrid>
  )
}
