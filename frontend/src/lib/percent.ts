/**
 * PROJECT-LIFECYCLE-001 §8 — «الإعداد» showed 10000% instead of 100%.
 *
 * `setup_completion` arrives from the server ALREADY as a whole percentage (0–100, `ProjectStatusDisplay::setupCompletion`),
 * and the workspace-settings table multiplied it by 100 a second time. Every place that shows a value that is already
 * 0–100 goes through this one formatter, so the scale is decided once: never ×100 here, clamped to 0–100, Latin digits.
 */
export function formatWholePercent(value: number | null | undefined): string {
  const n = typeof value === 'number' && Number.isFinite(value) ? Math.round(value) : 0

  return `${Math.min(100, Math.max(0, n))}%`
}
