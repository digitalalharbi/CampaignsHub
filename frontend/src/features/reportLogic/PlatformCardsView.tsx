import { ProviderName } from '@/components/ProviderMark'
import { platformColor } from '@/features/analytics/components'
import { compact, num } from '@/features/analytics/format'
import { Num } from '@/components/ui/Num'
import type { PlatformCardModel } from './platforms'

/**
 * REPORT-LOGIC-001 — the platforms summary's cards, drawn ONCE for the /r/ report and the analytics board.
 *
 * The markup is the report's own (moved from `ExecutiveSummary`, test ids unchanged under the default prefix); only the
 * source of the figures is shared logic — `platformCards()` — so the two surfaces cannot draw different numbers.
 */
/*
 * Seven digits is where the product compacts a count (owner-defect-sweep: «no surface writes a seven-figure count at full
 * width»); below it the exact figure stays, as everywhere else. The exact value travels as the element's title.
 */
const shown = (n: number | null): string => (n !== null && Math.abs(n) >= 1_000_000 ? compact(n) : num(n))

export function PlatformCardsView({ models, resultName, ar, selected, onSelect, testPrefix = 'exec', title }: {
  models: PlatformCardModel[]
  resultName: string
  ar: boolean
  selected: string | null
  onSelect: (platform: string | null) => void
  testPrefix?: string
  title?: string
}) {
  if (models.length === 0) return null
  const heading = title ?? (ar ? 'ملخص المنصات' : 'Platforms summary')

  const card = (m: PlatformCardModel) => {
    const on = selected === m.key
    const color = m.key === null ? 'var(--brand-600)' : platformColor(m.key)

    return (
      <button
        key={m.key ?? 'all'}
        type="button"
        role="tab"
        aria-selected={on}
        data-testid={`${testPrefix}-platform-card-${m.key ?? 'all'}`}
        onClick={() => onSelect(m.key === null || on ? null : m.key)}
        // A flex column: a button centres shorter content vertically, which pushed a card's colour bar down from its top edge.
        className={`flex min-w-0 flex-col overflow-hidden rounded-2xl border bg-surface text-start transition-colors ${on ? 'border-brand-500 ring-2 ring-brand-500/30' : 'border-border hover:border-brand-400'}`}
      >
        <span className="block h-1 w-full shrink-0" data-testid="exec-platform-card-bar" style={{ background: color }} aria-hidden />
        <span className="flex w-full flex-1 flex-col gap-0.5 p-2.5">
          {/* The platform's logo and name (Owner, 2026-10-19); «all platforms» is a word, not a platform. */}
          <span className="flex min-w-0 text-xs font-bold text-text-primary">
            {m.key === null ? <span className="truncate">{m.title}</span> : <ProviderName provider={m.key} locale={ar ? 'ar' : 'en'} size={16} />}
          </span>
          <span className="tnum truncate text-lg font-extrabold leading-tight text-text-primary"><Num>{shown(m.value)}</Num></span>
          <span className="truncate text-[11px] font-semibold text-text-muted">{resultName}</span>
          <span className="tnum mt-0.5 flex flex-wrap gap-x-2 gap-y-0.5 text-[11px] text-text-secondary">
            {m.impressions !== null && <span>{ar ? 'الظهور' : 'Impr.'} <Num>{shown(m.impressions)}</Num></span>}
            {m.rate !== null && <span>{ar ? 'معدل النقر' : 'CTR'} <Num>{m.rate}</Num></span>}
          </span>
          {m.share !== null && (
            <span className="mt-auto block h-1 overflow-hidden rounded-full bg-surface-secondary" aria-label={ar ? `الحصة من ${resultName}` : `Share of ${resultName}`}>
              <span className="block h-full rounded-full" style={{ width: `${Math.round(m.share * 100)}%`, background: color }} />
            </span>
          )}
        </span>
      </button>
    )
  }

  /*
   * REPORT-COMPOSITION-CONTROL-001 — the cards fill the row: exactly as many columns as cards (up to six).
   */
  return (
    <section data-testid={`${testPrefix}-platform-cards`} aria-label={heading} className="flex flex-col gap-2">
      <h2 className="text-base font-extrabold text-text-primary">{heading}</h2>
      <div
        role="tablist"
        data-cards={models.length}
        // REPORT-COMPACT-001 — Owner 2026-11-17: one compact row of platforms from a laptop width up, three on a tablet, two on a phone.
        className="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-3 lg:grid-cols-[repeat(var(--cards),minmax(0,1fr))]"
        style={{ '--cards': Math.min(models.length, 6) } as React.CSSProperties}
      >
        {models.map(card)}
      </div>
    </section>
  )
}
