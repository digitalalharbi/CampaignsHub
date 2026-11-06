/**
 * NOTIFICATION-ENROLMENT-001 — the one short word each «send me this now» press can answer with.
 *
 * Short on purpose (owner: «اعتمد دائمًا الاختصارات»). Every state the server can return has a word, so a press never prints
 * a raw key, and `not_enrolled` is stated plainly because it is the very condition these chips exist to expose.
 */
export const SEND_NOW_AR: Record<string, string> = {
  sent: 'تم الإرسال',
  already_sent: 'أُرسلت سابقًا',
  awaiting_credentials: 'البريد غير مربوط',
  skipped: 'لا شيء ضمن نطاقك',
  'skipped:no_activity': 'لا نشاط في الفترة',
  failed: 'تعذّر الإرسال',
  unsubscribed: 'ألغيتَ الاشتراك',
  suppressed: 'العنوان موقوف',
  limit_reached: 'بلغ الحدّ اليومي',
  address_not_proven: 'عنوانك غير مؤكد',
  not_enrolled: 'احفظ تفضيلاتك أولًا',
  switched_off: 'البريد مغلق لديك',
  held_for_digest: 'محجوز للملخص',
  held_by_quiet_hours: 'ساعات الهدوء',
  below_importance: 'دون الأهمية المختارة',
  repeat_limit: 'وصل حدّ التكرار',
  out_of_scope: 'خارج نطاقك',
  throttled: 'حاول بعد دقيقة',
}

export const SEND_NOW_EN: Record<string, string> = {
  sent: 'Sent',
  already_sent: 'Already sent',
  awaiting_credentials: 'Email is not connected',
  skipped: 'Nothing in your scope',
  'skipped:no_activity': 'No activity in the period',
  failed: 'Could not be sent',
  unsubscribed: 'You unsubscribed',
  suppressed: 'Address is suppressed',
  limit_reached: 'Daily limit reached',
  address_not_proven: 'Your address is not verified',
  not_enrolled: 'Save your preferences first',
  switched_off: 'Email is off for you',
  held_for_digest: 'Held for the digest',
  held_by_quiet_hours: 'Quiet hours',
  below_importance: 'Below your importance level',
  repeat_limit: 'Repeat limit reached',
  out_of_scope: 'Outside your scope',
  throttled: 'Try again in a minute',
}

/** The word for a server answer, with its reason when the reason has a word of its own. */
export function sendNowWord(ar: boolean, state: string, reason?: string | null): string {
  const words = ar ? SEND_NOW_AR : SEND_NOW_EN

  return (reason ? words[`${state}:${reason}`] : undefined) ?? words[state] ?? words.failed
}

/** What a failed request means: a refusal code from the body, or the limiter's 429. */
export function sendNowFailure(e: unknown): string {
  const r = (e as { response?: { status?: number; data?: { errors?: { code?: string } } } })?.response

  return r?.status === 429 ? 'throttled' : (r?.data?.errors?.code ?? 'failed')
}
