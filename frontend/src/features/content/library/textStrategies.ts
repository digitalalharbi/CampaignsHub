import { countWords, hasEmoji, hasHashtag, LIMITS } from '../studio/direction/copyRules'

/**
 * CONTENT-LIBRARY-SNAP-001 — the text step's suggestions and score, the way Meta's text step offers them: a few strategies, each with
 * headlines and primary texts to apply, and a ring that says how ready the text is.
 *
 * No model is called (tenant-key AI only, and none is configured here): every line is built from what the reader typed — the product,
 * its price, its description — so nothing claims a fact the reader did not give. No hashtags, no emoji, within the on-image word limits.
 */
export interface TextFacts { product: string; price: string; description: string }

export interface Strategy {
  key: string
  title: string
  why: string
  headlines: string[]
  primaries: string[]
}

const clip = (text: string, max: number) => text.trim().split(/\s+/u).filter(Boolean).slice(0, max).join(' ')
const uniq = (list: string[]) => [...new Set(list.map((s) => s.trim()).filter((s) => s !== ''))]

export const CTA_OPTIONS = {
  ar: ['اطلب الآن', 'تسوّق الآن', 'احجز الآن', 'اعرف المزيد', 'سجّل الآن', 'تواصل معنا', 'حمّل التطبيق', 'شاهد المزيد'],
  en: ['Order now', 'Shop now', 'Book now', 'Learn more', 'Sign up', 'Contact us', 'Get the app', 'See more'],
} as const

export function strategies(f: TextFacts, locale: 'ar' | 'en'): Strategy[] {
  const ar = locale === 'ar'
  const name = f.product.trim()
  const price = f.price.trim()
  const desc = f.description.trim()
  const out: Strategy[] = []

  if (price !== '') {
    out.push({
      key: 'price',
      title: ar ? `${name || 'المنتج'} بـ${price}` : `${name || 'The product'} at ${price}`,
      why: ar ? 'يذكر السعر صراحة كعرض واضح.' : 'States the price as a clear offer.',
      headlines: uniq([
        clip(ar ? `${name} بـ${price}` : `${name} at ${price}`, LIMITS.hook),
        clip(ar ? `بـ${price} فقط` : `Only ${price}`, LIMITS.hook),
        clip(ar ? `${name} بسعر ${price}` : `${name} for ${price}`, LIMITS.hook),
      ]),
      primaries: uniq([
        clip(ar ? `${name} بـ${price}. اطلب الآن` : `${name} at ${price}. Order now`, LIMITS.message),
        clip(ar ? `بـ${price} خذ ${name} اليوم` : `${price} gets you ${name} today`, LIMITS.message),
      ]),
    })
  }

  out.push({
    key: 'quality',
    title: ar ? 'الجودة والتميّز' : 'Quality first',
    why: ar ? 'يبرز المنتج نفسه وما يميّزه.' : 'Puts the product and what sets it apart first.',
    headlines: uniq([
      clip(name || (ar ? 'جودة تستحق' : 'Quality that shows'), LIMITS.hook),
      clip(ar ? `${name} بلمسة فاخرة` : `${name}, refined`, LIMITS.hook),
      clip(ar ? 'تفاصيل تفرق' : 'Details that matter', LIMITS.hook),
    ]),
    primaries: uniq([
      clip(desc || (ar ? `${name} بجودة تستحق التجربة` : `${name}, quality worth trying`), LIMITS.message),
      clip(ar ? `اختيارك الأفضل ${name}` : `${name}, the better choice`, LIMITS.message),
    ]),
  })

  out.push({
    key: 'now',
    title: ar ? 'اطلب اليوم' : 'Order today',
    why: ar ? 'يدعو للخطوة التالية مباشرة.' : 'Asks for the next step directly.',
    headlines: uniq([
      clip(ar ? `${name} متوفر الآن` : `${name}, available now`, LIMITS.hook),
      clip(ar ? 'لا يفوتك' : 'Do not miss it', LIMITS.hook),
    ]),
    primaries: uniq([
      clip(ar ? `اطلب ${name} اليوم ويوصلك بسرعة` : `Order ${name} today, delivered fast`, LIMITS.message),
      clip(ar ? `${name} بانتظارك. اطلب الآن` : `${name} is waiting. Order now`, LIMITS.message),
    ]),
  })

  return out
}

export interface TextScore { score: number; tips: string[] }

/** How ready the text is: each rule is a fact about what was typed, and the tips name what is missing. */
export function scoreText(t: { primaries: string[]; headlines: string[]; description: string; cta: string }, locale: 'ar' | 'en'): TextScore {
  const ar = locale === 'ar'
  const prim = t.primaries.map((s) => s.trim()).filter(Boolean)
  const head = t.headlines.map((s) => s.trim()).filter(Boolean)
  const all = [...prim, ...head, t.description]
  const rules: Array<[boolean, number, string]> = [
    [t.cta.trim() !== '', 20, ar ? 'اختر دعوة' : 'Choose a call to action'],
    [head.length > 0 && head.every((h) => countWords(h) <= LIMITS.hook), 15, ar ? `العنوان ${LIMITS.hook} كلمات أو أقل` : `Headline in ${LIMITS.hook} words or fewer`],
    [prim.length > 0 && prim.every((p) => countWords(p) <= LIMITS.message), 15, ar ? `النص الأساسي ${LIMITS.message} كلمات أو أقل` : `Primary text in ${LIMITS.message} words or fewer`],
    [head.length >= 2, 10, ar ? 'أضف خيار عنوان ثانٍ' : 'Add a second headline'],
    [prim.length >= 2, 10, ar ? 'أضف خيار نص ثانٍ' : 'Add a second primary text'],
    [all.some((s) => /\d/.test(s)), 10, ar ? 'اذكر رقمًا أو سعرًا' : 'Mention a number or a price'],
    [!all.some((s) => hasHashtag(s) || hasEmoji(s)), 10, ar ? 'احذف الهاشتاقات والإيموجي' : 'Remove hashtags and emoji'],
    [t.description.trim() !== '', 10, ar ? 'أضف وصفًا' : 'Add a description'],
  ]

  return { score: rules.reduce((n, [ok, w]) => n + (ok ? w : 0), 0), tips: rules.filter(([ok]) => !ok).map(([, , tip]) => tip) }
}
