import { days as countedDays } from '@/lib/counted'
import type { FreshnessRow } from './api'
import type { SyncStatus } from '@/lib/syncStatus'

/**
 * DATA-QUALITY-OPERATOR-UX-001 — written for the person who has to act, not the one who built it.
 *
 * ## What this tab was
 *
 * A table: platform · latest date · last sync · days with data · missing days · status. Every column
 * is true, and together they answer a question an administrator asks — «is the pipeline healthy?».
 * The person who actually opens this tab is an account manager whose client has just asked why last
 * week looks thin, and they need six different answers: what is wrong, what it affects, how much it
 * matters, what they can check themselves, whether the system can fix it, and whether somebody has
 * to go and get a credential.
 *
 * A row of six technical columns makes them derive all six, every time, from numbers whose
 * relationship they have to already know. So the table stays — nothing here hides detail — and the
 * findings above it say the thing the table implies.
 *
 * ## Nothing here is a new measurement
 *
 * Every finding is derived from the freshness row the tab already reads. Inventing a second source
 * for «is this platform healthy» is how two surfaces come to disagree about one account.
 */
export type FindingSeverity = 'critical' | 'attention' | 'watch'

/** Who can end it. The distinction the requirement asks for, and the one an operator needs first. */
export type FindingOwner = 'system' | 'operator' | 'credentials' | 'provider'

export interface QualityFinding {
  key: string
  provider: string
  name: string | null
  severity: FindingSeverity
  owner: FindingOwner
  /** What is wrong · what it affects · what you can check — one sentence each, in both languages. */
  what: { ar: string; en: string }
  affects: { ar: string; en: string }
  check: { ar: string; en: string }
  /** The window's coverage for this source, when it is countable: 0–1, or null for a store. */
  coverage: number | null
}

const SEVERITY_ORDER: Record<FindingSeverity, number> = { critical: 0, attention: 1, watch: 2 }

/**
 * How many days a source may be silent before silence is worth a sentence.
 *
 * Platforms settle the previous day at their own pace and a same-day gap is normal everywhere, so
 * one day is not a finding. Two is: by then every platform in this product has reported, and a
 * source that has not is either broken or paused — and the finding says which it cannot tell.
 */
const STALE_DAYS = 2

export function findingsFor(rows: FreshnessRow[], today: Date): QualityFinding[] {
  return rows
    .flatMap((row) => findingFor(row, today) ?? [])
    .sort((a, b) => SEVERITY_ORDER[a.severity] - SEVERITY_ORDER[b.severity] || a.provider.localeCompare(b.provider))
}

function findingFor(row: FreshnessRow, today: Date): QualityFinding[] | null {
  const status = (row.last_sync_status ?? '') as SyncStatus
  const coverage = coverageOf(row)
  const base = { key: `${row.provider}:${row.account_id ?? 'all'}`, provider: row.provider, name: row.name, coverage }

  if (status === 'failed') {
    return [{
      ...base,
      severity: 'critical',
      /*
       * A failed sync may or may not be a credential problem, and the row does not say which. The
       * owner is therefore `provider` — «somebody must look at the platform side» — rather than
       * `credentials`, which would send an operator to re-authorise an integration that is fine.
       */
      owner: 'provider',
      what: {
        ar: 'آخر مزامنة من هذه المنصة فشلت.',
        en: 'The last sync from this platform failed.',
      },
      affects: {
        ar: `كل رقم لهذه المنصة منذ ${row.latest_metric_date ?? 'آخر يوم وصل'} — الإجماليات أقل من الحقيقة، وليست صفرًا.`,
        en: `Every figure for this platform since ${row.latest_metric_date ?? 'its last day'} — the totals are lower than the truth, not zero.`,
      },
      check: {
        ar: 'افتح سجل المزامنة لهذه المنصة واقرأ نص الخطأ؛ إن ذكر الصلاحيات فالربط يحتاج إعادة تفويض.',
        en: 'Open this platform’s sync log and read the error; if it mentions permissions, the connection needs re-authorising.',
      },
    }]
  }

  if (status === 'awaiting_credentials') {
    return [{
      ...base,
      severity: 'attention',
      owner: 'credentials',
      what: { ar: 'هذه المنصة غير مهيَّأة بعد.', en: 'This platform is not configured yet.' },
      affects: {
        ar: 'لا شيء منها في أي رقم على هذه الصفحة — وهذا ليس عطلًا.',
        en: 'None of it is in any figure on this page — and that is not a fault.',
      },
      check: {
        ar: 'يحتاج الأمر بيانات اعتماد من صاحب الحساب الإعلاني؛ لا يمكن للنظام إنهاؤه.',
        en: 'It needs credentials from whoever owns the ad account; the system cannot finish this on its own.',
      },
    }]
  }

  if (status === 'awaiting_assignment') {
    return [{
      ...base,
      severity: 'attention',
      owner: 'operator',
      what: { ar: 'حساب مرتبط ولم يُسنَد إلى مشروع.', en: 'An account is connected and assigned to no project.' },
      affects: {
        ar: 'بياناته تصل ولا تظهر في أي مشروع — فلا تُحتسب في أي تقرير.',
        en: 'Its data arrives and appears under no project, so no report counts it.',
      },
      check: {
        ar: 'أسنِد الحساب إلى مشروعه من صفحة التكاملات — خطوة واحدة، ويمكنك إنهاؤها الآن.',
        en: 'Assign the account to its project on the integrations page — one step, and you can do it now.',
      },
    }]
  }

  const silent = daysSince(row.data_freshness_at ?? row.latest_metric_date, today)

  if (status === 'stale' || (silent !== null && silent >= STALE_DAYS)) {
    return [{
      ...base,
      severity: 'attention',
      owner: 'system',
      what: {
        ar: `لم تصل بيانات من هذه المنصة منذ ${countedDays(silent ?? STALE_DAYS, 'ar')}.`,
        en: `No data has arrived from this platform for ${countedDays(silent ?? STALE_DAYS, 'en')}.`,
      },
      affects: {
        ar: 'الأيام الأخيرة في هذه الفترة ناقصة، فالإجماليات أقل مما ستكون عليه بعد اللحاق.',
        en: 'The last days of this window are missing, so the totals are lower than they will be once it catches up.',
      },
      check: {
        ar: 'المزامنة تعمل تلقائيًا وقد تلحق وحدها؛ إن بقيت متأخرة غدًا فهي عطل يستحق فتح السجل.',
        en: 'The sync runs on its own and may catch up; if it is still behind tomorrow, that is a fault worth opening the log for.',
      },
    }]
  }

  /*
   * A hole in the MIDDLE of a window is a different sentence from a late tail, and it is the one a
   * client notices: a chart with a dip nobody caused. It is reported at `watch` because a paused
   * campaign produces exactly the same shape, and this row cannot tell the two apart.
   */
  if ((row.missing_days ?? 0) > 0) {
    return [{
      ...base,
      severity: 'watch',
      owner: 'system',
      what: {
        ar: `${countedDays(row.missing_days ?? 0, 'ar')} داخل الفترة بلا بيانات من هذه المنصة.`,
        en: `${countedDays(row.missing_days ?? 0, 'en')} inside this window have no data from this platform.`,
      },
      affects: {
        ar: 'المنحنى يهبط في تلك الأيام، والمتوسطات محسوبة على أيام أقل مما تظن.',
        en: 'The chart dips on those days, and the averages are over fewer days than they look.',
      },
      check: {
        ar: 'إن كانت الحملة متوقفة في تلك الأيام فهذا هو الجواب؛ وإن كانت تعمل، فالمزامنة فوّتتها.',
        en: 'If the campaign was paused on those days, that is the answer; if it was running, the sync missed them.',
      },
    }]
  }

  return null
}

/** The share of the window this source actually covered, or null where days are not the unit. */
function coverageOf(row: FreshnessRow): number | null {
  const withData = row.days_with_data
  const missing = row.missing_days

  if (withData === null || missing === null) {
    return null
  }

  const total = withData + missing

  return total > 0 ? Math.round((withData / total) * 100) / 100 : null
}

function daysSince(date: string | null, today: Date): number | null {
  if (!date) {
    return null
  }

  const then = new Date(date)

  if (Number.isNaN(then.getTime())) {
    return null
  }

  return Math.max(0, Math.floor((today.getTime() - then.getTime()) / 86_400_000))
}

/**
 * DATA-QUALITY-OPERATOR-UX-001 — how much of this window is actually measured, said as a level with
 * the numbers that produced it.
 *
 * ## Why a level and not a percentage on its own
 *
 * «Confidence: 84%» is a figure nobody can act on and nobody can check. It also claims a precision
 * this data does not have: the denominator is days, the numerator is days a platform reported, and
 * neither knows whether a missing day was a broken sync or a campaign nobody was running. So the
 * level is the headline — high, moderate, low — and the inputs that produced it are printed beside
 * it: how many days of how many, across how many sources, and which source is the reason.
 *
 * ## Stores are excluded from the count, not hidden
 *
 * A store's coverage is not measured in days with ad data — there is no daily row to be missing —
 * so counting it would make the fraction a different thing per tenant. It is stated separately.
 *
 * ## The level is the worst honest reading
 *
 * A window with 99% coverage and one source whose authorisation has failed is not «high»: the missing
 * source is missing entirely, and the days it never reported are not in the denominator at all. A
 * critical or attention finding therefore caps the level, whatever the arithmetic says.
 */
export type ConfidenceLevel = 'high' | 'moderate' | 'low'

export interface WindowConfidence {
  level: ConfidenceLevel
  /** Days reported over days expected, across the countable sources — or null when none are. */
  coverage: number | null
  daysWithData: number
  daysExpected: number
  /** Countable ad sources, and the ones excluded because days are not their unit. */
  sourcesCounted: number
  sourcesNotCountable: number
  /** The single source most responsible, when there is one. */
  worst: { provider: string; name: string | null } | null
}

export function windowConfidence(rows: FreshnessRow[], windowDays: number, findings: QualityFinding[]): WindowConfidence {
  const countable = rows.filter((r) => r.kind !== 'store' && r.days_with_data !== null)
  const notCountable = rows.length - countable.length

  const daysExpected = countable.length * Math.max(0, windowDays)
  const daysWithData = countable.reduce((n, r) => n + Math.min(r.days_with_data ?? 0, windowDays), 0)
  const coverage = daysExpected === 0 ? null : daysWithData / daysExpected

  const blocking = findings.filter((f) => f.severity === 'critical' || f.severity === 'attention')

  const arithmetic: ConfidenceLevel =
    coverage === null ? 'low' : coverage >= 0.95 ? 'high' : coverage >= 0.8 ? 'moderate' : 'low'

  const level: ConfidenceLevel =
    blocking.some((f) => f.severity === 'critical') ? 'low'
      : blocking.length > 0 ? (arithmetic === 'high' ? 'moderate' : arithmetic)
        : arithmetic

  /*
   * «Which source is the reason» — the finding that outranks the rest, or failing that the countable
   * source with the fewest days. Named so the reader has somewhere to go rather than a global number.
   */
  const worstFinding = blocking[0] ?? findings[0] ?? null
  const thinnest = countable.length === 0
    ? null
    : countable.reduce((a, b) => ((a.days_with_data ?? 0) <= (b.days_with_data ?? 0) ? a : b))

  const worst = worstFinding !== null
    ? { provider: worstFinding.provider, name: worstFinding.name }
    : thinnest !== null && (thinnest.days_with_data ?? 0) < windowDays
      ? { provider: thinnest.provider, name: thinnest.name }
      : null

  return {
    level,
    coverage,
    daysWithData,
    daysExpected,
    sourcesCounted: countable.length,
    sourcesNotCountable: notCountable,
    worst,
  }
}
