import { useQuery } from '@tanstack/react-query'
import { Activity } from 'lucide-react'
import { listClientActivity, type ActivityItem } from './api'
import { useT } from '@/lib/i18n'

/** Readable label for an event action key (client.classification_updated -> "classification updated"). */
function actionLabel(action: string): string {
  return action.replace(/^client\.|^request\./, '').replaceAll('_', ' ')
}

function diff(item: ActivityItem): string | null {
  if (!item.new) return null
  const keys = Object.keys(item.new).slice(0, 3)
  return keys.map((k) => `${k}: ${item.old?.[k] ?? '—'} → ${item.new?.[k] ?? '—'}`).join(' · ')
}

export function TabActivity({ clientId }: { clientId: string }) {
  const t = useT()
  const q = useQuery({ queryKey: ['app', 'client', clientId, 'activity'], queryFn: () => listClientActivity(clientId) })
  const items = q.data?.timeline ?? []

  if (q.isLoading) return <div className="h-24 animate-pulse rounded-xl bg-surface-secondary" />
  if (items.length === 0) return <div className="flex flex-col items-center gap-2 rounded-xl border border-border bg-surface p-10 text-center text-text-muted"><Activity size={22} /><span className="text-sm">{t('ac_empty')}</span></div>

  return (
    <ol className="relative ms-3 space-y-4 border-s border-border ps-5">
      {items.map((item) => {
        const d = diff(item)
        return (
          <li key={item.id} className="relative">
            <span className="absolute -start-[27px] top-1 h-2.5 w-2.5 rounded-full bg-brand-500 ring-4 ring-surface" />
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <span className="text-sm font-semibold text-text-primary">{actionLabel(item.action)}</span>
              <span className="text-[11px] text-text-muted">{item.time ? new Date(item.time).toLocaleString('en-CA') : ''}</span>
            </div>
            <div className="mt-0.5 flex flex-wrap items-center gap-2 text-[11px] text-text-secondary">
              {item.actor && <span>{t('ac_by')} {item.actor}</span>}
              {item.related_entity.id && <span className="rounded bg-surface-secondary px-1.5 py-0.5">{item.related_entity.type}: {item.related_entity.id}</span>}
              <span className="rounded bg-surface-secondary px-1.5 py-0.5">{item.source}</span>
            </div>
            {d && <div className="mt-1 truncate text-[11px] text-text-muted" dir="ltr">{d}</div>}
          </li>
        )
      })}
    </ol>
  )
}
