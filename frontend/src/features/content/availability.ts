import type { Locale } from '@/stores/ui'

/**
 * CONTENT-STATE-SEMANTICS-001 — an empty card says WHY it is empty.
 *
 * Every creative without figures rendered «لا توجد بيانات». That one sentence was covering four
 * situations an operator would act on differently, and the frontend had no way to tell them apart:
 * an empty metrics object looks identical whether the platform was never asked, the request failed,
 * or the creative simply did not run.
 *
 * So the answer comes from the backend's own record of the sync (`metrics_availability`), never from
 * inspecting zeros. `MetricsAvailability` is what `AccountMetricsSyncer` wrote at the moment it knew.
 */
export type MetricsAvailability = {
  /** success | unsupported | failed | skipped | unknown */
  status: string
  rows: number | null
  error: string | null
  at: string | null
}

export type EmptyReason =
  /** The fetch worked; this creative had no delivery in the selected period. Nothing is wrong. */
  | { kind: 'did_not_run'; text: string; tone: 'muted' }
  /** This platform has no creative-level reporting. The number does not exist to be fetched. */
  | { kind: 'unsupported'; text: string; tone: 'muted' }
  /** Numbers exist at the platform and we do not have them. This is a pipeline problem. */
  | { kind: 'failed'; text: string; detail: string | null; tone: 'warning' }
  /** No recorded attempt — a run predating the record, or a project that has never synced. */
  | { kind: 'unknown'; text: string; tone: 'muted' }
  /**
   * CONTENT-KPI-EMPTY-STATE-001 — the creative DID run; none of its figures can be headlined.
   *
   * Distinct from `did_not_run`, and the distinction is not cosmetic. A creative with a metrics
   * object delivered — the platform answered for it — and saying «لم يعمل خلال هذه الفترة» over
   * that is a false statement about the campaign, which an operator would act on by leaving alone
   * a creative that is actually running.
   */
  | { kind: 'no_displayable'; text: string; tone: 'muted' }
  /**
   * CONTENT-AD-DELIVERED-001 — the AD ran; the platform named no figures for THIS creative.
   *
   * Production holds 35 creatives in exactly this state, and `did_not_run` is a false statement
   * about every one of them — false in the direction that costs money, because an operator reads it
   * and leaves a live creative alone. The platform reported the ad and simply does not break that
   * result down to the creative.
   */
  | { kind: 'creative_grain_missing'; text: string; tone: 'muted' }

const COPY = {
  did_not_run: {
    ar: 'لم يعمل خلال هذه الفترة',
    en: 'Did not run in this period',
  },
  unsupported: {
    ar: 'هذه المنصة لا توفّر بيانات أداء لكل إعلان',
    en: 'This platform does not report per-ad performance',
  },
  failed: {
    ar: 'تعذّر جلب بيانات الأداء',
    en: 'Performance data could not be fetched',
  },
  unknown: {
    ar: 'لا تتوفر بيانات أداء',
    en: 'No performance data available',
  },
  no_displayable: {
    ar: 'لا توجد مؤشرات أداء قابلة للعرض لهذه الفترة',
    en: 'No displayable performance metrics for this period',
  },
  creative_grain_missing: {
    ar: 'المنصة لم تُرجع مؤشرات على مستوى هذا الإعلان لهذه الفترة',
    en: 'The platform did not return ad-level metrics for this period',
  },
} as const

/**
 * Why this creative has no figures — given what the sync recorded for its provider.
 *
 * Called only for a creative that HAS no metrics; one that has them needs no explanation. The
 * distinction that matters most is `failed` versus `did_not_run`: they look the same on screen and
 * call for opposite actions, so `failed` is the one state rendered as a warning rather than as
 * quiet grey text.
 */
export function emptyReason(
  availability: MetricsAvailability | undefined,
  locale: Locale,
): EmptyReason {
  const ar = locale === 'ar'
  const text = (key: keyof typeof COPY) => (ar ? COPY[key].ar : COPY[key].en)

  switch (availability?.status) {
    case 'success':
      // The platform answered for this account and window. The silence is about this creative.
      return { kind: 'did_not_run', text: text('did_not_run'), tone: 'muted' }

    case 'unsupported':
      return { kind: 'unsupported', text: text('unsupported'), tone: 'muted' }

    case 'failed':
      return {
        kind: 'failed',
        text: text('failed'),
        // The provider's own words, when there are any — «rate limited» is actionable in a way
        // that «no data» never was.
        detail: availability?.error ?? null,
        tone: 'warning',
      }

    default:
      // `skipped`, `unknown`, or no record at all. Do not invent a reason we were not told.
      return { kind: 'unknown', text: text('unknown'), tone: 'muted' }
  }
}

/**
 * The creative ran and reported something, but nothing its objective can be headlined on.
 *
 * Deliberately NOT derived from `metrics_availability`: that record answers «what happened to the
 * REQUEST for this provider», and here the request succeeded. The question this answers is about one
 * creative's figures, so routing it through the availability switch would borrow a sentence written
 * for a different question — which is exactly how «did not run» came to be printed over a creative
 * that did.
 */
export function noDisplayableMetrics(locale: Locale): EmptyReason {
  return {
    kind: 'no_displayable',
    text: locale === 'ar' ? COPY.no_displayable.ar : COPY.no_displayable.en,
    tone: 'muted',
  }
}

/**
 * The creative's own figures are absent, but its AD delivered in this window.
 *
 * Chosen ahead of `metrics_availability` on purpose. That record answers «what happened to the
 * REQUEST for this provider», and the request succeeded — so it would return `did_not_run`, which is
 * the one sentence this state must never print.
 */
export function creativeGrainMissing(locale: Locale): EmptyReason {
  return {
    kind: 'creative_grain_missing',
    text: locale === 'ar' ? COPY.creative_grain_missing.ar : COPY.creative_grain_missing.en,
    tone: 'muted',
  }
}
