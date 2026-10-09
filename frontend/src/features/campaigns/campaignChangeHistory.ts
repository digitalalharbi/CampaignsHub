import type { Locale } from '@/stores/ui'
import { moneyExact } from '@/features/analytics/format'
import { campaignStatusLabel, objectiveLabel, performanceLabel, priorityLabel, stageLabel } from './labels'

/**
 * CAMPAIGN-MGMT-CHANGE-HISTORY-001 — a change reads as what changed, in the reader's language.
 *
 * The audit log stores the campaign's columns as the server names them (`total_budget: 1000 → 2000`,
 * `status: active → paused`). The timeline showed exactly that: a column name and two stored tokens.
 * A reader who did not write the schema was being asked to translate it. Each field the campaign
 * audit records now has a name, and each value is said the way the rest of the product says it —
 * money as money, a status as its label, an absence as «—». A field this map does not know keeps its
 * stored name and value: never hidden, never guessed at.
 */
const FIELD: Record<string, { ar: string; en: string }> = {
  name: { ar: 'الاسم', en: 'Name' },
  status: { ar: 'الحالة', en: 'Status' },
  objective: { ar: 'الهدف', en: 'Objective' },
  total_budget: { ar: 'الميزانية الإجمالية', en: 'Total budget' },
  stage: { ar: 'المرحلة', en: 'Stage' },
  performance_label: { ar: 'تقييم الأداء', en: 'Performance label' },
  priority: { ar: 'الأولوية', en: 'Priority' },
  unified_campaign_id: { ar: 'الحملة الموحّدة', en: 'Unified campaign' },
  moved_from: { ar: 'نُقلت من', en: 'Moved from' },
}

export function changeFieldLabel(key: string, locale: Locale): string {
  const words = FIELD[key]
  return words ? (locale === 'ar' ? words.ar : words.en) : key
}

export function changeValueLabel(key: string, value: unknown, locale: Locale, currency: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  const s = String(value)
  switch (key) {
    case 'status': return campaignStatusLabel(s, locale)
    case 'objective': return objectiveLabel(s, locale)
    case 'stage': return stageLabel(s, locale)
    case 'performance_label': return performanceLabel(s, locale)
    case 'priority': return priorityLabel(s, locale)
    case 'total_budget': {
      const n = Number(value)
      return Number.isFinite(n) ? moneyExact(n, currency ?? undefined) : s
    }
    default: return s
  }
}

/** The fields whose stored value differs between before and after — the change, nothing else. */
export function changedFields(before: Record<string, unknown> | null, after: Record<string, unknown> | null): string[] {
  if (!before || !after) return []
  return Object.keys({ ...before, ...after }).filter((k) => String(before[k] ?? '') !== String(after[k] ?? ''))
}
