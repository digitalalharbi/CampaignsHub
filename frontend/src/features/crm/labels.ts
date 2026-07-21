import type { Locale } from '@/stores/ui'

const status: Record<string, { ar: string; en: string; tone: 'success' | 'warning' | 'danger' | 'info' | 'neutral' }> = {
  new: { ar: 'جديد', en: 'New', tone: 'info' },
  contacted: { ar: 'تم التواصل', en: 'Contacted', tone: 'neutral' },
  qualified: { ar: 'مؤهّل', en: 'Qualified', tone: 'success' },
  proposal_sent: { ar: 'أُرسل العرض', en: 'Proposal sent', tone: 'neutral' },
  negotiation: { ar: 'تفاوض', en: 'Negotiation', tone: 'warning' },
  won: { ar: 'رابح', en: 'Won', tone: 'success' },
  lost: { ar: 'خاسر', en: 'Lost', tone: 'danger' },
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
