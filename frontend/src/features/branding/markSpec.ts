/**
 * REPORT-IDENTITY-PROPORTION-001 — what a brand mark has to BE, said once.
 *
 * The operator was asked for a logo and told nothing about it: no format, no size, no shape. What
 * came back was whatever was to hand — and a 120×600 crest in a header that sizes by height is a
 * six-pixel sliver. Guidance is half of the fix; `BrandMark`'s plate is the other half, and the two
 * have to agree, so the frame quoted to the operator is the frame the mark is actually drawn in.
 *
 * One module rather than a sentence beside each control: the `accept` attribute, the help text and
 * the plate all read from here, so the screen cannot promise one thing and do another.
 *
 * The LIMITS mirror `BrandingSpec::ALLOWED_MIME` and `BrandingSpec::MAX_BYTES`, which remain the
 * authority — the server reads the file rather than its name and refuses it there. These exist to
 * say the rule BEFORE the upload; the server's own refusal is still what is shown after one.
 */

/** The four types a brand kit ships, in the form an `<input accept>` wants. */
export const MARK_ACCEPT = 'image/svg+xml,image/png,image/jpeg,image/webp'

/** `BrandingSpec::MAX_BYTES`. */
export const MARK_MAX_MB = 2

/** The plate a report cover draws the mark in — `BrandMark`'s `lg` size. */
export const MARK_FRAME = { width: 200, height: 48 } as const

/** What to upload so the mark fills that plate instead of sitting in a corner of it. */
export const MARK_SUGGESTED = { width: 600, height: 120, maxRatio: 5 } as const

/**
 * The one line shown under the upload controls.
 *
 * Latin digits in both languages — NUMERAL-PREFERENCE: the language of the sentence never decides
 * the numerals.
 */
export function markGuidance(ar: boolean): string {
  const { width, height, maxRatio } = MARK_SUGGESTED
  const frame = `${MARK_FRAME.width}×${MARK_FRAME.height}`

  return ar
    ? `SVG أو PNG بخلفية شفافة (JPG وWebP مقبولة) · المقاس المقترح ${width}×${height} بكسل، أفقي حتى ${maxRatio}:1 · حتى ${MARK_MAX_MB} ميجابايت · يُعرض كاملاً داخل إطار ${frame} بكسل، بلا قص ولا تمديد.`
    : `SVG or PNG on a transparent background (JPG and WebP accepted) · ${width}×${height} px suggested, horizontal up to ${maxRatio}:1 · ${MARK_MAX_MB} MB max · shown whole inside a ${frame} px frame, never cropped or stretched.`
}
