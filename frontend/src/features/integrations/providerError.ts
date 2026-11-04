import type { Locale } from '@/stores/ui'

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §15 — a provider's refusal, read back to the person who has to
 * act on it, with the provider's own words kept for the person who has to fix it.
 *
 * ## What customers were shown
 *
 * The card printed `connections.last_error` verbatim. In production, on a real LinkedIn connection,
 * that sentence was:
 *
 * > Projected field 'pivotValues%2CdateRange%2C…' not present in schema
 * > 'com.linkedin.ads.externalapi.reportingapi.v9.AdAnalyticsV9'
 *
 * A customer cannot act on that, and — this is the part that costs money — cannot tell that it is
 * NOT about them. It reads like a broken account, so the moves it produces are the expensive ones:
 * disconnect, re-authorise, open a ticket that says «LinkedIn is not working». It was in fact a
 * defect in the request this product sent, which no amount of re-consenting would have changed.
 *
 * ## Four readers, one message
 *
 * Every provider failure is answerable by exactly one of four parties, and the category is the whole
 * message: the CUSTOMER re-authorises or restores a permission on the platform, the OPERATOR of this
 * install adds keys, the PRODUCT team fixes a request the provider no longer accepts, and NOBODY
 * acts on a platform having a bad minute — the sync already retries.
 *
 * Saying which of the four it is turns an error into an instruction. Saying nothing turns all four
 * into «something is broken, ask the customer to reconnect».
 *
 * ## The raw text is not thrown away
 *
 * It is what a diagnosis is made from, so it stays — behind a disclosure, verbatim, unwrapped and
 * copyable. This translates the message; it does not replace the evidence.
 */
export type ProviderErrorActor = 'customer' | 'operator' | 'product' | 'nobody'

export type ProviderErrorReading = {
  category: string
  actor: ProviderErrorActor
  /** What is wrong, and what to do about it, in the reader's language. */
  message: string
  /** The provider's own words, verbatim. */
  raw: string
}

type Rule = {
  category: string
  actor: ProviderErrorActor
  match: RegExp
  ar: string
  en: string
}

/*
 * Ordered, and the order matters: a 401 that also mentions a rate limit is an authorisation problem,
 * and «invalid_grant» beats the generic «invalid» that appears in half of all provider messages.
 */
const RULES: readonly Rule[] = [
  {
    category: 'authorisation_lost',
    actor: 'customer',
    match: /invalid_grant|revoked|token (has )?expired|expired token|reauthenticat|re-?authoriz|unauthorized|unauthorised|401|OAuthException/i,
    ar: 'توقّفت المنصة عن قبول هذا التفويض. أعِد الربط من هذه البطاقة — لن تتغيّر الحسابات المختارة.',
    en: 'The platform has stopped accepting this authorisation. Reconnect from this card — your selected accounts stay as they are.',
  },
  {
    category: 'permission_withdrawn',
    actor: 'customer',
    match: /permission|forbidden|403|not have access|no access to|access denied|insufficient/i,
    ar: 'سُحبت صلاحية هذا الحساب على المنصة. يمنحها مالك الحساب من إعدادات المنصة، ثم تُستأنف المزامنة تلقائيًا.',
    en: 'This account’s permission was withdrawn on the platform. The account owner grants it again there, and the sync resumes on its own.',
  },
  {
    category: 'rate_limited',
    actor: 'nobody',
    match: /rate limit|throttl|too many requests|429|quota exceeded/i,
    ar: 'حدّت المنصة من عدد الطلبات مؤقتًا. تُعاد المحاولة تلقائيًا ولا يلزمك أي إجراء.',
    en: 'The platform is limiting how often we may ask. The sync retries by itself — nothing is needed from you.',
  },
  {
    category: 'platform_unavailable',
    actor: 'nobody',
    match: /timed? ?out|timeout|temporarily unavailable|service unavailable|internal server error|50[0234]|connection reset/i,
    ar: 'لم تُجب المنصة على الطلب. تُعاد المحاولة تلقائيًا ولا يلزمك أي إجراء.',
    en: 'The platform did not answer. The sync retries by itself — nothing is needed from you.',
  },
  {
    /*
     * The LinkedIn production failure, and every future one of its shape: the provider changed what
     * it accepts and this product is still asking the old way. Nothing the customer does helps, and
     * a message that does not say so sends them through OAuth for a defect in our request.
     */
    category: 'request_rejected',
    actor: 'product',
    match: /not present in schema|unknown field|unsupported (field|metric|parameter)|deprecat|invalid parameter|unrecognized|malformed|400 Bad Request/i,
    ar: 'طلب كامبينز هب من هذه المنصة شيئًا لم تعد تقبله. المشكلة في طلبنا لا في حسابك، وقد سُجّلت التفاصيل للفريق.',
    en: 'CampaignsHub asked this platform for something it no longer accepts. This is our request, not your account — the details are recorded for the team.',
  },
  {
    category: 'credentials_missing',
    actor: 'operator',
    match: /client_id|client_secret|no credentials|not configured|missing (api )?key|app (is )?not approved/i,
    ar: 'مفاتيح هذه المنصة غير مكتملة في هذا التثبيت. يتولّاها مشغّل المنصة، ولا يلزمك أي إجراء.',
    en: 'This platform’s keys are not complete on this install. The platform operator handles it — nothing is needed from you.',
  },
]

/**
 * META-GRAPH-REFUSAL-001 — Meta's published codes, as `PlatformHttp::reason()` stores them (`· code N · subcode M`
 * and the `(#N)` head). Read only for Meta: another platform's «code 190» means something else. Checked before
 * RULES, whose sentences would send a missing grant to reconnect. The backend twin is `MetaGraphRefusal`.
 */
const META_CODE = (codes: string) => new RegExp(`(?:(?<![\\w])code (?:${codes})(?!\\d)|\\(#(?:${codes})\\))`)

const META_RULES: readonly Rule[] = [
  {
    category: 'page_too_large',
    actor: 'nobody',
    match: /reduce the amount of data/i,
    ar: 'رفضت Meta حجم الصفحة المطلوبة. يعيد النظام الطلب بصفحة أصغر تلقائيًا، ولا يلزمك أي إجراء.',
    en: 'Meta refused the size of the requested page. The sync asks again with a smaller page by itself — nothing is needed from you.',
  },
  {
    category: 'authorisation_checkpoint',
    actor: 'customer',
    match: /(?<![\w])code (190|102)(?!\d).*(?<![\w])subcode (459|464)(?!\d)/,
    ar: 'أوقفت Meta جلسة صاحب الربط لفحص أمني. يسجّل الدخول في facebook.com لإنهاء الفحص، ثم يعيد الربط من هذه البطاقة.',
    en: 'Meta paused this person’s session for a security check. They sign in at facebook.com to clear it, then reconnect from this card.',
  },
  {
    category: 'authorisation_lost',
    actor: 'customer',
    match: META_CODE('190|102'),
    ar: 'لم تعد Meta تقبل هذا التفويض. أعِد الربط من هذه البطاقة — لن تتغيّر الحسابات المختارة.',
    en: 'Meta no longer accepts this authorisation. Reconnect from this card — your selected accounts stay as they are.',
  },
  {
    category: 'account_unreachable',
    actor: 'customer',
    match: /(?<![\w])code 100(?!\d).*(?<![\w])subcode 33(?!\d)/,
    ar: 'لا يصل هذا التفويض إلى الحساب الإعلاني: إما حُذف الحساب أو أُزيلت صلاحية المستخدم عليه. يتحقق مالك الحساب من وصول المستخدم في Business Manager.',
    en: 'This authorisation cannot reach the ad account: it was deleted, or the user’s access to it was removed. The account’s owner checks the user’s access in Business Manager.',
  },
  {
    category: 'permission_withdrawn',
    actor: 'customer',
    match: new RegExp(`${META_CODE('3|10|2\\d\\d').source}|${META_CODE('100').source}.*permission|permission.*${META_CODE('100').source}`, 'i'),
    ar: 'لم تُمنح الصلاحية المطلوبة. يمنح مالك الحساب الإعلاني صلاحية ads_read لهذا المستخدم من إعدادات Business Manager، وتُستأنف المزامنة في الجولة التالية.',
    en: 'The required permission was not granted. The ad account’s owner grants this user ads_read in Business Manager settings, and the next sweep resumes.',
  },
  {
    category: 'rate_limited',
    actor: 'nobody',
    match: new RegExp(`${META_CODE('4|17|32|341|613|800\\d\\d').source}|request limit reached`, 'i'),
    ar: 'حدّت Meta من عدد الطلبات مؤقتًا. تُستأنف المزامنة في الجولة التالية ولا يلزمك أي إجراء.',
    en: 'Meta is limiting how often we may ask. The next sweep resumes by itself — nothing is needed from you.',
  },
  {
    category: 'platform_unavailable',
    actor: 'nobody',
    match: META_CODE('1|2'),
    ar: 'لم تُجب Meta على الطلب مؤقتًا. تُعاد المحاولة في الجولة التالية ولا يلزمك أي إجراء.',
    en: 'Meta could not answer for now. The next sweep tries again — nothing is needed from you.',
  },
  {
    category: 'policy_block',
    actor: 'customer',
    match: META_CODE('368'),
    ar: 'حظرت Meta هذا الإجراء مؤقتًا بسبب مخالفة سياسة. يراجع صاحب الحساب حالة الحساب في Meta.',
    en: 'Meta has temporarily blocked this action for a policy violation. The account’s owner reviews its status at Meta.',
  },
  {
    category: 'request_rejected',
    actor: 'product',
    match: META_CODE('100'),
    ar: 'رفضت Meta معاملًا في طلب كامبينز هب. المشكلة في طلبنا لا في حسابك، وقد سُجّلت التفاصيل للفريق.',
    en: 'Meta rejected a parameter in CampaignsHub’s request. This is our request, not your account — the details are recorded for the team.',
  },
]

/** A provider's references are not its verdict: `trace A401x` holds no 401, and `request 50344` no 503. */
const withoutReferences = (text: string): string => text.replace(/\s*·\s*(trace|request|log|subcode)\s+\S+/g, '')

/**
 * Read a provider's refusal.
 *
 * Returns `null` for an empty message rather than an «unknown error» box: a card with no error is
 * not a card with a mystery.
 */
export function readProviderError(raw: string | null | undefined, locale: Locale, provider?: string): ProviderErrorReading | null {
  const text = (raw ?? '').trim()
  if (text === '') return null

  const ar = locale === 'ar'
  const metaRule = provider === 'meta' ? META_RULES.find((r) => r.match.test(text)) : undefined
  const verdictText = withoutReferences(text)
  const rule = metaRule ?? RULES.find((r) => r.match.test(verdictText))

  if (rule === undefined) {
    /*
     * Unmatched is stated as unmatched. Guessing a category from a message this code has never seen
     * would put a confident instruction under a failure nobody has diagnosed — and the reader would
     * follow it.
     */
    return {
      category: 'unclassified',
      actor: 'product',
      raw: text,
      message: ar
        ? 'رفضت المنصة الطلب. التفاصيل أدناه، وقد سُجّلت للفريق.'
        : 'The platform refused the request. The details are below, and are recorded for the team.',
    }
  }

  return { category: rule.category, actor: rule.actor, raw: text, message: ar ? rule.ar : rule.en }
}
