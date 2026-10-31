import { useState } from 'react'
import { LifeBuoy } from 'lucide-react'
import { endSupportSession } from '@/features/admin/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

/**
 * SUPERADMIN-SUPPORT-ACCESS-001 — shown across every portal while a platform admin is inside a workspace for support.
 *
 * Says whose workspace it is, that it is read-only and when it ends, and offers the way back. Leaving reloads into the console so
 * nothing of the visited workspace stays in the client's caches.
 */
export function SupportBanner() {
  const support = useAuth((s) => s.user?.support)
  const ar = useUi((s) => s.locale) === 'ar'
  const [leaving, setLeaving] = useState(false)
  if (!support) return null

  const until = new Date(support.expires_at).toLocaleTimeString(ar ? 'ar-SA-u-nu-latn' : 'en-GB', { hour: '2-digit', minute: '2-digit' })

  const leave = async () => {
    setLeaving(true)
    try {
      const r = await endSupportSession()
      window.location.assign(r.return_path)
    } catch {
      window.location.assign('/admin/tenants')
    }
  }

  return (
    <div
      role="status"
      data-testid="support-banner"
      className="flex flex-wrap items-center justify-center gap-2 border-b border-info/40 bg-info/10 px-4 py-1.5 text-xs font-semibold text-text-primary"
    >
      <LifeBuoy size={14} aria-hidden className="shrink-0 text-info" />
      <span>
        {ar ? `وضع الدعم — ${support.tenant_name ?? ''}` : `Support mode — ${support.tenant_name ?? ''}`}
      </span>
      <span className="font-normal text-text-secondary">
        {ar ? `· قراءة فقط · ينتهي ${until}` : `· read-only · ends ${until}`}
      </span>
      <button
        type="button"
        data-testid="support-exit"
        disabled={leaving}
        onClick={() => void leave()}
        className="rounded-lg border border-border bg-surface px-2.5 py-0.5 font-semibold text-text-primary hover:bg-surface-secondary focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600"
      >
        {ar ? 'العودة للأدمن' : 'Back to admin'}
      </button>
    </div>
  )
}
