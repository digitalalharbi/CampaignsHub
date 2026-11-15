import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Send, X } from 'lucide-react'
import { reroute, rerouteRecipients } from './followupApi'
import { useCopy } from './centerCopy'

/**
 * NOTIF-V2-FOLLOWUP-001 — «أعد التوجيه بضغطة»: send one internal report to a confirmed external recipient who is subscribed to its
 * project. The dialog only lists who the server says is eligible; the message then goes through the same checks, review and
 * log as every external message — it is queued here, never sent from the browser.
 */
export function RerouteDialog({ notificationId, onClose }: { notificationId: string; onClose: () => void }) {
  const c = useCopy()
  const [picked, setPicked] = useState('')
  const list = useQuery({ queryKey: ['followup', 'reroute', notificationId], queryFn: () => rerouteRecipients(notificationId) })
  const send = useMutation({ meta: { feedback: 'none' }, mutationFn: () => reroute(notificationId, picked) })
  const recipients = list.data?.recipients ?? []

  return (
    <div role="dialog" aria-modal="true" aria-label={c('reroute_title')} data-testid="reroute-dialog" className="absolute inset-x-3 bottom-3 z-10 rounded-xl border border-border bg-surface p-4 shadow-xl sm:inset-x-6">
      <div className="mb-2 flex items-center justify-between gap-2">
        <h3 className="text-sm font-bold text-text-primary">{c('reroute_title')}</h3>
        <button type="button" aria-label={c('close')} onClick={onClose} className="flex h-9 w-9 items-center justify-center rounded-lg hover:bg-surface-hover"><X size={15} /></button>
      </div>
      <p className="mb-3 text-xs text-text-secondary">{c('reroute_note')}</p>
      {send.isSuccess ? (
        <p role="status" data-testid="reroute-done" className="text-sm font-semibold text-success">{c('reroute_done')}</p>
      ) : recipients.length === 0 ? (
        <p data-testid="reroute-none" className="text-sm text-text-muted">{c('reroute_none')}</p>
      ) : (
        <div className="flex flex-col gap-2">
          <select aria-label={c('reroute_title')} data-testid="reroute-select" value={picked} onChange={(e) => setPicked(e.target.value)} className="h-10 w-full rounded-lg border border-border bg-surface px-2 text-sm text-text-primary">
            <option value="" disabled>{c('pick_person')}</option>
            {recipients.map((r) => <option key={r.id} value={r.id}>{r.name ? `${r.name} — ${r.email}` : r.email}</option>)}
          </select>
          <button type="button" data-testid="reroute-send" disabled={picked === '' || send.isPending} onClick={() => send.mutate()}
            className="inline-flex h-10 items-center justify-center gap-1 rounded-lg bg-brand-500 px-4 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50"><Send size={14} /> {c('reroute_send')}</button>
        </div>
      )}
    </div>
  )
}
