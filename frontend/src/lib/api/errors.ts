import type { Locale } from '@/stores/ui'

/**
 * What went wrong, said precisely (LOGIN-FINAL).
 *
 * This exists because the client had exactly two answers: the server's envelope message, or «A
 * network error occurred». Everything that was not a well-formed envelope fell into the second —
 * which meant a 502 from a gateway, an HTML error page, a body the server never finished sending,
 * and a bug in our own code all told the customer their internet was down. Reproduced against the
 * running stack: with the API unreachable the dev proxy answers **502 with an empty `text/plain`
 * body**, so axios has a response, `response.data` is `''`, and the envelope lookup yields nothing.
 *
 * The rule now: a status we received is a fact about the SERVER, and it is described as one. Only
 * the case where no response arrived at all is called a network problem.
 */

/** The shape axios gives us, narrowed to what can actually be relied on. */
interface HttpFailure {
  status?: number
  /**
   * True only when the request was SENT and nothing came back.
   *
   * Not merely "there is no response object" — a `TypeError` thrown in our own code has no
   * response either, and reporting that as a connection problem sends somebody to restart a router
   * over our bug.
   */
  sentButUnanswered: boolean
  envelopeMessage?: string
}

const COPY = {
  ar: {
    // Reserved for a request that got no response at all.
    offline: 'تعذّر الاتصال بالخادم. تحقّق من اتصالك بالإنترنت وحاول مرة أخرى.',
    // A response arrived, but not one we can read — a بوابة وسيطة، صفحة خطأ، رد مقطوع.
    unreadable: 'الخادم ردّ بشكل غير متوقع. حاول مرة أخرى، وإن تكرر الأمر تواصل مع الدعم.',
    unauthenticated: 'انتهت جلستك. الرجاء تسجيل الدخول مرة أخرى.',
    forbidden: 'ليس لديك صلاحية لتنفيذ هذا الإجراء.',
    notFound: 'العنصر المطلوب غير موجود.',
    expired: 'انتهت صلاحية الصفحة. الرجاء تحديثها والمحاولة مرة أخرى.',
    invalid: 'البيانات المُدخلة غير صحيحة.',
    tooMany: 'عدد كبير من المحاولات. الرجاء الانتظار قليلًا ثم المحاولة مرة أخرى.',
    server: 'حدث خطأ في الخادم. لم يكن السبب من جهتك — حاول مرة أخرى بعد قليل.',
    unavailable: 'الخدمة غير متاحة مؤقتًا. حاول مرة أخرى بعد قليل.',
    timeout: 'استغرق الطلب وقتًا أطول من المتوقع. حاول مرة أخرى.',
    unexpected: 'حدث خطأ غير متوقع.',
  },
  en: {
    offline: 'The server could not be reached. Check your connection and try again.',
    unreadable: 'The server answered unexpectedly. Try again, and contact support if it keeps happening.',
    unauthenticated: 'Your session has ended. Please sign in again.',
    forbidden: 'You do not have permission to do this.',
    notFound: 'That could not be found.',
    expired: 'This page has expired. Refresh it and try again.',
    invalid: 'Some of the details are not valid.',
    tooMany: 'Too many attempts. Please wait a moment and try again.',
    server: 'Something went wrong on our side. It was not your fault — please try again shortly.',
    unavailable: 'The service is temporarily unavailable. Please try again shortly.',
    timeout: 'The request took longer than expected. Please try again.',
    unexpected: 'Something unexpected went wrong.',
  },
} as const

/**
 * The message for a failure, in the reader's language.
 *
 * The server's own message wins whenever there is one: it is already translated, and it knows things
 * this function cannot — which field, which plan, which limit. This only supplies what to say when
 * the response carried nothing usable.
 */
export function describeFailure(failure: HttpFailure, locale: Locale): string {
  const c = COPY[locale]

  if (failure.envelopeMessage) return failure.envelopeMessage

  /*
   * The ONLY case that is genuinely a network problem.
   *
   * Everything below this line reached a server and came back with a status, so blaming the
   * customer's connection would send them to restart a router over our 500.
   */
  if (failure.sentButUnanswered) return c.offline

  switch (failure.status) {
    case 401: return c.unauthenticated
    case 403: return c.forbidden
    case 404: return c.notFound
    case 419: return c.expired
    case 422: return c.invalid
    case 429: return c.tooMany
    case 408:
    case 504: return c.timeout
    // 502/503 are a gateway in front of the API, not the API. Said as "temporarily unavailable"
    // rather than as an error the customer could have caused or can act on.
    case 502:
    case 503: return c.unavailable
    default: break
  }

  if (failure.status !== undefined && failure.status >= 500) return c.server
  if (failure.status !== undefined) return c.unreadable

  // Nothing was sent, nothing answered, and there is no status: this did not come from the network
  // at all. Almost always a fault of ours, and said as one rather than blamed on the connection.
  return c.unexpected
}

/** Timeouts and aborts look like "no response" but are worth naming separately. */
export function isTimeout(code: string | undefined): boolean {
  return code === 'ECONNABORTED' || code === 'ETIMEDOUT'
}
