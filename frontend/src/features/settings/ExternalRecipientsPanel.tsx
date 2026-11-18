import { Link } from 'react-router-dom'
import { usePortalPath } from '@/app/portalPath'

/**
 * NOTIF-V2-SETTINGS-UI-001 — external recipients moved.
 *
 * They are workspace-level now, with roles, subscriptions, consent, review and a log, in the notification settings page's
 * «خارجي» section. The old form wrote the legacy table the engine no longer sends from, so it would have looked like a
 * working screen that mails nobody. What stays here is one line that says where it went. The legacy rows themselves were
 * moved, not deleted, as «pending confirmation» (builder M's importer).
 */
export function ExternalRecipientsPanel({ ar }: { ar: boolean }) {
  const to = usePortalPath()

  return (
    <p data-testid="external-recipients" className="rounded-xl border border-border bg-surface-secondary px-4 py-3 text-sm text-text-secondary">
      {ar ? 'مستلمو التقارير الخارجية انتقلوا إلى إعدادات الإشعارات في المساحة.' : 'External report recipients moved to the workspace notification settings.'}{' '}
      <Link data-testid="external-recipients-link" to={`${to('/settings/workspace')}?tab=notifications&section=external`} className="font-semibold text-brand-600 hover:underline">
        {ar ? 'افتح المكان الجديد' : 'Open the new place'}
      </Link>
    </p>
  )
}
