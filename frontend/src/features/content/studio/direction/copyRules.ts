/**
 * CONTENT-STUDIO-001 — the copy structure and its hard limits: hook ≤ 6 words, message ≤ 10, call to action ≤ 3,
 * no hashtag, no emoji, no forbidden word. Words are counted on Arabic and Latin text alike.
 */

export interface StudioCopy {
  hook: string
  message: string
  cta: string
  /** Optional price, discount code, instalments, product name. */
  support?: string
}

export const LIMITS = { hook: 6, message: 10, cta: 3 } as const

export function countWords(text: string): number {
  const words = text.trim().split(/\s+/u).filter((w) => /[\p{L}\p{N}]/u.test(w))

  return words.length
}

export const hasHashtag = (text: string): boolean => /(^|\s)#[\p{L}\p{N}_]/u.test(text)

export const hasEmoji = (text: string): boolean => /\p{Extended_Pictographic}/u.test(text)

/** Whole-word, case-insensitive; Arabic has no case, and matching on word boundaries stops «سعر» from flagging «أسعار». */
export function forbiddenWordsIn(text: string, forbidden: ReadonlyArray<string>): string[] {
  const tokens = text.split(/[\s،,.؛;:!؟?()«»"'-]+/u).map((t) => t.toLowerCase()).filter(Boolean)
  const set = new Set(forbidden.map((w) => w.trim().toLowerCase()).filter(Boolean))

  return [...new Set(tokens.filter((t) => set.has(t)))]
}
