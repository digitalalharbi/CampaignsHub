/**
 * CONTENT-STUDIO-001 batch 5 — rhythm. Cuts and reveals are pulled onto the nearest beat of the chosen music when one is close enough;
 * otherwise they stay where the structure put them, so a missing or irregular track never moves the hook out of the first 3 seconds.
 */
export function snapToBeat(timeMs: number, beatsMs: ReadonlyArray<number>, toleranceMs = 200): number {
  let best = timeMs
  let bestD = toleranceMs + 1

  for (const b of beatsMs) {
    const d = Math.abs(b - timeMs)

    if (d < bestD) {
      bestD = d
      best = b
    }
  }

  return bestD <= toleranceMs ? best : timeMs
}

/** `count` cut times spread across [from, to) and snapped to the beats; strictly increasing, never outside the span. */
export function cutTimes(fromMs: number, toMs: number, count: number, beatsMs: ReadonlyArray<number> = [], toleranceMs = 200): number[] {
  const out: number[] = []
  const step = (toMs - fromMs) / count

  for (let i = 1; i < count; i++) {
    const snapped = snapToBeat(fromMs + step * i, beatsMs, toleranceMs)
    const t = Math.min(Math.max(snapped, fromMs + 1), toMs - 1)

    if (out.length === 0 || t > out[out.length - 1]!) out.push(t)
  }

  return out
}
