/**
 * The centre's tables page at 10 / 25 / 50 — the Owner's sizes, in one control.
 *
 * Latin digits always: the numerals are a preference of their own and never follow the language.
 */
export const PAGE_SIZES = [10, 25, 50] as const
export type PageSize = typeof PAGE_SIZES[number]

export function PageSizer({ value, onChange, ar, testId }: {
  value: number
  onChange: (size: PageSize) => void
  ar: boolean
  testId: string
}) {
  return (
    <label className="flex items-center gap-1.5 text-xs text-text-secondary">
      {ar ? 'لكل صفحة' : 'Per page'}
      <select
        value={value}
        onChange={(e) => onChange(Number(e.target.value) as PageSize)}
        data-testid={testId}
        className="tnum rounded-lg border border-border bg-background px-2 py-1 text-xs text-text-primary focus:border-brand-500 focus:outline-none"
      >
        {PAGE_SIZES.map((size) => <option key={size} value={size}>{size.toLocaleString('en-US')}</option>)}
      </select>
    </label>
  )
}

/** Previous / «page of pages» / next, with Latin digits. */
export function Pager({ page, lastPage, onPage, ar, testId }: {
  page: number
  lastPage: number
  onPage: (page: number) => void
  ar: boolean
  testId: string
}) {
  return (
    <div className="flex items-center gap-2 text-xs" data-testid={testId}>
      <button
        type="button"
        disabled={page <= 1}
        onClick={() => onPage(page - 1)}
        data-testid={`${testId}-prev`}
        className="rounded-lg border border-border px-2.5 py-1 font-semibold text-text-primary transition-colors hover:bg-surface-hover disabled:cursor-not-allowed disabled:opacity-40"
      >
        {ar ? 'السابق' : 'Previous'}
      </button>
      <span className="tnum text-text-muted" data-testid={`${testId}-position`}>
        {page.toLocaleString('en-US')} / {lastPage.toLocaleString('en-US')}
      </span>
      <button
        type="button"
        disabled={page >= lastPage}
        onClick={() => onPage(page + 1)}
        data-testid={`${testId}-next`}
        className="rounded-lg border border-border px-2.5 py-1 font-semibold text-text-primary transition-colors hover:bg-surface-hover disabled:cursor-not-allowed disabled:opacity-40"
      >
        {ar ? 'التالي' : 'Next'}
      </button>
    </div>
  )
}
