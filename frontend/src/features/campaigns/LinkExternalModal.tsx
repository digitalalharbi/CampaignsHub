import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { AxiosError } from 'axios'
import { Link2 } from 'lucide-react'
import { linkExternal, listExternalCampaigns, listLinkSuggestions } from './api'
import { campaignStatusLabel, campaignStatusTone, providerLabel } from './labels'
import { isDemoProvider, type ExternalCampaign } from './types'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Checkbox } from '@/components/ui/Checkbox'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { EmptyState, Skeleton } from '@/components/ui/States'
import type { ApiEnvelope } from '@/lib/api/types'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'

interface Props {
  open: boolean
  onClose: () => void
  projectId: string
  campaignId: string
}

/** Reads the 409 confirmation envelope: the backend is the source of the duplicate-link decision. */
function read409(err: unknown): { current: string | null } | null {
  const axiosError = err as AxiosError<ApiEnvelope<unknown>>
  if (axiosError.response?.status !== 409) return null
  const meta = axiosError.response.data?.meta as Record<string, unknown> | undefined
  return { current: (meta?.current_unified_campaign_id as string | undefined) ?? null }
}

export function LinkExternalModal({ open, onClose, projectId, campaignId }: Props) {
  const t = useT()
  const locale = useUi((s) => s.locale)
  const queryClient = useQueryClient()

  const [search, setSearch] = useState('')
  const [unlinkedOnly, setUnlinkedOnly] = useState(true)
  const [pendingMove, setPendingMove] = useState<{ ext: ExternalCampaign; current: string | null } | null>(null)

  const externalQuery = useQuery({
    queryKey: ['project', projectId, 'external', { unlinkedOnly }],
    queryFn: () => listExternalCampaigns(projectId, unlinkedOnly ? { linked: false } : {}),
    enabled: open && Boolean(projectId),
  })

  const suggestionsQuery = useQuery({
    queryKey: ['project', projectId, 'campaign', campaignId, 'suggestions'],
    queryFn: () => listLinkSuggestions(projectId, campaignId),
    enabled: open && Boolean(projectId),
  })

  const suggestedIds = useMemo(
    () => new Set((suggestionsQuery.data ?? []).map((s) => s.id)),
    [suggestionsQuery.data],
  )

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['project', projectId, 'external'] })
    // The command-center detail page (Platforms tab, KPIs) keys on the plural convention
    // ['projects', projectId, 'campaigns', campaignId, …]; invalidate it so a link/move/unlink
    // refetches the linked list. Also refetch the list (singular key) it uses.
    queryClient.invalidateQueries({ queryKey: ['projects', projectId, 'campaigns', campaignId] })
    queryClient.invalidateQueries({ queryKey: ['project', projectId, 'campaigns'] })
  }

  const linkMutation = useMutation({
    mutationFn: ({ ext, confirm }: { ext: ExternalCampaign; confirm: boolean }) =>
      linkExternal(projectId, campaignId, ext.id, confirm),
    onSuccess: () => {
      setPendingMove(null)
      invalidate()
    },
    onError: (err, variables) => {
      const conflict = read409(err)
      // First attempt (confirm=false) hitting a link elsewhere → ask the user to confirm the move.
      if (conflict && !variables.confirm) setPendingMove({ ext: variables.ext, current: conflict.current })
    },
  })

  const rows = useMemo(() => {
    let list = externalQuery.data ?? []
    // Hide campaigns already linked to THIS unified campaign (they live in the Linked tab).
    list = list.filter((e) => e.unified_campaign_id !== campaignId)
    if (search.trim()) {
      const q = search.trim().toLowerCase()
      list = list.filter((e) => e.name.toLowerCase().includes(q))
    }
    // Suggested first.
    return [...list].sort((a, b) => Number(suggestedIds.has(b.id)) - Number(suggestedIds.has(a.id)))
  }, [externalQuery.data, campaignId, search, suggestedIds])

  const genericError =
    linkMutation.isError && read409(linkMutation.error) === null ? toApiError(linkMutation.error) : null

  return (
    <Modal open={open} onClose={onClose} title={t('link_external')} size="lg">
      <div className="space-y-3">
        <p className="text-xs text-text-muted">{t('demo_source_note')}</p>

        {genericError && <Alert severity="danger" title={genericError.message} />}

        <div className="flex flex-wrap items-center gap-3">
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('search_campaigns')}
            className="max-w-xs"
            data-autofocus
          />
          <Checkbox
            checked={unlinkedOnly}
            onChange={(e) => setUnlinkedOnly(e.target.checked)}
            label={t('unlinked_only')}
          />
        </div>

        {/* Move-confirmation — shown only after the backend replies 409 requires_confirmation. */}
        {pendingMove && (
          <Alert severity="warning" title={t('move_confirm_title')}>
            <p className="text-xs">{t('move_confirm_body')}</p>
            <p className="mt-1 text-xs text-text-muted">
              {t('current_link')}: <span className="tnum">{pendingMove.current ?? '—'}</span>
            </p>
            <div className="mt-2 flex gap-2">
              <Button variant="secondary" onClick={() => setPendingMove(null)}>
                {t('cancel')}
              </Button>
              <Button
                variant="danger"
                loading={linkMutation.isPending}
                onClick={() => linkMutation.mutate({ ext: pendingMove.ext, confirm: true })}
              >
                {t('confirm_move')}
              </Button>
            </div>
          </Alert>
        )}

        {externalQuery.isLoading ? (
          <div className="space-y-2">
            <Skeleton className="h-12 w-full" />
            <Skeleton className="h-12 w-full" />
          </div>
        ) : rows.length === 0 ? (
          /*
           * The empty state is nameable, because «the list resolved and is empty» is a real answer.
           *
           * Without a handle on it the only proof a caller had that the query had finished was a
           * ROW — so anything waiting for the panel to become interactive was really waiting for the
           * pool to be non-empty, and started failing the moment imports began arriving linked. The
           * two are different facts and now have different signals.
           */
          <div data-testid="link-external-empty">
            <EmptyState
              title={unlinkedOnly ? t('no_unlinked_external') : t('no_linked_external')}
              description={t('no_external_hint')}
            />
          </div>
        ) : (
          <div className="max-h-[46vh] space-y-2 overflow-y-auto pe-1">
            {rows.map((ext) => {
              const linkedElsewhere = ext.unified_campaign_id !== null

              /*
               * A row the tests can name (PROJINT-001 follow-up).
               *
               * The linking spec used to find this row with "the smallest div containing both the
               * name and a Link button". That heuristic held while exactly one external carried a
               * given name; the moment a second appeared — which happens as soon as two projects
               * each have a Sandbox binding — `.last()` picked a container with no button in it, and
               * the failure read as a missing row rather than as an ambiguous selector.
               */
              return (
                <div
                  key={ext.id}
                  data-testid="link-external-row"
                  data-external-name={ext.name ?? ''}
                  className="flex items-center justify-between rounded-[9px] border border-border p-2.5"
                >
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="truncate text-sm font-semibold">{ext.name}</span>
                      <Badge tone={isDemoProvider(ext.provider) ? 'warning' : 'neutral'}>
                        {providerLabel(ext.provider, locale)}
                        {isDemoProvider(ext.provider) ? ` · ${t('demo_label')}` : ''}
                      </Badge>
                      <Badge tone={campaignStatusTone(ext.status)}>{campaignStatusLabel(ext.status, locale)}</Badge>
                      {suggestedIds.has(ext.id) && <Badge tone="info">{t('suggestions_label')}</Badge>}
                      {linkedElsewhere && <Badge tone="danger">{t('linked_platforms')}</Badge>}
                    </div>
                    <span className="text-xs text-text-muted">
                      {t('ad_account_label')}: <span className="tnum">{ext.external_id}</span>
                    </span>
                  </div>
                  <Button
                    variant="secondary"
                    loading={linkMutation.isPending && linkMutation.variables?.ext.id === ext.id}
                    onClick={() => linkMutation.mutate({ ext, confirm: false })}
                  >
                    <Link2 size={14} /> {t('link_action')}
                  </Button>
                </div>
              )
            })}
          </div>
        )}
      </div>
    </Modal>
  )
}
