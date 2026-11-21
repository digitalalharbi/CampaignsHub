import { luma, saturation, type Pixels } from './pixels'

export interface Swatch {
  hex: string
  rgb: [number, number, number]
  /** Share of sampled pixels, 0–1. */
  share: number
}

const toHex = (n: number): string => Math.round(Math.min(255, Math.max(0, n))).toString(16).padStart(2, '0')
export const hexOf = (rgb: readonly [number, number, number]): string => `#${toHex(rgb[0])}${toHex(rgb[1])}${toHex(rgb[2])}`

/**
 * k-means over a strided sample, seeded from brightness quantiles so the same image always gives the same palette (no randomness,
 * so a test and a re-run agree). Returns the swatches largest first.
 */
export function palette(src: Pixels, k = 5, iterations = 10, maxSamples = 6000): Swatch[] {
  const total = src.width * src.height
  const stride = Math.max(1, Math.floor(total / maxSamples))
  const samples: Array<[number, number, number]> = []

  for (let i = 0; i < total; i += stride) samples.push([src.data[i * 4]!, src.data[i * 4 + 1]!, src.data[i * 4 + 2]!])

  if (samples.length === 0) return []

  const sorted = [...samples].sort((a, b) => luma(...a) - luma(...b))
  const centres: Array<[number, number, number]> = Array.from({ length: k }, (_, j) => [...sorted[Math.min(sorted.length - 1, Math.floor(((j + 0.5) / k) * sorted.length))]!] as [number, number, number])
  const owner = new Int32Array(samples.length)

  for (let it = 0; it < iterations; it++) {
    for (let s = 0; s < samples.length; s++) {
      let bestJ = 0
      let bestD = Infinity

      for (let j = 0; j < k; j++) {
        const d = (samples[s]![0] - centres[j]![0]) ** 2 + (samples[s]![1] - centres[j]![1]) ** 2 + (samples[s]![2] - centres[j]![2]) ** 2

        if (d < bestD) {
          bestD = d
          bestJ = j
        }
      }

      owner[s] = bestJ
    }

    const sums = Array.from({ length: k }, () => [0, 0, 0, 0])

    for (let s = 0; s < samples.length; s++) {
      const a = sums[owner[s]!]!
      a[0]! += samples[s]![0]
      a[1]! += samples[s]![1]
      a[2]! += samples[s]![2]
      a[3]!++
    }

    for (let j = 0; j < k; j++) if (sums[j]![3]! > 0) centres[j] = [sums[j]![0]! / sums[j]![3]!, sums[j]![1]! / sums[j]![3]!, sums[j]![2]! / sums[j]![3]!]
  }

  const counts = new Array<number>(k).fill(0)
  for (let s = 0; s < samples.length; s++) counts[owner[s]!]!++

  return centres
    .map((c, j) => ({ rgb: [Math.round(c[0]), Math.round(c[1]), Math.round(c[2])] as [number, number, number], share: counts[j]! / samples.length }))
    .filter((s) => s.share > 0)
    .sort((a, b) => b.share - a.share)
    .map((s) => ({ ...s, hex: hexOf(s.rgb) }))
}

/** The most vivid swatch that is more than a speck of the picture — the candidate accent colour. */
export function accentCandidate(swatches: ReadonlyArray<Swatch>, minShare = 0.04): Swatch | null {
  const vivid = swatches.filter((s) => s.share >= minShare).sort((a, b) => saturation(...b.rgb) * (0.5 + b.share) - saturation(...a.rgb) * (0.5 + a.share))

  return vivid[0] ?? null
}
