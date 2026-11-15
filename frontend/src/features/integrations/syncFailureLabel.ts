import { accountHealthLabel } from './accountHealth'
import { days, hours, minutes } from '@/lib/counted'

/**
 * SYNC-FAILURE-LABEL-001 — what an account's `last_sync_error_category` means, in plain Saudi Arabic, in ONE place.
 *
 * The category is a word from the closed list the sync writes (AccountMetricsSyncer). Every sentence here is one the
 * product already says elsewhere (accountHealth, the status model, the provider-error readings): nothing is added, and
 * a category this table does not know is called «فشلت آخر مزامنة» — never a guessed cause.
 */
const CATEGORY: Record<string, { ar: string; en: string }> = {
  awaiting_credentials: { ar: 'بانتظار بيانات اعتماد المنصة', en: 'Waiting for the platform credentials' },
  awaiting_assignment: { ar: 'الحساب غير مربوط بمشروع', en: 'The account is not linked to a project' },
  token_invalidated: { ar: 'لم تعد المنصة تقبل التفويض — أعد الربط', en: 'The platform no longer accepts the authorisation — reconnect' },
  unmapped_rows: { ar: 'بعض الحملات لم تُطابَق', en: 'Some campaigns could not be matched' },
}

export function syncFailureLabel(category: string | null | undefined, ar: boolean): string {
  if (!category) return ar ? 'فشلت آخر مزامنة' : 'Last sync failed'
  if (category === 'insights_not_authorised') return accountHealthLabel('insights_not_authorised', ar)
  const hit = CATEGORY[category]

  return hit ? (ar ? hit.ar : hit.en) : (ar ? 'فشلت آخر مزامنة' : 'Last sync failed')
}

/** How old the last good sync is, in whole minutes / hours / days — through the shared counted nouns. */
export function dataAge(iso: string | null | undefined, ar: boolean, now: Date = new Date()): string {
  if (!iso) return ar ? 'لم تتزامن بعد' : 'Never synced'
  const locale = ar ? 'ar' : 'en'
  const total = Math.max(0, Math.round((now.getTime() - new Date(iso).getTime()) / 60_000))
  const span = total < 60 ? minutes(total, locale) : total < 48 * 60 ? hours(Math.floor(total / 60), locale) : days(Math.floor(total / 1440), locale)

  return ar ? `قبل ${span}` : `${span} ago`
}
