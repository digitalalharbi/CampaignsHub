import { countWords, hasEmoji, hasHashtag, LIMITS, type StudioCopy } from '../direction/copyRules'

/**
 * CONTENT-STUDIO-001 batch 7 — copy that needs no AI: three alternatives from the facts we already have (product name, price,
 * discount, code), each inside the hard limits (hook ≤ 6 words, message ≤ 10, CTA ≤ 3; no hashtag, no emoji). A tenant's AI key can
 * write better lines later; this is the floor, and it is never empty and never invents a number the product data does not carry.
 */
export interface ProductFacts {
  name?: string
  /** Already formatted for reading («299 ر.س»); never computed here. */
  price?: string
  /** A discount percentage the product data states, as a whole number. */
  discountPercent?: number
  code?: string
}

const clip = (text: string, max: number): string => text.trim().split(/\s+/u).slice(0, max).join(' ')

export function suggestCopy(facts: ProductFacts, locale: 'ar' | 'en'): StudioCopy[] {
  const ar = locale === 'ar'
  const name = facts.name?.trim() ?? ''
  const cta = ar ? ['اطلب الآن', 'تسوّق الآن', 'احصل عليه'] : ['Order now', 'Shop now', 'Get yours']
  const discount = facts.discountPercent && facts.discountPercent > 0 ? facts.discountPercent : null

  const hooks = [
    discount !== null ? (ar ? `خصم ${discount}٪ اليوم` : `${discount}% off today`) : name !== '' ? clip(name, LIMITS.hook) : ar ? 'اكتشف الجديد' : 'Discover the new',
    name !== '' ? clip(ar ? `جرّب ${name}` : `Try ${name}`, LIMITS.hook) : ar ? 'جرّبه بنفسك' : 'See it for yourself',
    ar ? 'لا يفوتك العرض' : 'Do not miss it',
  ]
  const messages = [
    facts.price ? clip(ar ? `بسعر ${facts.price} فقط` : `Only ${facts.price}`, LIMITS.message) : ar ? 'جودة تستحق التجربة' : 'Quality worth trying',
    facts.code ? clip(ar ? `استخدم الكود ${facts.code}` : `Use code ${facts.code}`, LIMITS.message) : ar ? 'اطلبه الآن واستلمه بسرعة' : 'Order now, delivered fast',
    name !== '' ? clip(name, LIMITS.message) : ar ? 'اختيار يناسبك' : 'A choice made for you',
  ]

  return [0, 1, 2].map((i) => ({
    hook: hooks[i]!,
    message: messages[i]!,
    cta: cta[i]!,
    ...(facts.price ? { support: facts.price } : {}),
  }))
}

/** True when a line can be used as-is: within its limit, no hashtag, no emoji, not empty. */
export const copyIsClean = (c: StudioCopy): boolean =>
  c.hook.trim() !== '' && c.cta.trim() !== '' && countWords(c.hook) <= LIMITS.hook && countWords(c.message) <= LIMITS.message && countWords(c.cta) <= LIMITS.cta &&
  ![c.hook, c.message, c.cta, c.support ?? ''].some((t) => hasHashtag(t) || hasEmoji(t))
