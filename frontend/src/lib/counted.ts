import type { Locale } from '@/stores/ui'

/**
 * TYPOGRAPHY-PRODUCT-POLISH-001 — «1 accounts available» is a bug with a shrug in front of it.
 *
 * ## Why a module and not a ternary at each site
 *
 * English needs one decision and Arabic needs four, and the Arabic four are the reason this is
 * shared: the noun after a number changes FORM, not just its ending, and it changes on boundaries
 * nobody remembers at the call site — 1 takes the singular, 2 takes the dual, 3–10 take the plural,
 * and 11 upwards take the accusative singular. So «3 حسابات» and «11 حسابًا» are both correct and
 * «3 حسابًا» and «11 حسابات» are both wrong, and a product that writes them inline gets the common
 * case right and the rest wrong for as long as nobody counts.
 *
 * The product's own numeral rule is untouched: the DIGITS stay Latin everywhere
 * (CampaignsHub numeral preference). This is about the word beside them.
 *
 * ## The zero case
 *
 * Zero takes the same form as 11+ in Arabic («0 حسابًا»), which is grammatical and reads like a
 * receipt. Callers with a zero worth saying usually have a better sentence for it — «لم يُربط أي
 * حساب» — and this returns the grammatical form rather than pretending to decide that for them.
 */
export type ArabicForms = {
  /** 1 — حساب */
  one: string
  /** 2 — حسابان */
  two: string
  /** 3–10 — حسابات */
  few: string
  /** 0, 11+ — حسابًا */
  many: string
}

export function countedAr(n: number, forms: ArabicForms): string {
  const abs = Math.abs(Math.trunc(n))
  const hundreds = abs % 100

  if (abs === 1) return `${n} ${forms.one}`
  if (abs === 2) return `${n} ${forms.two}`
  if (hundreds >= 3 && hundreds <= 10) return `${n} ${forms.few}`

  return `${n} ${forms.many}`
}

export function countedEn(n: number, one: string, other: string): string {
  return `${n} ${Math.abs(n) === 1 ? one : other}`
}

/** «3 حسابات» · «1 account» — the phrase this product counts most often. */
export function accounts(n: number, locale: Locale): string {
  return locale === 'ar'
    ? countedAr(n, { one: 'حساب', two: 'حسابان', few: 'حسابات', many: 'حسابًا' })
    : countedEn(n, 'account', 'accounts')
}

/**
 * «3 حسابات إعلانية» · «1 ad account» — the noun AND its adjective, both agreeing with the count.
 *
 * Arabic agreement is not a suffix on the noun alone: «3 حسابات إعلاني» is as wrong as «3 حساب», and
 * it is the form that survives longest in a product, because the noun looks right on its own.
 */
export function adAccounts(n: number, locale: Locale): string {
  if (locale !== 'ar') return countedEn(n, 'ad account', 'ad accounts')

  const abs = Math.abs(Math.trunc(n))
  const hundreds = abs % 100
  const adjective = abs === 1 ? 'إعلاني' : abs === 2 ? 'إعلانيان' : hundreds >= 3 && hundreds <= 10 ? 'إعلانية' : 'إعلانيًا'

  return `${accounts(n, locale)} ${adjective}`
}

/** «3 حسابات مربوطة» · «1 account connected» — the same count, said about bindings. */
export function connectedAccounts(n: number, locale: Locale): string {
  return locale === 'ar'
    ? `${accounts(n, locale)} ${n === 1 ? 'مربوط' : n === 2 ? 'مربوطان' : 'مربوطة'}`
    : `${countedEn(n, 'account', 'accounts')} connected`
}

/** «3 حملات» · «1 campaign» */
export function campaigns(n: number, locale: Locale): string {
  return locale === 'ar'
    ? countedAr(n, { one: 'حملة', two: 'حملتان', few: 'حملات', many: 'حملة' })
    : countedEn(n, 'campaign', 'campaigns')
}

/** «3 عملاء» · «1 client» */
export function clients(n: number, locale: Locale): string {
  return locale === 'ar'
    ? countedAr(n, { one: 'عميل', two: 'عميلان', few: 'عملاء', many: 'عميلًا' })
    : countedEn(n, 'client', 'clients')
}

/**
 * «3 أيام» · «1 day»
 *
 * The window labels are the reason this one exists: «آخر 7 أيام» and «آخر 30 يومًا» are both right
 * and were both written by hand at every call site, so half the product said «آخر 30 أيام».
 */
export function days(n: number, locale: Locale): string {
  return locale === 'ar'
    ? countedAr(n, { one: 'يوم', two: 'يومان', few: 'أيام', many: 'يومًا' })
    : countedEn(n, 'day', 'days')
}
