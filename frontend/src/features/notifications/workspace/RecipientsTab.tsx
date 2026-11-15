import { useEffect, useMemo, useState } from 'react'
import { Copy, Pause, Pencil, Play, Plus, ShieldCheck, Trash2 } from 'lucide-react'
import { Pager } from '@/components/fit/Pager'
import { ResponsiveList } from '@/components/fit/ResponsiveList'
import { useFitRows } from '@/components/fit/useFitRows'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import {
  useApproveInternally, useConfirmationPreview, useRecipientActions, useRecipients, useSendConfirmations,
  type RecipientRow, type SubscriptionRow, type WorkspaceSettingsData,
} from './api'
import { RecipientWizard } from './RecipientWizard'
import type { WordKey } from './strings'
import { useTypeWords } from './typeWords'
import { LockedNote, StatusPill, useGate } from './ui'

const STATUS_WORD: Record<string, WordKey> = { pending: 'sPending', active: 'sActive', paused: 'sPaused', cancelled: 'sCancelled' }
const STATUS_TONE = { pending: 'warning', active: 'success', paused: 'neutral', cancelled: 'neutral' } as const

interface Line { recipient: RecipientRow; sub: SubscriptionRow | null; key: string }

export function RecipientsTab({ data, userEmail, isOwner }: { data: WorkspaceSettingsData; userEmail?: string; isOwner: boolean }) {
  const type = data.workspace.type
  const { t, ar, lang } = useTypeWords(type)
  const gate = useGate('notifications.external')
  const list = useRecipients()
  const actions = useRecipientActions()
  const approve = useApproveInternally()
  const [wizard, setWizard] = useState<{ open: boolean; row: RecipientRow | null; subscriptionId: string | null }>({ open: false, row: null, subscriptionId: null })
  const [removing, setRemoving] = useState<Line | null>(null)
  const [askConfirm, setAskConfirm] = useState(false)
  const [page, setPage] = useState(1)
  const [error, setError] = useState('')
  const { ref, rows: perPage } = useFitRows({ min: 3, max: 30, fallback: 8 })

  const lines: Line[] = useMemo(() => (list.data ?? []).flatMap((r): Line[] => (r.subscriptions.length === 0
    ? [{ recipient: r, sub: null, key: r.id }]
    : r.subscriptions.map((s) => ({ recipient: r, sub: s, key: s.id })))), [list.data])
  const pending = (list.data ?? []).filter((r) => r.status === 'pending').length
  const pages = Math.max(1, Math.ceil(lines.length / perPage))
  useEffect(() => { if (page > pages) setPage(pages) }, [page, pages])
  const shown = lines.slice((page - 1) * perPage, page * perPage)
  const preview = useConfirmationPreview(askConfirm)
  const send = useSendConfirmations()

  if (list.isLoading) return <Skeleton className="h-40" />
  if (list.isError) return <p className="text-sm text-danger">{t('failed')} <button className="underline" onClick={() => void list.refetch()}>{t('retry')}</button></p>

  const roleLabel = (k: string) => type.role_labels[k]?.[lang] ?? k
  const isOrg = (email: string) => data.settings.org_domains.includes((email.split('@')[1] ?? '').toLowerCase())
  const guard = { onError: (e: unknown) => setError(toApiError(e).message) }

  const rowActions = (l: Line) => (
    <div className="flex items-center gap-1">
      {l.sub && l.sub.status === 'active' && <IconBtn label={t('pause')} testid={`pause-${l.key}`} onClick={() => actions.pause.mutate(l.sub!.id, guard)}><Pause size={15} /></IconBtn>}
      {l.sub && l.sub.status === 'paused' && <IconBtn label={t('resume')} testid={`resume-${l.key}`} onClick={() => actions.resume.mutate(l.sub!.id, guard)}><Play size={15} /></IconBtn>}
      {l.recipient.status === 'pending' && isOrg(l.recipient.email) && l.recipient.role_key !== type.client_role && (
        <IconBtn label={t('approveInternally')} testid={`approve-${l.key}`} onClick={() => approve.mutate(l.recipient.id, guard)}><ShieldCheck size={15} /></IconBtn>
      )}
      <IconBtn label={t('edit')} testid={`edit-${l.key}`} onClick={() => setWizard({ open: true, row: l.recipient, subscriptionId: l.sub?.id ?? null })}><Pencil size={15} /></IconBtn>
      {l.sub && <IconBtn label={t('duplicate')} testid={`dup-${l.key}`} onClick={() => actions.duplicate.mutate(l.sub!.id, guard)}><Copy size={15} /></IconBtn>}
      {l.sub && <IconBtn label={t('delete')} testid={`del-${l.key}`} onClick={() => setRemoving(l)}><Trash2 size={15} /></IconBtn>}
    </div>
  )
  const statusPill = (l: Line) => { const s = l.sub?.status ?? l.recipient.status; return <StatusPill tone={STATUS_TONE[s]}>{t(STATUS_WORD[s])}</StatusPill> }
  const who = (l: Line) => <span title={l.recipient.email}><span className="font-semibold">{l.recipient.name || l.recipient.email}</span>{l.recipient.name ? <span className="ms-1 text-xs text-text-muted" dir="ltr">{l.recipient.email}</span> : null}</span>

  return (
    <div data-testid="recipients-tab" className="flex min-h-0 flex-1 flex-col gap-2">
      <LockedNote entitlement="notifications.external" />
      <div className="flex flex-wrap items-center gap-2">
        <Button size="sm" disabled={!gate.editable} onClick={() => setWizard({ open: true, row: null, subscriptionId: null })} data-testid="add-recipient"><Plus size={15} aria-hidden /> {t('addRecipient')}</Button>
        {isOwner && pending > 0 && <Button size="sm" variant="secondary" disabled={!gate.editable} onClick={() => setAskConfirm(true)} data-testid="send-confirmations">{t('sendConfirmations')}</Button>}
        {error && <span role="alert" className="text-xs text-danger">{error}</span>}
      </div>
      <div ref={ref as never} className="flex min-h-0 flex-1 flex-col gap-1">
        <div className="min-h-0 flex-1 overflow-y-auto md:overflow-hidden">
          <ResponsiveList
            testid="recipients"
            rows={shown}
            rowKey={(l) => l.key}
            empty={<p className="rounded-xl border border-dashed border-border p-6 text-center text-sm text-text-muted">{t('noRecipients')}</p>}
            columns={[
              { key: 'who', header: t('colRecipient'), cell: who },
              { key: 'role', header: t('colRole'), width: '14%', cell: (l) => roleLabel(l.recipient.role_key) },
              { key: 'projects', header: t('colProjects'), width: '9%', align: 'center', cell: (l) => <span className="tnum">{l.sub?.project_ids.length ?? 0}</span> },
              { key: 'status', header: t('colStatus'), width: '15%', cell: statusPill },
              { key: 'sched', header: t('colSchedule'), width: '16%', cell: (l) => <span dir="ltr" className="text-xs text-text-secondary">{l.sub?.frequency ?? '-'} {l.sub?.send_time ?? ''}</span> },
              { key: 'actions', header: '', width: '20%', align: 'end', cell: rowActions },
            ]}
            renderCard={(l) => (
              <div className="space-y-2 rounded-xl border border-border bg-surface p-3" data-testid={`recipient-card-${l.key}`}>
                <div className="flex items-start justify-between gap-2"><div className="min-w-0 break-words text-sm">{who(l)}</div>{statusPill(l)}</div>
                <div className="text-xs text-text-secondary">{roleLabel(l.recipient.role_key)} - {t('colProjects')}: <span className="tnum">{l.sub?.project_ids.length ?? 0}</span></div>
                {rowActions(l)}
              </div>
            )}
          />
        </div>
        <Pager page={page} pages={pages} onPage={setPage} total={lines.length} ar={ar} testid="recipients-pager" hideWhenSingle />
      </div>

      {wizard.open && <RecipientWizard key={wizard.subscriptionId ?? wizard.row?.id ?? 'new'} open data={data} editing={wizard.row} editingSubscriptionId={wizard.subscriptionId} userEmail={userEmail} onClose={() => setWizard({ open: false, row: null, subscriptionId: null })} />}

      <Modal open={removing !== null} onClose={() => setRemoving(null)} size="sm" title={t('confirmDelete')}
        footer={<><Button variant="ghost" onClick={() => setRemoving(null)}>{t('cancel')}</Button>
          <Button variant="danger" data-testid="confirm-delete" onClick={() => { if (removing?.sub) actions.remove.mutate(removing.sub.id, { ...guard, onSuccess: () => setRemoving(null) }) }}>{t('delete')}</Button></>}>
        <p className="text-sm">{t('deleteRecipientText')}</p>
      </Modal>

      <Modal open={askConfirm} onClose={() => setAskConfirm(false)} size="sm" title={t('sendConfirmations')}
        footer={<><Button variant="ghost" onClick={() => setAskConfirm(false)}>{t('cancel')}</Button>
          <Button data-testid="confirm-send-go" disabled={!preview.data || preview.data.recipients < 1} loading={send.isPending}
            onClick={() => { if (preview.data) send.mutate(preview.data.recipients, { ...guard, onSuccess: () => setAskConfirm(false) }) }}>{t('confirmSend')}</Button></>}>
        {preview.isLoading ? <Skeleton className="h-12" /> : <p data-testid="confirm-preview" className="text-sm">{t('confirmPreview', { n: preview.data?.recipients ?? 0 })}</p>}
      </Modal>
    </div>
  )
}

function IconBtn({ label, onClick, children, testid }: { label: string; onClick: () => void; children: React.ReactNode; testid?: string }) {
  return (
    <button type="button" aria-label={label} title={label} data-testid={testid} onClick={(e) => { e.stopPropagation(); onClick() }}
      className="flex h-8 w-8 items-center justify-center rounded-lg text-text-secondary hover:bg-surface-hover hover:text-text-primary">
      {children}
    </button>
  )
}
