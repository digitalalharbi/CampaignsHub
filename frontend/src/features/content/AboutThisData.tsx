import { Info } from 'lucide-react'
import type { Locale } from '@/stores/ui'

/**
 * CONTENT-RESULT-AVAILABILITY-001 §17 — one sentence, so a gap reads as a provider fact.
 *
 * A reader meeting «—» on three cards and a video with no cover on a fourth draws one of two
 * conclusions, and only one of them is true: either advertising platforms expose different things
 * for different accounts, or this product is broken. Every individual absence already says which —
 * that is what the last two units were about — but the reader has to assemble the pattern from four
 * separate tooltips.
 *
 * So the page says it once, in the header, where somebody can read it before they start wondering.
 * One sentence and no more: «تعتمد معاينات المحتوى ومؤشراته على البيانات التي توفرها المنصة
 * الإعلانية للحساب والفترة المحددين». It is not an explanation of the pipeline and it must not grow
 * into one — a technical essay in a header is how a product admits it expects to be doubted.
 */
export function AboutThisData({ locale, testid = 'about-this-data' }: { locale: Locale; testid?: string }) {
  const ar = locale === 'ar'

  return (
    <span
      data-testid={testid}
      /*
       * `title`, like the unavailable figures use: one hover text a screen reader, a long-press and
       * a keyboard focus all already understand, on a line that must not grow a popover.
       */
      title={ar
        ? 'تعتمد معاينات المحتوى ومؤشراته على البيانات التي توفرها المنصة الإعلانية للحساب والفترة المحددين.'
        : 'Content previews and figures depend on what the advertising platform provides for the selected account and period.'}
      className="inline-flex cursor-help items-center gap-1 text-text-muted hover:text-text-secondary"
    >
      <Info size={12} aria-hidden />
      {ar ? 'حول هذه البيانات' : 'About this data'}
    </span>
  )
}
