import { Lock } from 'lucide-react'
import { Link } from 'react-router-dom'
import { usePortalPath } from '@/app/portalPath'
import { useCopy } from './centerCopy'

/** NOTIF-V2-FOLLOWUP-001 — a feature the plan does not include: a lock and «ترقية», the data kept. Never an error banner. */
export function UpgradeLock({ name }: { name: string }) {
  const c = useCopy()
  const portalPath = usePortalPath()

  return (
    <div data-testid="upgrade-lock" className="flex flex-col items-start gap-3 rounded-xl border border-border bg-surface-secondary p-4 sm:flex-row sm:items-center">
      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-surface text-text-secondary"><Lock size={18} /></span>
      <div className="min-w-0 flex-1">
        <p className="text-sm font-bold text-text-primary">{name}</p>
        <p className="text-xs text-text-secondary">{c('locked')}</p>
      </div>
      <Link to={portalPath('/subscriptions')} className="inline-flex h-10 items-center rounded-lg bg-brand-500 px-4 text-sm font-semibold text-white hover:bg-brand-600">{c('upgrade')}</Link>
    </div>
  )
}
