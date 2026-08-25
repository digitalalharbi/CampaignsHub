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
/**
 * FRESHNESS-STATUS-001 — two vocabularies reach this module, and only one was mapped.
 *
 * A sync RUN reports how the last attempt went: `running`, `success`, `partial_mapping`,
 * `awaiting_assignment`. A SOURCE reports how current its data is: `fresh`, `stale`,
 * `awaiting_credentials`. `no_data` and `failed` belong to both.
 *
 * `DataFreshnessService::verdict()` returns the second set, and the metrics controller sends it as
 * `last_sync_status` — so the data-quality table fed freshness words into a map of run words. The
 * pill prints whatever it is given, so «fresh» appeared as the status of every healthy platform, in
 * English, on the tab whose subject is whether the numbers can be trusted.
 *
 * Both sets are named here rather than the caller translating between them: they are genuinely
 * different answers to different questions, and collapsing them would lose that.
 */
export const SYNC_STATUSES = [
  'running', 'success', 'no_data', 'partial_mapping', 'failed', 'awaiting_assignment',
  'fresh', 'stale', 'awaiting_credentials',
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
  fresh: {
    tone: 'success', ar: 'حديثة', en: 'Fresh',
    hint_ar: 'بيانات هذه المنصة محدَّثة حتى آخر يوم مكتمل.',
    hint_en: "This platform's data is current to the last complete day.",
  },
  stale: {
    tone: 'warning', ar: 'متأخرة', en: 'Stale',
    hint_ar: 'مضى وقت على آخر يوم وصلت فيه بيانات من هذه المنصة — الأرقام أقدم مما تبدو.',
    hint_en: 'It has been a while since this platform last sent a day — the figures are older than they look.',
  },
  awaiting_credentials: {
    tone: 'neutral', ar: 'بانتظار بيانات الاعتماد', en: 'Awaiting credentials',
    hint_ar: 'لم تُهيَّأ هذه المنصة بعد، فلا يوجد ما يُزامَن. ليست عطلًا.',
    hint_en: 'This platform is not configured yet, so there is nothing to sync. Not a fault.',
  },
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
