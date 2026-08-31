/**
 * ANALYTICS-FILTER-TRUTH-001 — what a panel says when it could not honour a filter.
 *
 * Every metrics endpoint is sent `provider`, `objective` and `campaign`. Most narrow by all three.
 * A few genuinely cannot: source health belongs to a connection rather than to a campaign, and the
 * store half of an attribution reconciliation has no campaign on it at all, so narrowing only the
 * platform half would manufacture a gap out of the filter and report it as an attribution finding.
 *
 * Declining is defensible. Declining in SILENCE is not — the panel then sits under chips naming one
 * campaign and answers for the whole project, and nothing on the screen says which question was
 * answered. The server names the axes it dropped; this turns that into the sentence.
 */
export interface FilterScope {
  applied: string[]
  unapplied: string[]
}

const AXIS: Record<string, { ar: string; en: string }> = {
  provider: { ar: 'المنصة', en: 'platform' },
  objective: { ar: 'الهدف', en: 'objective' },
  campaign: { ar: 'الحملة', en: 'campaign' },
}

/**
 * The sentence for a panel, or null when there is nothing to say.
 *
 * Null when no axis was declined — including when the reader has filtered nothing at all. A note
 * that appears on an unfiltered page is noise, and noise is how a real warning stops being read.
 */
export function scopeNote(scope: FilterScope | undefined, ar: boolean): string | null {
  const dropped = (scope?.unapplied ?? []).filter((axis) => axis in AXIS)
  if (dropped.length === 0) return null

  const names = dropped.map((axis) => (ar ? AXIS[axis].ar : AXIS[axis].en))
  const list = ar ? names.join(' و') : names.join(' and ')

  return ar
    ? `هذه اللوحة تشمل المشروع كامل: لا تضيّقها ${list}.`
    : `This panel covers the whole project — ${list} does not narrow it.`
}
