/**
 * CONTENT-STUDIO-001 batch 5 — fitting copy into its box. The measuring function is INJECTED: in the browser it is
 * `ctx.measureText`, in a Worker or a test it is `approxMeasure`. The same fit therefore runs in the previewer and in the exporter,
 * and an Arabic line is measured at its real advance, not at a Latin guess, once the real measurer is passed in.
 */
export type Measure = (text: string, px: number) => number

/** A stand-in measurer: Arabic and Latin average about half an em; digits and spaces a little less. Deterministic. */
export const approxMeasure: Measure = (text, px) => {
  let em = 0

  for (const ch of text) em += /\s/u.test(ch) ? 0.28 : /[\p{N}]/u.test(ch) ? 0.52 : /[؀-ۿ]/u.test(ch) ? 0.5 : 0.55

  return em * px
}

export interface Fit {
  lines: string[]
  px: number
  /** True when even the smallest allowed size did not fit; the caller must shorten the copy or enlarge the box. */
  overflow: boolean
}

/** Greedy wrap by words in reading order (the same order for Arabic and English; direction only changes where lines are drawn from). */
export function wrap(measure: Measure, text: string, px: number, maxWidth: number): string[] {
  const words = text.trim().split(/\s+/u).filter(Boolean)
  const lines: string[] = []
  let line = ''

  for (const word of words) {
    const next = line === '' ? word : `${line} ${word}`

    if (line !== '' && measure(next, px) > maxWidth) {
      lines.push(line)
      line = word
    } else {
      line = next
    }
  }

  if (line !== '') lines.push(line)

  return lines
}

export function fitText(measure: Measure, text: string, maxWidth: number, maxHeight: number, startPx: number, minPx: number, lineHeight = 1.25): Fit {
  if (text.trim() === '') return { lines: [], px: startPx, overflow: false }

  for (let px = startPx; px >= minPx; px -= 2) {
    const lines = wrap(measure, text, px, maxWidth)
    const widest = Math.max(...lines.map((l) => measure(l, px)))

    if (lines.length * px * lineHeight <= maxHeight && widest <= maxWidth) return { lines, px, overflow: false }
  }

  return { lines: wrap(measure, text, minPx, maxWidth), px: minPx, overflow: true }
}

/** The shortest time a block of copy should stay on screen: 0.3s a word plus 0.8s. */
export const minReadMs = (wordCount: number): number => Math.round((wordCount * 0.3 + 0.8) * 1000)
