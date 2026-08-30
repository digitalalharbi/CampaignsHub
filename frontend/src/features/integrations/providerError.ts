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
    ar: 'طلب CampaignsHub من هذه المنصة شيئًا لم تعد تقبله. المشكلة في طلبنا لا في حسابك، وقد سُجّلت التفاصيل للفريق.',
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
 * Read a provider's refusal.
 *
 * Returns `null` for an empty message rather than an «unknown error» box: a card with no error is
 * not a card with a mystery.
 */
export function readProviderError(raw: string | null | undefined, locale: Locale): ProviderErrorReading | null {
  const text = (raw ?? '').trim()
  if (text === '') return null

  const ar = locale === 'ar'
  const rule = RULES.find((r) => r.match.test(text))

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
