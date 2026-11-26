import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link2, Search, X } from 'lucide-react'
import { fetchSharedCampaigns, saveSharedCampaigns, type SharedCampaignsSplit } from './api'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * SHARED-ACCOUNT-PROJECTS-001 (Owner 2026-11-26) — one ad account read by several projects, split by campaign.
 *
 * The mark: «مشترك · 2 مشاريع», with the projects named on hover and in the panel. The panel lists every campaign
 * of the account with the ONE project it belongs to; in a project the account is shared with, the campaigns are
 * picked here. A campaign another shared project picked is shown with that project's name and cannot be picked.
 */
export function SharedAccountMark({ projects, onOpen, testId }: {
  /** Every project that reads the account, this one included. */
  projects: { id: string; name: string }[]
  onOpen?: () => void
  testId?: string
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const names = projects.map((p) => p.name).join('، ')
  const label = ar ? `مشترك · ${projects.length.toLocaleString('en-US')} مشاريع` : `Shared · ${projects.length} projects`
  const body = (
    <span className="inline-flex items-center gap-1 rounded-[var(--radius-pill)] bg-warning-soft px-2.5 py-0.5 text-xs font-bold text-warning">
      <Link2 size={12} aria-hidden />
      {label}
    </span>
  )

  return onOpen === undefined ? (
    <span title={names} aria-label={`${label}: ${names}`} data-testid={testId}>{body}</span>
  ) : (
    <button type="button" onClick={onOpen} title={names} aria-label={`${label}: ${names}`} data-testid={testId} className="rounded-[var(--radius-pill)] focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
      {body}
    </button>
  )
}

export function SharedCampaignsDialog({ projectId, accountId, onClose }: { projectId: string; accountId: string; onClose: () => void }) {
  const ar = useUi((s) => s.locale) === 'ar'

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="shared-campaigns-title" data-testid="shared-campaigns-dialog">
      <div className="flex w-full max-w-lg flex-col gap-4 rounded-2xl border border-border bg-surface p-5 shadow-2xl">
        <header className="flex items-center gap-2">
          <Link2 size={18} className="shrink-0 text-warning" aria-hidden />
          <h2 id="shared-campaigns-title" className="flex-1 text-base font-bold text-text-primary">{ar ? 'حساب مشترك' : 'Shared account'}</h2>
          <Button type="button" variant="ghost" size="sm" onClick={onClose} aria-label={ar ? 'إغلاق' : 'Close'} data-testid="shared-campaigns-close"><X size={16} /></Button>
        </header>
        <SharedCampaignsPanel projectId={projectId} accountId={accountId} onSaved={onClose} />
      </div>
    </div>
  )
}

export function SharedCampaignsPanel({ projectId, accountId, onSaved }: { projectId: string; accountId: string; onSaved?: () => void }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const qc = useQueryClient()
  const split = useQuery({
    queryKey: ['shared-campaigns', projectId, accountId],
    queryFn: () => fetchSharedCampaigns(projectId, accountId),
    retry: false,
  })

  const [picked, setPicked] = useState<Set<string> | null>(null)
  const [search, setSearch] = useState('')

  useEffect(() => {
    if (split.data && picked === null) setPicked(new Set(split.data.scope?.campaign_external_ids ?? []))
  }, [split.data, picked])

  const save = useMutation({
    mutationFn: () => saveSharedCampaigns({
      projectId,
      accountId,
      campaignExternalIds: [...(picked ?? [])],
      nameContains: split.data?.scope?.name_contains ?? [],
    }),
    onSuccess: (data) => {
      qc.setQueryData(['shared-campaigns', projectId, accountId], data)
      void qc.invalidateQueries({ queryKey: ['project-bindings', projectId] })
      void qc.invalidateQueries({ queryKey: ['project', projectId, 'integrations'] })
      onSaved?.()
    },
  })

  const shown = useMemo(() => {
    const needle = search.trim().toLowerCase()
    return (split.data?.campaigns ?? []).filter((c) => needle === '' || c.name.toLowerCase().includes(needle) || c.external_id.includes(needle))
  }, [split.data, search])

  if (split.isLoading) return <Skeleton className="h-40 w-full" />
  if (split.isError || !split.data) return <ErrorState title={toApiError(split.error).message} onRetry={() => void split.refetch()} />

  const data: SharedCampaignsSplit = split.data
  const refusal = save.isError ? toApiError(save.error) : null

  return (
    <div className="flex flex-col gap-3" data-testid="shared-campaigns-panel">
      <div className="flex flex-wrap items-center gap-1.5" data-testid="shared-campaigns-projects">
        <span className="min-w-0 truncate text-sm font-bold text-text-primary"><bdi>{data.account.name ?? data.account.external_id}</bdi></span>
        {data.projects.map((p) => (
          <Badge key={p.id} tone={p.is_this ? 'success' : 'neutral'} data-testid={`shared-project-${p.id}`}>
            <bdi>{p.name}</bdi>
            <span className="tnum opacity-75">{p.campaigns.toLocaleString('en-US')}</span>
          </Badge>
        ))}
      </div>

      {data.campaigns.length > 8 && (
        <label className="flex items-center gap-2 rounded-xl border border-border bg-surface-secondary px-3 py-2">
          <Search size={15} className="shrink-0 text-text-secondary" aria-hidden />
          <input
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={ar ? 'بحث' : 'Search'}
            aria-label={ar ? 'بحث' : 'Search'}
            className="min-w-0 flex-1 bg-transparent text-sm text-text-primary outline-none placeholder:text-text-secondary"
            data-testid="shared-campaigns-search"
          />
        </label>
      )}

      <div className="flex max-h-80 flex-col gap-1.5 overflow-y-auto" data-testid="shared-campaigns-list">
        {data.campaigns.length === 0 && (
          <p className="rounded-xl border border-dashed border-border p-4 text-center text-xs text-text-secondary" data-testid="shared-campaigns-empty">
            {ar ? 'تظهر الحملات بعد أول تحديث للبيانات' : 'Campaigns appear after the first data update'}
          </p>
        )}
        {shown.map((c) => {
          const locked = c.locked_by !== null
          const checked = data.editable ? (picked?.has(c.external_id) ?? false) : c.here
          return (
            <label
              key={c.external_id}
              className={`flex items-center gap-3 rounded-xl border px-3 py-2 ${!data.editable || locked ? 'cursor-default' : 'cursor-pointer hover:bg-surface-hover'} ${checked ? 'border-brand-500 bg-surface-hover' : 'border-border'} ${locked ? 'opacity-60' : ''}`}
            >
              {data.editable && (
                <input
                  type="checkbox"
                  className="h-4 w-4 shrink-0 accent-brand-600"
                  checked={checked}
                  disabled={locked}
                  onChange={() => setPicked((prev) => {
                    const next = new Set(prev ?? [])
                    if (next.has(c.external_id)) next.delete(c.external_id)
                    else next.add(c.external_id)
                    return next
                  })}
                  data-testid={`shared-campaign-${c.external_id}`}
                />
              )}
              <span className="min-w-0 flex-1 truncate text-sm font-semibold text-text-primary" title={c.name}><bdi>{c.name}</bdi></span>
              <Badge tone={c.here ? 'success' : 'neutral'}><bdi>{locked ? c.locked_by : (c.project_name ?? '—')}</bdi></Badge>
            </label>
          )
        })}
      </div>

      {refusal !== null && (
        <p role="alert" className="text-xs font-semibold text-danger" data-testid="shared-campaigns-refused">
          {refusal.status === 422 && refusal.message.includes('another project') ? (ar ? 'حملة مختارة تتبع مشروعًا آخر' : 'A chosen campaign belongs to another project') : refusal.message}
        </p>
      )}

      {data.editable && (
        <footer className="flex items-center justify-between gap-2">
          <span className="tnum text-xs text-text-secondary" data-testid="shared-campaigns-count">
            {ar ? `المختار: ${(picked?.size ?? 0).toLocaleString('en-US')}` : `Selected: ${picked?.size ?? 0}`}
          </span>
          <Button type="button" onClick={() => save.mutate()} loading={save.isPending} data-testid="shared-campaigns-save">
            {ar ? 'حفظ الحملات' : 'Save campaigns'}
          </Button>
        </footer>
      )}
    </div>
  )
}
