import type { LiveDelivery } from '../api'

/**
 * PERF-RECOVERY-001 — the page says when it is showing the link's last complete build rather than one made for this visit.
 *
 * The footer's «آخر تحديث للتقرير» is that build's own time, so the figures are already dated truthfully; this one line says the
 * rest: they are the last confirmed copy, and (when it is so) a newer one is being prepared. Nothing renders for a fresh build or
 * for a server that does not keep builds — a sentence about caching on every report would be noise.
 */
export function LiveDeliveryNote({ delivery, ar }: { delivery?: LiveDelivery | null; ar: boolean }) {
  if (!delivery || delivery.source !== 'last_confirmed') return null

  const text = delivery.refreshing
    ? ar
      ? 'تعرض الصفحة آخر نسخة مكتملة من التقرير، ويجري إعداد نسخة أحدث في الخلفية.'
      : 'This page shows the report’s last completed build; a newer one is being prepared in the background.'
    : ar
      ? 'تعرض الصفحة آخر نسخة مكتملة من التقرير.'
      : 'This page shows the report’s last completed build.'

  return (
    <p data-testid="live-delivery-note" role="status" className="text-xs text-text-muted">
      {text}
    </p>
  )
}
