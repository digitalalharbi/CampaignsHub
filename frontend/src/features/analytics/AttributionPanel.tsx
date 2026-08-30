import { Panel } from './components'
import { money, num, percent } from './format'
import type { Attribution, PlatformClaim } from './api'
import { providerLabel } from '@/features/campaigns/labels'
import type { Locale } from '@/stores/ui'

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

          {platforms.length > 0 && (
            <div className="mt-3 min-w-0 overflow-x-auto">
              <table className="w-full min-w-[620px] text-sm">
                <thead>
                  <tr className="border-b border-border text-xs uppercase tracking-wide text-text-muted">
                    <th className="p-2 text-start font-bold">{ar ? 'المنصة' : 'Platform'}</th>
                    <th className="p-2 text-start font-bold">{ar ? 'أبلغت المنصة' : 'Platform-Reported'}</th>
                    <th className="p-2 text-start font-bold">{ar ? 'أكّده المتجر' : 'Store-Confirmed'}</th>
                    <th className="p-2 text-start font-bold">{ar ? 'الفرق' : 'Difference'}</th>
                    <th className="p-2 text-start font-bold">{ar ? 'نافذة الإسناد' : 'Attribution window'}</th>
                  </tr>
                </thead>
                <tbody>
                  {platforms.map((p) => (
                    <ClaimRow key={p.provider} claim={p} ar={ar} locale={locale} />
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/*
           * The refusal, printed where the total would be. This is the sentence the panel exists for
           * — an absent number with no reason beside it reads as a broken sync.
           */}
          <p
            data-testid="attribution-total-withheld"
            className="mt-3 rounded-lg border border-border bg-surface-secondary p-3 text-text-secondary"
          >
            <span className="font-semibold text-text-primary">
              {ar ? 'لا يوجد إجمالي موحّد للمنصات.' : 'There is no unified platform total.'}
            </span>{' '}
            {ar ? data?.platform_reported?.total_withheld_ar : data?.platform_reported?.total_withheld_en}
          </p>
        </section>

        {/*
         * CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 — how much of what the platforms claim is the same sale.
         *
         * Between the two measurements, because it is about the distance between them. One order
         * bought after a TikTok video AND a Meta retargeting ad is counted by both, and that is
         * invisible per platform: each figure is honest on its own terms.
         */}
        <Overlap overlap={data?.overlap} ar={ar} />

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
                    {ar ? `${num(m.campaigns)} حملة` : `${num(m.campaigns)} campaigns`}
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

function ClaimRow({ claim, ar, locale }: { claim: PlatformClaim; ar: boolean; locale: Locale }) {
  const a = claim.attribution

  return (
    <tr className="border-b border-border last:border-0">
      <td className="p-2 align-top font-semibold text-text-primary">{providerLabel(claim.provider, locale)}</td>
      {/*
       * An order COUNT and a MONEY amount stack rather than sitting side by side.
       *
       * Inline, «267» and «94K SAR» read as one token — live, the cell said «26794K SAR», which is
       * not a number this product has. A four-pixel margin is not a separator between two figures in
       * different units.
       */}
      <Pair
        primary={num(claim.platform_reported_orders)}
        secondary={money(claim.platform_reported_revenue, claim.currency ?? undefined)}
        unit={ar ? 'طلب' : 'orders'}
      />
      {/* Null, never a dash standing in for zero — «nobody checked» is not «the shop saw none». */}
      <td className="p-2 align-top text-text-secondary">
        {claim.store_confirmed_orders === null ? (
          <span className="text-text-muted">{ar ? 'لا يوجد متجر مربوط' : 'No store connected'}</span>
        ) : (
          <>
            <span className="block">
              {num(claim.store_confirmed_orders)}{' '}
              <span className="text-xs text-text-muted">{ar ? 'طلب' : 'orders'}</span>
            </span>
            <span className="block text-xs text-text-muted">
              {money(claim.store_confirmed_revenue ?? 0, claim.currency ?? undefined)}
            </span>
          </>
        )}
      </td>
      <td className="p-2 align-top text-text-secondary">
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
      </td>
      <td className="p-2 align-top text-text-secondary">
        {a.window_known ? (
          <>
            <span>
              {ar
                ? `نقرة ${a.click_through_days} يوم`
                : `${a.click_through_days}d click`}
            </span>
            <span className="ms-2">
              {a.view_through_days === null
                ? ar
                  ? '· بلا مشاهدة'
                  : '· no view-through'
                : ar
                  ? `· مشاهدة ${a.view_through_days} يوم`
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
      </td>
    </tr>
  )
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
    <td className="p-2 align-top text-text-secondary">
      <span className="block">
        {primary} <span className="text-xs text-text-muted">{unit}</span>
      </span>
      <span className="block text-xs text-text-muted">{secondary}</span>
    </td>
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
