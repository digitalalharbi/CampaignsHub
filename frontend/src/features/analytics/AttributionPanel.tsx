import type { ReactNode } from 'react'
import { Panel } from './components'
import { money, moneyExact, num, percent } from './format'
import type { Attribution, PlatformClaim, Reconciliation as ReconciliationData } from './api'
import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { Explainer } from '@/components/ui/Explainer'
import { providerLabel } from '@/features/campaigns/labels'
import type { Locale } from '@/stores/ui'
import { campaigns as countedCampaigns } from '@/lib/counted'
import { days as countedDays } from '@/lib/counted'

/**
 * REPORT-OBJECTIVE-005 — the two systems that answer «كم بعنا؟», kept apart on screen.
 *
 * ## What this panel is for
 *
 * Two blocks that are never one figure: what each PLATFORM reported, and what the STORE confirmed.
 * The platforms' claims are listed one per row and are never added together, because a sale clicked
 * from two platforms is reported in full by both and the sum is an order count that never happened.
 *
 * The server withholds that total and sends the REASON with it, and this panel prints the reason
 * where the number would have been. A reader who sees an absent figure and no explanation concludes
 * the sync is broken; a reader who sees «هذه الأرقام لا تُجمع، ولماذا» has learned the thing the
 * panel exists to teach.
 *
 * ## The comparison is per platform, deliberately
 *
 * Each platform's claim sits beside the orders the shop actually recorded for it. Those two ARE
 * comparable, and the gap is the useful number. A single rolled-up «the platforms over-report by
 * 40%» would need the unified total this whole feature refuses to produce.
 *
 * ## Nothing here is a correction
 *
 * Neither figure is adjusted to match the other. We do not know which is wrong — the pixel misses
 * ad-blocked sessions, the ledger misses nothing but also credits no ad — and a panel that silently
 * picked one would be making that call on the client's behalf without saying so.
 */
export function AttributionPanel({
  data,
  loading,
  error,
  locale,
  className,
}: {
  data: Attribution | undefined
  loading?: boolean
  error?: boolean
  locale: Locale
  className?: string
}) {
  const ar = locale === 'ar'
  const platforms = data?.platform_reported?.platforms ?? []
  const store = data?.store_confirmed

  /*
   * Only platforms with BOTH figures. A comparison needs two measured sides; a platform whose store
   * side is null was never checked against a shop, and that is not a zero.
   */
  const comparable = platforms.filter((p) => p.store_confirmed_orders !== null)
  const dedup = data?.dedup

  return (
    <Panel
      title={ar ? 'الإسناد ومنع التكرار' : 'Attribution & de-duplication'}
      description={
        ar
          ? 'ما أبلغت به المنصات وما أكّده المتجر — رقمان مختلفان لسؤال واحد، ولا يُجمعان'
          : 'What the platforms reported and what the store confirmed — two answers to one question, never added together'
      }
      loading={loading}
      error={error}
      empty={!loading && platforms.length === 0 && !store?.available}
      className={className}
    >
      {/*
       * `min-w-0` on the grid and on every section it holds.
       *
       * A grid item defaults to `min-width: auto`, which means it refuses to shrink below its content
       * — so the table's `min-w-[620px]` propagated straight out through the section, the grid, the
       * panel and the page. Live at 375px the whole page scrolled sideways and the table's own
       * `overflow-x-auto` box never engaged, because it had been stretched to 620px rather than
       * clipped. The scroll belongs to the table, not to the document.
       */}
      <div data-testid="attribution" className="grid min-w-0 gap-5 text-sm">
        {/* ── Platform-Reported ─────────────────────────────────────────────────────────── */}
        <section className="min-w-0">
          <h4 className="text-xs font-bold uppercase tracking-wide text-text-muted">
            {ar ? data?.platform_reported?.label_ar : data?.platform_reported?.label_en}
          </h4>
          <p className="mt-1 text-text-secondary">
            {ar ? data?.platform_reported?.basis_ar : data?.platform_reported?.basis_en}
          </p>

          {/*
            DATA-QUALITY-OPERATOR-UX-001 · TABLE-PRESENTATION-CONTRACT-001 — the attribution half on
            the product's own table.

            This was the last hand-rolled table on the analytics tabs: no sort on any column, and the
            one question an operator brings to this panel is «which platform is overclaiming the
            most», which is a sort. `values` carries the raw figures behind the stacked cells, so the
            order is on the number rather than on «94K SAR» as a string, and a platform with no store
            connected sorts LAST rather than as a zero difference — «nobody checked» is not «the shop
            saw none», and that distinction is the entire point of this panel.
          */}
          {/*
            VISUAL-FIRST-001 — «ATTRIBUTION → platform-reported vs store-confirmed visual comparison.»

            The panel's whole subject is a DISAGREEMENT between two numbers, and a disagreement is
            the thing a paired bar shows and a table row does not: side by side on one scale, the gap
            IS the finding, and the platform overclaiming most is the longest overhang rather than
            the largest cell in a column somebody has to sort.

            Drawn ONLY for platforms where both sides were actually measured. With no store connected
            there is no second number, and a bar pair with one side missing would read as «the shop
            saw none» — the exact confusion this panel exists to prevent. Those platforms keep their
            table row and their «no store connected» state, and the comparison simply says nothing
            about them.
          */}
          {comparable.length > 0 && (
            <div className="mt-3 space-y-2" data-testid="attribution-comparison">
              {comparable.map((p) => {
                const ceiling = Math.max(p.platform_reported_orders, p.store_confirmed_orders ?? 0) || 1
                return (
                  <div key={p.provider} className="flex items-center gap-2" data-testid={`attribution-compare-${p.provider}`}>
                    <span className="w-24 shrink-0 truncate text-xs text-text-secondary">{providerLabel(p.provider, locale)}</span>
                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                      <span className="flex items-center gap-2">
                        <span className="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-secondary">
                          <span className="block h-full rounded-full bg-brand-500" style={{ width: `${(p.platform_reported_orders / ceiling) * 100}%` }} />
                        </span>
                        <span className="tnum w-16 shrink-0 text-end text-[11px] text-text-secondary" dir="ltr">{num(p.platform_reported_orders)}</span>
                      </span>
                      <span className="flex items-center gap-2">
                        <span className="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-secondary">
                          <span className="block h-full rounded-full bg-text-muted" style={{ width: `${((p.store_confirmed_orders ?? 0) / ceiling) * 100}%` }} />
                        </span>
                        <span className="tnum w-16 shrink-0 text-end text-[11px] text-text-secondary" dir="ltr">{num(p.store_confirmed_orders ?? 0)}</span>
                      </span>
                    </div>
                  </div>
                )
              })}
              <p className="text-[11px] text-text-muted">
                {ar
                  ? 'الأعلى: ما أبلغت به المنصة · الأدنى: ما أكّده المتجر'
                  : 'Upper: platform-reported · lower: store-confirmed'}
              </p>
            </div>
          )}

          {platforms.length > 0 && (
            <div className="mt-3">
              <MetricTable
                head={[
                  ar ? 'المنصة' : 'Platform',
                  ar ? 'أبلغت المنصة' : 'Platform-Reported',
                  ar ? 'أكّده المتجر' : 'Store-Confirmed',
                  ar ? 'الفرق' : 'Difference',
                  ar ? 'نافذة الإسناد' : 'Attribution window',
                ]}
                rows={platforms.map((p) => claimCells(p, ar, locale))}
                values={platforms.map((p): SortValues => [
                  providerLabel(p.provider, locale),
                  p.platform_reported_orders,
                  p.store_confirmed_orders,
                  p.difference,
                  p.attribution.window_known ? (p.attribution.click_through_days ?? null) : null,
                ])}
                initialSort={{ column: 3, dir: 'desc' }}
              />
            </div>
          )}

          {/*
           * The refusal, printed where the total would be. This is the sentence the panel exists for
           * — an absent number with no reason beside it reads as a broken sync.
           *
           * VISUAL-FIRST-001 splits it in two. The REFUSAL stays on the page, because that is the
           * finding: there is no unified total, and a reader who takes nothing else from this panel
           * must take that. The thirty words explaining WHY there cannot be one — no shared key, one
           * sale reported by two platforms, summing produces a count of orders that never happened —
           * are true, load-bearing and read once; they are disclosed rather than printed, so the
           * panel states its answer and offers its reasoning instead of leading with it.
           */}
          <div
            data-testid="attribution-total-withheld"
            className="mt-3 rounded-lg border border-border bg-surface-secondary p-3 text-text-secondary"
          >
            <p className="font-semibold text-text-primary">
              {ar ? 'لا يوجد إجمالي موحّد للمنصات.' : 'There is no unified platform total.'}
            </p>
            <Explainer
              className="mt-1.5"
              testid="attribution-total-withheld-why"
              label={ar ? 'لماذا لا يُجمع' : 'Why it cannot be summed'}
            >
              {ar ? data?.platform_reported?.total_withheld_ar : data?.platform_reported?.total_withheld_en}
            </Explainer>
          </div>
        </section>

        {/*
         * CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 — how much of what the platforms claim is the same sale.
         *
         * Between the two measurements, because it is about the distance between them. One order
         * bought after a TikTok video AND a Meta retargeting ad is counted by both, and that is
         * invisible per platform: each figure is honest on its own terms.
         */}
        <Overlap overlap={data?.overlap} ar={ar} />
        <Reconciliation data={data?.reconciliation} ar={ar} />

        {/* ── Store-Confirmed ───────────────────────────────────────────────────────────── */}
        <section className="min-w-0 border-t border-border pt-4">
          <h4 className="text-xs font-bold uppercase tracking-wide text-text-muted">
            {ar ? store?.label_ar : store?.label_en}
          </h4>

          {store?.available ? (
            <>
              <p className="mt-1 text-text-secondary">{ar ? store.basis_ar : store.basis_en}</p>
              <div className="mt-3 flex flex-wrap gap-6">
                <Figure label={ar ? 'الطلبات المؤكَّدة' : 'Confirmed orders'} value={num(store.orders ?? 0)} />
                <Figure
                  label={ar ? 'الإيراد الصافي' : 'Net revenue'}
                  value={money(store.revenue ?? 0, store.currency ?? undefined)}
                />
                <Figure
                  label={ar ? 'مُسندة إلى حملة' : 'Attributed to a campaign'}
                  value={num(store.attributed_orders ?? 0)}
                />
              </div>
              <p className="mt-2 text-xs text-text-muted">
                {ar
                  ? `هذا الرقم يُجمع لأن لكل طلبية مفتاحًا حقيقيًا: ${dedup?.store_confirmed.key}.`
                  : `This figure may be totalled because every order has a real key: ${dedup?.store_confirmed.key}.`}
              </p>

              {/*
               * A shop connected twice is a setup problem the merchant can fix, and the collapse is
               * stated rather than applied in silence — a total that halves between two weeks with no
               * line saying why is a total nobody trusts again.
               */}
              {(store.duplicates_collapsed ?? 0) > 0 && (
                <p
                  data-testid="attribution-duplicates"
                  className="mt-3 rounded-lg border border-[var(--warning-border,var(--border))] bg-[var(--warning-background)] p-3 text-warning"
                >
                  {ar
                    ? `دُمجت ${num(store.duplicates_collapsed ?? 0)} نسخة مكررة من طلبات قبل احتساب أي رقم أعلاه.`
                    : `${num(store.duplicates_collapsed ?? 0)} duplicate copies of orders were collapsed before any figure above was computed.`}
                  {(store.shops_connected_more_than_once ?? []).map((shop) => (
                    <span key={`${shop.provider}-${shop.shop_external_id}`} className="mt-1 block">
                      {ar
                        ? `المتجر «${shop.names[0]}» مربوط ${arabicTimes(shop.connections)} — افصل الربط الزائد.`
                        : `The shop “${shop.names[0]}” is connected ${num(shop.connections)} times — disconnect the extra one.`}
                    </span>
                  ))}
                </p>
              )}
            </>
          ) : (
            <p className="mt-1 text-text-secondary">{ar ? store?.unavailable_ar : store?.unavailable_en}</p>
          )}
        </section>

        {/* ── Unattributed ──────────────────────────────────────────────────────────────── */}
        {data?.unattributed?.available && (data.unattributed.orders ?? 0) > 0 && (
          <section className="min-w-0 border-t border-border pt-4">
            <h4 className="text-xs font-bold uppercase tracking-wide text-text-muted">
              {ar ? 'طلبات بلا إسناد' : 'Unattributed orders'}
            </h4>
            <div className="mt-2 flex flex-wrap gap-6">
              <Figure label={ar ? 'العدد' : 'Orders'} value={num(data.unattributed.orders ?? 0)} />
              <Figure
                label={ar ? 'النسبة' : 'Share'}
                value={data.unattributed.share !== null ? percent(data.unattributed.share) : '—'}
              />
            </div>
            <p className="mt-2 text-xs text-text-muted">
              {ar ? data.unattributed.note_ar : data.unattributed.note_en}
            </p>
          </section>
        )}

        {/* ── The model, which is governance rather than measurement ────────────────────── */}
        {(data?.models?.length ?? 0) > 0 && (
          <section className="min-w-0 border-t border-border pt-4">
            <h4 className="text-xs font-bold uppercase tracking-wide text-text-muted">
              {ar ? 'نموذج الإسناد المُعرَّف على الحملات' : 'Attribution model set on the campaigns'}
            </h4>
            <ul className="mt-2 grid gap-1 text-text-secondary">
              {data?.models?.map((m) => (
                <li key={m.model}>
                  {m.is_set ? (
                    <code className="rounded bg-surface-secondary px-1.5 py-0.5 text-[12px]">{m.model}</code>
                  ) : (
                    /* Never defaulted. «Nobody set one» is a different sentence from «last click». */
                    <span className="font-semibold text-warning">{ar ? 'غير محدَّد' : 'Not set'}</span>
                  )}
                  <span className="ms-2 text-text-muted">
                    {countedCampaigns(m.campaigns, ar ? 'ar' : 'en')}
                  </span>
                </li>
              ))}
            </ul>
          </section>
        )}
      </div>
    </Panel>
  )
}

/**
 * One platform's claim, as the five cells the table renders.
 *
 * Cells rather than a row since the migration onto `MetricTable`: the table owns the `<tr>`, the
 * alignment and the sort, and this owns what each cell SAYS. Every sentence below is unchanged —
 * the stacked count-over-money, the «no store connected» that is not a zero, the mixed-window
 * warning — because none of them was the problem; the missing sort was.
 *
 * @return list of five cells, positionally matched to the header
 */
function claimCells(claim: PlatformClaim, ar: boolean, locale: Locale): ReactNode[] {
  const a = claim.attribution

  return [
    <span key="p" className="font-semibold text-text-primary">{providerLabel(claim.provider, locale)}</span>,

    /*
     * An order COUNT and a MONEY amount stack rather than sitting side by side.
     *
     * Inline, «267» and «94K SAR» read as one token — live, the cell said «26794K SAR», which is not
     * a number this product has. A four-pixel margin is not a separator between two figures in
     * different units.
     */
    <Pair
      key="reported"
      primary={num(claim.platform_reported_orders)}
      secondary={money(claim.platform_reported_revenue, claim.currency ?? undefined)}
      unit={ar ? 'طلب' : 'orders'}
    />,

    /* Null, never a dash standing in for zero — «nobody checked» is not «the shop saw none». */
    claim.store_confirmed_orders === null ? (
      <span key="store" className="text-text-muted">{ar ? 'لا يوجد متجر مربوط' : 'No store connected'}</span>
    ) : (
      <Pair
        key="store"
        primary={num(claim.store_confirmed_orders)}
        secondary={money(claim.store_confirmed_revenue ?? 0, claim.currency ?? undefined)}
        unit={ar ? 'طلب' : 'orders'}
      />
    ),

    <span key="difference" className="text-text-secondary">
      <span className="block">
        {claim.difference === null ? '—' : num(claim.difference)}
        {/* A literal space, not only a margin — the unit has to survive copy-paste and screen readers. */}
        {claim.difference !== null && (
          <>
            {' '}
            <span className="text-xs text-text-muted">{ar ? 'طلب' : 'orders'}</span>
          </>
        )}
      </span>
      {claim.ratio !== null && (
        <span className="block text-xs text-text-muted">
          {ar ? `المنصة تُبلّغ ×${claim.ratio}` : `platform reports ×${claim.ratio}`}
        </span>
      )}
    </span>,

    <span key="window" className="text-text-secondary">
      {a.window_known ? (
        <>
          <span>
            {ar ? `نقرة ${countedDays(a.click_through_days ?? 0, 'ar')}` : `${a.click_through_days}d click`}
          </span>
          <span className="ms-2">
            {a.view_through_days === null
              ? ar
                ? '· بلا مشاهدة'
                : '· no view-through'
              : ar
                ? `· مشاهدة ${countedDays(a.view_through_days as number, 'ar')}`
                : `· ${a.view_through_days}d view`}
          </span>
          {a.mixed_windows && (
            <span className="mt-0.5 block text-xs font-semibold text-warning">
              {ar
                ? 'أكثر من نافذة داخل هذه المنصة — أرقامها غير متقارنة فيما بينها.'
                : 'More than one window inside this platform — its own figures are not comparable to each other.'}
            </span>
          )}
        </>
      ) : (
        <span className="text-text-muted">{ar ? a.unknown_ar : a.unknown_en}</span>
      )}
    </span>,
  ]
}

/**
 * «مرتين» for two, «{n} مرات» beyond it.
 *
 * Arabic has a dual, and «مربوط 2 مرات» is not a sentence a reader of this interface would write.
 * Two is also the overwhelmingly common case here — a shop connected twice — so the wrong form would
 * be the one almost everybody sees. The digits stay Latin, as they do everywhere in this product.
 */
function arabicTimes(n: number): string {
  return n === 2 ? 'مرتين' : `${num(n)} مرات`
}

/** A count over its money amount — two units, two lines, never one run-together token. */
function Pair({ primary, secondary, unit }: { primary: string; secondary: string; unit: string }) {
  return (
    <span className="text-text-secondary">
      <span className="block">
        {primary} <span className="text-xs text-text-muted">{unit}</span>
      </span>
      <span className="block text-xs text-text-muted">{secondary}</span>
    </span>
  )
}

function Figure({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div className="text-xs font-bold uppercase tracking-wide text-text-muted">{label}</div>
      <div className="text-lg font-semibold text-text-primary">{value}</div>
    </div>
  )
}

/**
 * The overlap between what the platforms claim and what the shop recorded — as a FLOOR.
 *
 * `claimed − confirmed` is «at least this many claims are not distinct sales». Never «exactly»: a
 * claim with no confirmed sale behind it may be one order two platforms both claimed, a sale that
 * never happened, or a real sale the shop cannot see. The product cannot tell them apart, so it does
 * not name one — the note says all three, and the number is labelled a claim rather than an order.
 *
 * Coverage sits beside it because it bounds it. Measured against half a ledger, the gap is a claim
 * about half a shop, and a reader who is not told that reads it as a claim about the whole one.
 */
function Overlap({ overlap, ar }: { overlap: Attribution['overlap'] | undefined; ar: boolean }) {
  if (overlap === undefined) {
    return null
  }

  if (! overlap.available) {
    return (
      <section data-testid="attribution-overlap-unavailable" className="min-w-0 border-t border-border pt-4">
        <h4 className="text-xs font-bold uppercase tracking-wide text-text-muted">
          {ar ? 'التداخل بين المنصات' : 'Overlap between platforms'}
        </h4>
        <p className="mt-1 text-text-secondary">{ar ? overlap.note_ar : overlap.note_en}</p>
      </section>
    )
  }

  return (
    <section data-testid="attribution-overlap" className="min-w-0 border-t border-border pt-4">
      <h4 className="text-xs font-bold uppercase tracking-wide text-text-muted">
        {ar ? 'التداخل بين المنصات' : 'Overlap between platforms'}
      </h4>

      <p className="mt-1 text-text-secondary">
        {ar
          ? `تدّعي المنصات ${num(overlap.platforms_claim ?? 0)} بيعة، وسجّل المتجر ${num(overlap.store_confirms ?? 0)}.`
          : `The platforms claim ${num(overlap.platforms_claim ?? 0)} sales; the shop recorded ${num(overlap.store_confirms ?? 0)}.`}
      </p>

      <p data-testid="attribution-overlap-floor" className="mt-2 text-sm font-semibold text-text-primary">
        {ar
          ? `${num(overlap.at_least_duplicated ?? 0)} مطالبة على الأقل ليست بيعة مستقلة.`
          : `At least ${num(overlap.at_least_duplicated ?? 0)} claims are not distinct sales.`}
      </p>

      {/* The caveat is not a footnote here: it is what makes the number above honest. */}
      <p data-testid="attribution-overlap-note" className="mt-1 text-xs text-text-secondary">
        {ar ? overlap.note_ar : overlap.note_en}
      </p>

      {overlap.coverage !== null && overlap.coverage !== undefined && (
        <p data-testid="attribution-overlap-coverage" className="mt-2 text-xs text-text-muted">
          {ar
            ? `المقارنة مبنية على ${percent(overlap.coverage, 0)} من طلبات المتجر — الباقي بلا إسناد.`
            : `Measured against ${percent(overlap.coverage, 0)} of the shop's orders — the rest carry no attribution.`}
        </p>
      )}
    </section>
  )
}

/**
 * ATTRIBUTION-RECONCILIATION-001 — Owner directive 2026-10-09 §50: the four layers and the ledger.
 *
 * The sections above keep the claims apart and set them beside the store. This one shows the step
 * after: per platform, the claim and what the ledger actually placed there, the gap named as that
 * platform's overclaim, and a ROAS that says which of the two it was counted on. Then the ledger
 * itself — the merchant's own references, strongest evidence first — so «reconciled» is a list a
 * reader can check, not a number they are asked to trust. GA4 is its own line: read where it was
 * synced, stated absent where it was not, and never added into any of the others.
 */
function Reconciliation({ data, ar }: { data: ReconciliationData | undefined; ar: boolean }) {
  if (!data) return null

  const t = {
    title: ar ? 'التسوية' : 'Reconciliation',
    claimed: ar ? 'المزعوم' : 'Claimed',
    reconciled: ar ? 'المُسوّى' : 'Reconciled',
    overclaim: ar ? 'مطالبة زائدة' : 'Overclaim',
    spend: ar ? 'الإنفاق' : 'Spend',
    roasPlatform: ar ? 'عائد (المنصة)' : 'ROAS (platform-reported)',
    roasReconciled: ar ? 'عائد (مُسوّى)' : 'ROAS (reconciled)',
    roasBusiness: ar ? 'عائد الأعمال (المتجر على كل الإنفاق)' : 'Business ROAS (store over all spend)',
    notStated: ar ? 'غير مُحدَّد' : 'not stated',
    unavailable: ar ? 'لا يوجد دفتر طلبات يُسوّى عليه' : 'No ledger to reconcile against',
    ledger: ar ? 'دفتر الطلبات' : 'Order ledger',
    reference: ar ? 'المرجع' : 'Reference',
    date: ar ? 'التاريخ' : 'Date',
    platform: ar ? 'المنصة' : 'Platform',
    campaign: ar ? 'الحملة' : 'Campaign',
    evidence: ar ? 'الدليل' : 'Evidence',
    revenue: ar ? 'الإيراد الصافي' : 'Net revenue',
    refunded: ar ? 'مُسترد' : 'Refunded',
    unattributed: ar ? 'غير مُسند' : 'Unattributed',
    conflict: ar ? 'إشارات متعارضة' : 'Conflicting signals',
    orders: ar ? 'طلبات' : 'orders',
  }

  const evidence: Record<string, { ar: string; en: string }> = {
    utm_campaign_id: { ar: 'معرّف الحملة من المنصة', en: 'Campaign id from the platform' },
    utm_campaign_name: { ar: 'اسم الحملة', en: 'Campaign name' },
    click_id_platform_only: { ar: 'معرّف نقرة — المنصة فقط', en: 'Click id — platform only' },
    utm_source_platform_only: { ar: 'مصدر UTM — المنصة فقط', en: 'UTM source — platform only' },
    conflict: { ar: 'إشارات متعارضة', en: 'Conflicting signals' },
    none: { ar: 'غير مُسند', en: 'Unattributed' },
  }

  return (
    <section data-testid="reconciliation" className="min-w-0 border-t border-border pt-4">
      <h4 className="text-xs font-bold uppercase tracking-wide text-text-muted">{t.title}</h4>
      <p className="mt-1 text-xs text-text-secondary">{ar ? data.note_ar : data.note_en}</p>

      {!data.available && (
        <p data-testid="reconciliation-unavailable" className="mt-2 text-sm font-semibold text-text-primary">
          {t.unavailable}
        </p>
      )}

      {/* Per platform: claim · reconciled · overclaim · the two ROAS, each with its basis. */}
      <ul className="mt-3 grid grid-cols-1 gap-2">
        {data.platforms.map((p) => (
          <li key={p.provider} data-testid={`reconciliation-row-${p.provider}`} className="min-w-0 rounded-lg border border-border bg-surface-secondary p-2.5">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
              <span className="text-sm font-semibold text-text-primary">{providerLabel(p.provider, ar ? 'ar' : 'en')}</span>
              <span className="flex flex-wrap items-baseline gap-x-4 gap-y-1 text-xs text-text-secondary">
                <span><span className="text-text-muted">{t.claimed} </span><span className="tnum font-semibold text-text-primary" dir="ltr">{num(p.platform_reported_orders)}</span></span>
                <span><span className="text-text-muted">{t.reconciled} </span><span className="tnum font-semibold text-text-primary" dir="ltr">{p.reconciled_orders === null ? '—' : num(p.reconciled_orders)}</span></span>
                <span><span className="text-text-muted">{t.overclaim} </span><span className="tnum font-semibold text-warning" dir="ltr">{p.overclaim_orders === null ? '—' : num(p.overclaim_orders)}</span></span>
                {p.spend !== null && <span><span className="text-text-muted">{t.spend} </span><span className="tnum" dir="ltr">{money(p.spend, data.ledger?.rows[0]?.currency ?? undefined)}</span></span>}
              </span>
            </div>
            <div className="mt-1.5 flex flex-wrap gap-2 text-[11px]">
              <span data-testid={`roas-platform_reported-${p.provider}`} className="rounded-full bg-surface px-2 py-0.5 text-text-secondary">
                {t.roasPlatform}: <span className="tnum font-semibold text-text-primary" dir="ltr">{p.roas.platform_reported.value === null ? t.notStated : `${p.roas.platform_reported.value}×`}</span>
              </span>
              <span data-testid={`roas-reconciled-${p.provider}`} className="rounded-full bg-surface px-2 py-0.5 text-text-secondary">
                {t.roasReconciled}: <span className="tnum font-semibold text-text-primary" dir="ltr">{p.roas.reconciled.value === null ? t.notStated : `${p.roas.reconciled.value}×`}</span>
              </span>
            </div>
          </li>
        ))}
      </ul>

      {/* Business ROAS — the ledger over all spend; the one basis no platform's claim touches. */}
      {data.business_roas && (
        <p data-testid="reconciliation-business-roas" className="mt-3 text-xs text-text-secondary">
          <span className="font-semibold text-text-primary">{t.roasBusiness}: </span>
          <span className="tnum font-semibold text-text-primary" dir="ltr">{data.business_roas.value === null ? t.notStated : `${data.business_roas.value}×`}</span>
        </p>
      )}

      {/* GA4 — its own line, never added into the rows above. */}
      <p data-testid="reconciliation-measurement" className="mt-3 text-xs text-text-secondary">
        <span className="font-semibold text-text-primary">{ar ? data.layers.measurement.ar : data.layers.measurement.en}: </span>
        {data.measurement.available ? (
          <>
            <span className="tnum" dir="ltr">{num(data.measurement.transactions ?? 0)}</span>
            {' '}{ar ? 'معاملة' : 'transactions'}
            {data.measurement.revenue !== null && (
              <>
                {' · '}
                <span className="tnum" dir="ltr">{moneyExact(data.measurement.revenue, data.measurement.currency ?? undefined)}</span>
              </>
            )}
            {' — '}{ar ? data.measurement.note_ar : data.measurement.note_en}
          </>
        ) : (
          <span>{ar ? data.measurement.note_ar : data.measurement.note_en}</span>
        )}
      </p>

      {data.available && data.unattributed && data.conflict && (
        <p data-testid="reconciliation-unplaced" className="mt-1 text-xs text-text-secondary">
          {t.unattributed}: <span className="tnum" dir="ltr">{num(data.unattributed.orders)}</span> {t.orders}
          {data.conflict.orders > 0 && (
            <> · {t.conflict}: <span className="tnum" dir="ltr">{num(data.conflict.orders)}</span> {t.orders}</>
          )}
        </p>
      )}

      {data.ledger && (
        <div data-testid="reconciliation-ledger" className="mt-3 min-w-0">
          <div className="flex flex-wrap items-baseline justify-between gap-2">
            <h5 className="text-xs font-semibold text-text-primary">{t.ledger}</h5>
            {data.ledger.truncated && (
              <span data-testid="reconciliation-ledger-cap" className="text-[11px] text-text-muted">
                {ar
                  ? `أحدث ${num(data.ledger.cap)} من ${num(data.ledger.total)} — بترتيب قوة الدليل`
                  : `${num(data.ledger.cap)} of ${num(data.ledger.total)}, strongest evidence first`}
              </span>
            )}
          </div>
          {/*
           * TABLE-PRESENTATION-CONTRACT-001 — the ledger is an analytical table and uses the product's
           * one primitive: sort on any column, numerals aligned by the primitive, not by the cell. The
           * row's test id rides on the reference cell. The campaign column exists only where the
           * payload carries one — a client link never does.
           */}
          {(() => {
            const withCampaign = data.ledger.rows.some((r) => r.campaign !== undefined)
            const locale = ar ? 'ar' : 'en'
            const head = [
              t.reference, t.date, t.platform,
              ...(withCampaign ? [t.campaign] : []),
              t.evidence, t.revenue, t.refunded,
            ]
            const rows = data.ledger.rows.map((r) => [
              <span key="ref" data-testid={`ledger-row-${r.reference}`} className="font-semibold text-text-primary">{r.reference}</span>,
              <span key="date" className="text-text-secondary">{r.placed_at ?? '—'}</span>,
              <span key="platform" className="text-text-secondary">{r.platform === null ? '—' : providerLabel(r.platform, locale)}</span>,
              ...(withCampaign ? [<span key="campaign" className="text-text-secondary">{r.campaign ?? '—'}</span>] : []),
              <span key="evidence" className="text-text-secondary">{ar ? (evidence[r.method]?.ar ?? r.method) : (evidence[r.method]?.en ?? r.method)}</span>,
              <span key="revenue" className="text-text-primary">{r.revenue === null ? '—' : moneyExact(r.revenue, r.currency ?? undefined)}</span>,
              <span key="refunded" className="text-text-secondary">{r.refunded === null ? '—' : moneyExact(r.refunded, r.currency ?? undefined)}</span>,
            ])
            const values: SortValues[] = data.ledger.rows.map((r) => [
              r.reference, r.placed_at, r.platform === null ? null : providerLabel(r.platform, locale),
              ...(withCampaign ? [r.campaign ?? null] : []),
              r.evidence_rank, r.revenue, r.refunded,
            ])
            return (
              <div className="mt-1.5 min-w-0">
                <MetricTable head={head} rows={rows} values={values} initialSort={{ column: withCampaign ? 4 : 3, dir: 'asc' }} />
              </div>
            )
          })()}
        </div>
      )}
    </section>
  )
}
