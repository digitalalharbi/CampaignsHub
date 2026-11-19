import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CloudOff, RefreshCw } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'
import { fmtDateTime } from '@/lib/datetime'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { listDriveLinks } from '@/features/drive/api'
import { syncDriveLink } from './api'
import { DRIVE_STATE, UI, tr } from './copy'

/*
 * CONTENT-INTAKE-001 — Drive → intake, read-only. One folder per project; a sync imports its image and video files into the
 * pipeline (metadata only, idempotent), and the state, last sync and last error stay on screen. Without a connection it says so
 * and shows nothing.
 */
export function DriveSyncPanel() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = UI[ar ? 'ar' : 'en']
  const qc = useQueryClient()
  const canView = useAuth((s) => s.hasPermission('drive.view'))
  const canSync = useAuth((s) => s.hasPermission('drive.manage') && s.hasPermission('content.create'))
  const [message, setMessage] = useState<Record<string, string>>({})
  const q = useQuery({ queryKey: ['drive-links'], queryFn: listDriveLinks, enabled: canView })
  const sync = useMutation({
    mutationFn: (id: string) => syncDriveLink(id),
    onSuccess: (r, id) => {
      setMessage((m) => ({ ...m, [id]: t.drive.result(r.created, r.updated, r.unchanged, r.skipped) + (r.truncated ? ` · ${t.drive.truncated}` : '') }))
      void qc.invalidateQueries({ queryKey: ['drive-links'] })
      void qc.invalidateQueries({ queryKey: ['content-intake'] })
    },
    onError: (e, id) => setMessage((m) => ({ ...m, [id]: toApiError(e).message })),
  })

  if (!canView) return <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{t.noPermission}</p>
  const links = (q.data ?? []).filter((l) => l.scope === 'project')
  const awaiting = links.length > 0 && links.every((l) => (l.sync_state ?? 'awaiting_credentials') === 'awaiting_credentials')

  return (
    <div className="flex flex-col gap-3" data-testid="drive-sync">
      <p className="text-sm text-text-secondary">{t.drive.note}</p>
      {awaiting && (
        <div className="flex items-start gap-3 rounded-2xl border border-warning/30 bg-warning/5 p-4" data-testid="drive-awaiting">
          <CloudOff className="mt-0.5 h-5 w-5 shrink-0 text-warning" aria-hidden />
          <p className="text-sm text-text-secondary">{t.drive.awaiting}</p>
        </div>
      )}
      {q.isLoading ? <div className="h-24 animate-pulse rounded-2xl bg-surface-secondary" /> : links.length === 0 ? (
        <p className="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">
          {t.drive.none} <Link to="../files/drive" className="font-semibold text-brand-600 underline">{t.drive.manage}</Link>
        </p>
      ) : (
        <ul className="flex flex-col gap-2">
          {links.map((l) => (
            <li key={l.id} data-testid={`drive-link-${l.id}`} className="flex flex-col gap-2 rounded-2xl border border-border bg-surface p-3 sm:flex-row sm:items-center">
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-bold text-text-primary">{l.folder_name}</p>
                <p className="text-xs text-text-muted">
                  {tr(DRIVE_STATE[l.sync_state ?? 'awaiting_credentials'], ar)} · {t.drive.last}: <span dir="ltr">{l.last_synced_at ? fmtDateTime(l.last_synced_at) : t.drive.never}</span>
                  {l.last_sync_summary && <> · <span dir="ltr">{t.drive.result(l.last_sync_summary.created, l.last_sync_summary.updated, l.last_sync_summary.unchanged, l.last_sync_summary.skipped)}</span></>}
                </p>
                {l.last_error && <p role="alert" className="text-xs text-danger" data-testid="drive-error">{t.drive.error}: {l.last_error}</p>}
                {message[l.id] && <p className="text-xs text-text-secondary" data-testid="drive-result">{message[l.id]}</p>}
              </div>
              {canSync && (
                <Button variant="secondary" loading={sync.isPending && sync.variables === l.id} onClick={() => sync.mutate(l.id)} data-testid={`drive-sync-${l.id}`}>
                  <RefreshCw className="h-4 w-4" aria-hidden />{sync.isPending && sync.variables === l.id ? t.drive.syncing : t.drive.sync}
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
