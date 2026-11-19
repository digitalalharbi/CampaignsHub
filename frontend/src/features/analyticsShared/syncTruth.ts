/**
 * ANALYTICS-SYNC-TRUTH-001 — «آخر مزامنة» is the latest SUCCESSFUL sync, not the newest stored figure.
 *
 * The head of Analytics read `data_freshness_at` (the newest metric row), so an account that synced
 * successfully and delivered nothing — zero spend is data, not a gap — read «لم تصل بيانات بعد». A run that
 * reached the provider counts: the server says so in `last_success_at`, and this is the one reader of it.
 *
 * A row from an install answering from before the field existed falls back to the newest stored figure.
 */
export interface SyncRow {
  last_success_at?: string | null
  data_freshness_at?: string | null
}

export function lastSuccessfulSync(rows: readonly SyncRow[]): string | null {
  let best: string | null = null

  for (const row of rows) {
    for (const at of [row.last_success_at, row.data_freshness_at]) {
      if (at && (best === null || at > best)) best = at
    }
  }

  return best
}
