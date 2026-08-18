/**
 * INTEG-RUNTIME §8 — the six words a sync may say, and the ONE place that decides their colour.
 *
 * ## Why this is a module and not three copies
 *
 * It was three copies. `PlatformIntegrationsPanel` and `CampaignDepthTabs` each held a `SYNC_TONE`
 * map, `AnalyticsPage` inlined a ternary chain of Tailwind classes, and `StoresPanel` compared
 * against a bare string. Four surfaces, four independent opinions about what «partial» means and what
 * colour it is — so the same run could be amber in one place and green two clicks away, and adding a
 * status to the backend silently produced an unstyled label in whichever copy nobody remembered.
 *
 * ## The colours are an argument, not a palette
 *
 * `no_data` is **neutral, never red or amber**. «The provider had no rows for this window» is the
 * ordinary answer for a paused campaign or a quiet weekend, and painting it as a problem is how a
 * customer learns to ignore the one row that is a problem. `partial_mapping` IS amber, because rows
 * the product could not place are figures missing from a client's report. `awaiting_assignment` is
 * amber too: nothing is broken, but nothing will happen until somebody chooses a project.
 */
export const SYNC_STATUSES = [
  'running', 'success', 'no_data', 'partial_mapping', 'failed', 'awaiting_assignment',
] as const

export type SyncStatus = (typeof SYNC_STATUSES)[number]

export type SyncTone = 'success' | 'warning' | 'danger' | 'neutral'

export interface SyncStatusMeaning {
  tone: SyncTone
  ar: string
  en: string
  /** The one-line explanation of what the word means, for a tooltip or a caption. */
  hint_ar: string
  hint_en: string
}

const MEANINGS: Record<SyncStatus, SyncStatusMeaning> = {
  running: {
    tone: 'neutral', ar: 'قيد التنفيذ', en: 'Running',
    hint_ar: 'بدأت ولم تنتهِ بعد.', hint_en: 'Started, not finished yet.',
  },
  success: {
    tone: 'success', ar: 'ناجحة', en: 'Succeeded',
    hint_ar: 'وصلت البيانات وحُفظت كاملة.', hint_en: 'The data arrived and was stored in full.',
  },
  no_data: {
    tone: 'neutral', ar: 'لا توجد بيانات للفترة', en: 'No data for the period',
    hint_ar: 'سألنا المنصة وأجابت بأنه لم يحدث نشاط في هذه الفترة. ليست مشكلة.',
    hint_en: 'We asked the platform and it reported no activity in this window. Not a problem.',
  },
  partial_mapping: {
    tone: 'warning', ar: 'صفوف لم تُطابَق', en: 'Rows not matched',
    hint_ar: 'أرسلت المنصة صفوفًا تخص حملات لم تُكتشف بعد، فلم تُحفظ.',
    hint_en: 'The platform sent rows for campaigns not discovered yet, so they were not stored.',
  },
  failed: {
    tone: 'danger', ar: 'فشلت', en: 'Failed',
    hint_ar: 'لم نتمكن من إتمام الطلب.', hint_en: 'The request could not be completed.',
  },
  awaiting_assignment: {
    tone: 'warning', ar: 'بانتظار ربط بمشروع', en: 'Awaiting a project',
    hint_ar: 'لم يُحدَّد بعد المشروع الذي يتغذّى من هذا الحساب، فلم تُجلَب أي بيانات.',
    hint_en: 'Nobody has said which project this account feeds, so nothing was fetched.',
  },
}

/**
 * What a status means, including for a word this build has never heard of.
 *
 * An unknown status is NEUTRAL and is shown verbatim. Colouring it green would dress an unknown as a
 * success; colouring it red would raise an alarm about a word that may be perfectly ordinary.
 */
export function syncStatusMeaning(status: string | null | undefined): SyncStatusMeaning {
  if (status && status in MEANINGS) return MEANINGS[status as SyncStatus]

  return {
    tone: 'neutral',
    ar: status ?? '—', en: status ?? '—',
    hint_ar: '', hint_en: '',
  }
}

/** The label in the reader's language. */
export function syncStatusLabel(status: string | null | undefined, ar: boolean): string {
  const meaning = syncStatusMeaning(status)

  return ar ? meaning.ar : meaning.en
}

/** Whether a human has something to do about this outcome. `no_data` and `success` do not qualify. */
export function syncStatusNeedsAttention(status: string | null | undefined): boolean {
  return status === 'failed' || status === 'partial_mapping' || status === 'awaiting_assignment'
}
