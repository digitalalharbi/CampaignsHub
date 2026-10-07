import type { Locale } from '@/stores/ui'

const status: Record<string, { ar: string; en: string; tone: 'success' | 'warning' | 'danger' | 'info' | 'neutral' }> = {
  new: { ar: 'جديد', en: 'New', tone: 'info' },
  assigned: { ar: 'مُسند', en: 'Assigned', tone: 'info' },
  /* Deliberately not «contacted»: a call nobody answered is an attempt, and counting it as a
     conversation is how a team's contact rate reads 100% while half the leads never spoke to
     anybody. The tone is warning because somebody has to try again. */
  contact_attempted: { ar: 'محاولة تواصل', en: 'Contact attempted', tone: 'warning' },
  contacted: { ar: 'تم التواصل', en: 'Contacted', tone: 'neutral' },
  qualified: { ar: 'مؤهّل', en: 'Qualified', tone: 'success' },
  appointment: { ar: 'موعد محجوز', en: 'Appointment', tone: 'success' },
  won: { ar: 'رابح', en: 'Won', tone: 'success' },
  lost: { ar: 'خاسر', en: 'Lost', tone: 'danger' },
  /* Not a kind of loss. A junk submission filed as `lost` sits beside a real customer who chose a
     competitor, and quietly poisons every conversion rate computed from that column. */
  invalid: { ar: 'غير صالح', en: 'Invalid', tone: 'neutral' },
  /* The superseded sales vocabulary. No row can be written with these any more, but rows written
     before the change can still carry them, and a historical row must not render as its raw key. */
  proposal_sent: { ar: 'أُرسل العرض', en: 'Proposal sent', tone: 'neutral' },
  negotiation: { ar: 'تفاوض', en: 'Negotiation', tone: 'warning' },
}

const source: Record<string, { ar: string; en: string }> = {
  website: { ar: 'الموقع', en: 'Website' },
  referral: { ar: 'ترشيح', en: 'Referral' },
  paid: { ar: 'إعلان مدفوع', en: 'Paid' },
  whatsapp: { ar: 'واتساب', en: 'WhatsApp' },
  email: { ar: 'بريد', en: 'Email' },
  phone: { ar: 'هاتف', en: 'Phone' },
  event: { ar: 'فعالية', en: 'Event' },
  exhibition: { ar: 'معرض', en: 'Exhibition' },
  manual: { ar: 'يدوي', en: 'Manual' },
  api: { ar: 'API', en: 'API' },
  webhook: { ar: 'Webhook', en: 'Webhook' },
}

export function statusLabel(key: string, locale: Locale): string {
  return status[key]?.[locale] ?? key
}

export function statusTone(key: string): 'success' | 'warning' | 'danger' | 'info' | 'neutral' {
  return status[key]?.tone ?? 'neutral'
}

export function sourceLabel(key: string, locale: Locale): string {
  return source[key]?.[locale] ?? key
}
