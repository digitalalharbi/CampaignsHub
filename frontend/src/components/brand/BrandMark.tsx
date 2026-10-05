import { useState } from 'react'

/**
 * REPORT-IDENTITY-PROPORTION-001 — an uploaded mark gets a PLATE, not a height.
 *
 * ## What «fixed height, free width» did
 *
 * Every surface that drew a brand mark set a height and let the width follow the artwork:
 * `h-6 w-auto`, `h-7 w-auto`, `h-4 w-auto`. For a wide wordmark that is fine. For anything tall it is
 * not: a 120×600 crest at `h-6` is SIX PIXELS wide, and what the client sees at the top of their
 * report is a coloured sliver that reads as a rendering fault rather than as a logo.
 *
 * Measured on the shared report, with a company wordmark and a client crest side by side:
 * the client's mark rendered 6×28 and the company's 72×16. Two marks on one line, at two different
 * heights, with widths an order of magnitude apart — there is no alignment to read, and the owner's
 * reading of it («الابعاد لليمين غير مناسب») is exactly right.
 *
 * ## The plate
 *
 * The height is fixed, the width is bounded at BOTH ends, and the artwork is contained and centred
 * inside. Three things follow:
 *
 *   - two marks on one line share a band, so they read as a pair rather than as two images that
 *     happen to be adjacent — the shared report drew them at 28px and 16px, and nothing about that
 *     said they were the same kind of thing;
 *   - a wide wordmark fills the plate to the `max`, exactly as it always did;
 *   - a tall crest is scaled to the band's height and sits centred in the `min`, which gives it
 *     balanced space instead of a sliver jammed against the name beside it.
 *
 * `object-contain` keeps doing the job it always did: nothing is cropped and nothing is stretched.
 *
 * A plate is reserved only when there IS a mark, and it is released when the mark fails to load. An
 * empty rectangle beside a name is a gap the reader has to interpret, and the documented fallback is
 * that a missing logo becomes the name.
 *
 * The sizes are a scale, not free numbers, so the same mark is recognisably the same object in a
 * form, in a report header and on a printed cover. What the operator should upload to fill one is
 * stated on the upload control itself {@see markSpec}.
 */
const PLATES = {
  /** A dense header line beside running text. */
  sm: 'h-6 min-w-[32px] max-w-[96px]',
  /** Panels and forms. */
  md: 'h-8 min-w-[40px] max-w-[140px]',
  /** The top of the report itself, and the printed cover. */
  lg: 'h-12 min-w-[56px] max-w-[200px]',
} as const

export type BrandMarkSize = keyof typeof PLATES

export function BrandMark({ src, alt = '', size = 'md', testid, className = '' }: {
  src: string
  /** Empty when the name is already written beside the mark — the mark is then decorative. */
  alt?: string
  size?: BrandMarkSize
  testid?: string
  className?: string
}) {
  /*
   * A mark that fails to load takes its PLATE with it.
   *
   * The other surfaces hid the broken `<img>` and that was the whole repair, because the image was
   * the only thing occupying space. A plate is reserved space, so hiding only the image would leave
   * a 140×32 hole in the header next to the name — a gap a reader has to interpret, and exactly what
   * the fallback rule exists to avoid. Caught here and not by the caller: the plate is this
   * component's, so clearing it is this component's job.
   *
   * It happens for real: a row can outlive its file, and a logo URL is resolved from the row.
   */
  const [broken, setBroken] = useState(false)

  if (broken) {
    return null
  }

  return (
    <span
      className={`flex shrink-0 items-center justify-center ${PLATES[size]} ${className}`}
      data-testid={testid === undefined ? undefined : `${testid}-plate`}
    >
      <img
        src={src}
        alt={alt}
        data-testid={testid}
        onError={() => setBroken(true)}
        className="max-h-full max-w-full object-contain"
      />
    </span>
  )
}
