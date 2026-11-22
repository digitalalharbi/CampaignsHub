/**
 * CREATIVE-ENGINE-001 — the engine's only source of chance. Mulberry32 over a 32-bit seed, and a string hash (cyrb53, folded to 32
 * bits) to make that seed from a content id and a batch index. The same seed gives the same batch, so every saved design can be
 * reproduced exactly; «دفعة جديدة» is a new seed, never a repeat.
 */
export function mulberry32(seed: number): () => number {
  let a = seed >>> 0

  return () => {
    a = (a + 0x6d2b79f5) >>> 0
    let t = a
    t = Math.imul(t ^ (t >>> 15), t | 1)
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61)

    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

export function hashSeed(...parts: Array<string | number>): number {
  const str = parts.join('|')
  let h1 = 0xdeadbeef
  let h2 = 0x41c6ce57
  for (let i = 0; i < str.length; i++) {
    const ch = str.charCodeAt(i)
    h1 = Math.imul(h1 ^ ch, 2654435761)
    h2 = Math.imul(h2 ^ ch, 1597334677)
  }
  h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909)
  h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909)

  return ((h2 & 0x1fffff) * 4294967296 + (h1 >>> 0)) >>> 0
}

/** Picks one item; the generator decides, so the same seed picks the same thing. */
export const pick = <T>(rand: () => number, items: ReadonlyArray<T>): T => items[Math.floor(rand() * items.length) % items.length]!
