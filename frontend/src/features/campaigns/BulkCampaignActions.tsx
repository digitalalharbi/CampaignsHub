import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Pause, Play } from 'lucide-react'
import { ProviderMark } from '@/components/ProviderMark'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { getData } from '@/lib/api/client'
import { STATE_LABELS, performWrite, type ActionState, type WriteEntity, type WriteOptions, type WriteResult } from './providerWrites'
import { providerLabel } from './labels'
import { useAuth } from '@/stores/auth'

/**
 * CAMPAIGNS-TABLE-002 — pause or resume several campaigns at once, on the platforms, through the same per-campaign
 * write path the control tab uses.
 *
 * Nothing is sent before the confirmation dialog. For each selected campaign the server's own write options are
 * read first, so a platform campaign the registry, the permission set, the account selection or the connection
 * status would refuse is reported as refused rather than attempted; what the platform confirmed is what is shown.
 * A campaign with no platform campaign behind it is reported as such — this product never pauses a draft.
 */
export type BulkOutcome = { campaignId: string; name: string; provider: string | null; result: 'done' | 'refused' | 'nothing'; message: string | null }

export async function runBulk(projectId: string, campaigns: Array<{ id: string; name: string }>, action: 'pause' | 'resume'): Promise<BulkOutcome[]> {
  const out: BulkOutcome[] = []
  for (const c of campaigns) {
    try {
      const options = await getData<WriteOptions>(`/projects/${projectId}/campaigns/${c.id}/provider-writes`)
      const targets = options.entities.filter((e: WriteEntity) => e.level === 'campaign')
      if (targets.length === 0) {
        out.push({ campaignId: c.id, name: c.name, provider: null, result: 'nothing', message: null })
        continue
      }
      for (const e of targets) {
        if (e.actions[action] !== 'available') {
          out.push({ campaignId: c.id, name: c.name, provider: e.provider, result: 'refused', message: e.actions[action] })
          continue
        }
        const r: WriteResult = await performWrite(projectId, c.id, { level: 'campaign', entity_id: e.id, action })
        out.push({ campaignId: c.id, name: c.name, provider: e.provider, result: r.ok ? 'done' : 'refused', message: r.ok ? null : (r.message ?? r.refusal ?? null) })
      }
    } catch (err) {
      out.push({ campaignId: c.id, name: c.name, provider: null, result: 'refused', message: err instanceof Error ? err.message : String(err) })
    }
  }
  return out
}

export function BulkCampaignActions({ projectId, selected, onClear, ar }: {
  projectId: string
  selected: Array<{ id: string; name: string }>
  onClear: () => void
  ar: boolean
}) {
  const qc = useQueryClient()
  const canPause = useAuth((s) => s.hasPermission('campaigns.pause'))
  const [confirm, setConfirm] = useState<'pause' | 'resume' | null>(null)
  const [busy, setBusy] = useState(false)
  const [outcomes, setOutcomes] = useState<BulkOutcome[] | null>(null)

  if (selected.length === 0 && outcomes === null) return null

  const run = async () => {
    if (!confirm) return
    setBusy(true)
    const out = await runBulk(projectId, selected, confirm)
    setBusy(false)
    setConfirm(null)
    setOutcomes(out)
    void qc.invalidateQueries({ queryKey: ['project', projectId, 'campaigns'] })
  }

  const reason = (m: string | null) => {
    const known = m && m in STATE_LABELS ? STATE_LABELS[m as Exclude<ActionState, 'available'>] : null
    return known ? (ar ? known.ar : known.en) : (m ?? '')
  }
  const label = (o: BulkOutcome) => o.result === 'done'
    ? (ar ? 'أكّدت المنصة' : 'Platform confirmed')
    : o.result === 'nothing'
      ? (ar ? 'لا حملة على منصة' : 'No platform campaign')
      : (ar ? `رُفض: ${reason(o.message)}` : `Refused: ${reason(o.message)}`)

  return (
    <div className="flex flex-wrap items-center gap-2" data-testid="campaigns-bulk">
      {selected.length > 0 && (
        <>
          <span className="text-xs font-bold text-text-secondary" data-testid="campaigns-bulk-count">{ar ? `المحدد: ${selected.length}` : `Selected: ${selected.length}`}</span>
          {canPause ? (
            <>
              <Button size="sm" variant="secondary" onClick={() => setConfirm('pause')} data-testid="campaigns-bulk-pause"><Pause size={13} aria-hidden /> {ar ? 'إيقاف على المنصات' : 'Pause on the platforms'}</Button>
              <Button size="sm" variant="secondary" onClick={() => setConfirm('resume')} data-testid="campaigns-bulk-resume"><Play size={13} aria-hidden /> {ar ? 'تشغيل على المنصات' : 'Resume on the platforms'}</Button>
            </>
          ) : (
            <span className="text-xs text-text-muted" data-testid="campaigns-bulk-denied">{ar ? 'إيقاف الحملات وتشغيلها يحتاج صلاحية الإيقاف.' : 'Pausing and resuming campaigns needs the pause permission.'}</span>
          )}
          <button type="button" onClick={onClear} className="text-xs font-semibold text-text-muted hover:underline">{ar ? 'إلغاء التحديد' : 'Clear'}</button>
        </>
      )}
      {outcomes && (
        <ul className="w-full space-y-1 rounded-xl border border-border p-2 text-xs" data-testid="campaigns-bulk-results">
          {outcomes.map((o, i) => (
            <li key={`${o.campaignId}-${i}`} className="flex flex-wrap items-center gap-2">
              {o.provider && <ProviderMark provider={o.provider} size={14} />}
              <span className="font-semibold text-text-primary">{o.name}</span>
              {o.provider && <span className="text-text-muted">{providerLabel(o.provider, ar ? 'ar' : 'en')}</span>}
              <Badge tone={o.result === 'done' ? 'success' : o.result === 'nothing' ? 'neutral' : 'danger'}>{label(o)}</Badge>
            </li>
          ))}
          <li><button type="button" onClick={() => setOutcomes(null)} className="text-text-muted hover:underline">{ar ? 'إخفاء' : 'Dismiss'}</button></li>
        </ul>
      )}
      {confirm && (
        <Modal open onClose={() => { if (!busy) setConfirm(null) }} title={confirm === 'pause' ? (ar ? 'إيقاف الحملات المحددة' : 'Pause the selected campaigns') : (ar ? 'تشغيل الحملات المحددة' : 'Resume the selected campaigns')} size="md"
          footer={<><Button variant="secondary" disabled={busy} onClick={() => setConfirm(null)}>{ar ? 'إلغاء' : 'Cancel'}</Button><Button loading={busy} onClick={() => void run()} data-testid="campaigns-bulk-confirm">{confirm === 'pause' ? (ar ? 'نعم، أوقف' : 'Yes, pause') : (ar ? 'نعم، شغّل' : 'Yes, resume')}</Button></>}>
          <div className="space-y-2 text-sm" data-testid="campaigns-bulk-dialog">
            <p>{ar ? `سيُرسل الطلب إلى المنصات لـ ${selected.length} حملة. لا يُغيَّر شيء محليًا قبل تأكيد المنصة.` : `The request goes to the platforms for ${selected.length} campaign(s). Nothing changes locally before the platform confirms.`}</p>
            {confirm === 'resume' && <p className="font-semibold text-warning">{ar ? 'تشغيل حملة يبدأ الإنفاق عليها.' : 'Resuming a campaign starts its spend.'}</p>}
            <ul className="list-disc ps-5 text-text-secondary">{selected.map((c) => <li key={c.id}>{c.name}</li>)}</ul>
          </div>
        </Modal>
      )}
    </div>
  )
}
