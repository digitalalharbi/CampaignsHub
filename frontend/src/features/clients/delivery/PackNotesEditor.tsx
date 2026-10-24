import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, EyeOff, Loader2, Plus, Trash2 } from 'lucide-react'
import { toApiError } from '@/lib/api/client'
import { addNote, deleteNote, NOTE_KINDS, orderNotes, updateNote, type NoteKind, type PackNote } from './deliveryApi'

const COPY = {
  ar: {
    title: 'ملاحظاتك وتوصياتك', hint: 'تُضاف للتقرير مع الملاحظات الآلية. «داخلي» لا يراه العميل في أي مكان.',
    kinds: { observation: 'ملاحظة', challenge: 'تحدٍّ', opportunity: 'فرصة تحسين', recommendation: 'توصية' } as Record<NoteKind, string>,
    textAr: 'النص بالعربية', textEn: 'النص بالإنجليزية (اختياري)', add: 'إضافة', client: 'للعميل', internal: 'داخلي', remove: 'حذف',
    up: 'تقديم', down: 'تأخير', save: 'حفظ', needText: 'اكتب نص الملاحظة أولًا.', empty: 'لا توجد ملاحظات كتبتها بعد.',
  },
  en: {
    title: 'Your notes and recommendations', hint: 'Added to the report beside the generated notes. «Internal» is never shown to the client anywhere.',
    kinds: { observation: 'Observation', challenge: 'Challenge', opportunity: 'Opportunity', recommendation: 'Recommendation' } as Record<NoteKind, string>,
    textAr: 'Text in Arabic', textEn: 'Text in English (optional)', add: 'Add', client: 'Client', internal: 'Internal', remove: 'Delete',
    up: 'Move up', down: 'Move down', save: 'Save', needText: 'Write the note first.', empty: 'You have not written any notes yet.',
  },
}

/**
 * DELIVERY-NOTES-001 — the Owner's own notes in a delivery: add, edit in place, order, keep internal, delete.
 * Every change is a server call that regenerates the report, so the live link, PDF and WhatsApp text follow.
 */
export function PackNotesEditor({ packId, notes, ar, open, onChange }: { packId: string; notes: PackNote[]; ar: boolean; open: boolean; onChange: () => void }) {
  const t = ar ? COPY.ar : COPY.en
  const [kind, setKind] = useState<NoteKind>('observation')
  const [textAr, setTextAr] = useState('')
  const [textEn, setTextEn] = useState('')
  const [audience, setAudience] = useState<PackNote['audience']>('client')
  const [local, setLocal] = useState<string | null>(null)

  const done = () => { setLocal(null); onChange() }
  const add = useMutation({
    mutationFn: () => addNote(packId, { kind, text_ar: textAr, text_en: textEn, audience }),
    onSuccess: () => { setTextAr(''); setTextEn(''); done() },
  })
  const patch = useMutation({ mutationFn: (v: { id: string; body: Parameters<typeof updateNote>[2] }) => updateNote(packId, v.id, v.body), onSuccess: done })
  const remove = useMutation({ mutationFn: (id: string) => deleteNote(packId, id), onSuccess: done })
  const order = useMutation({ mutationFn: (ids: string[]) => orderNotes(packId, ids), onSuccess: done })
  const error = add.error ?? patch.error ?? remove.error ?? order.error
  const busy = add.isPending || patch.isPending || remove.isPending || order.isPending

  const move = (i: number, d: -1 | 1) => {
    const ids = notes.map((n) => n.id)
    const j = i + d
    if (j < 0 || j >= ids.length) return
    ;[ids[i], ids[j]] = [ids[j]!, ids[i]!]
    order.mutate(ids)
  }
  const field = 'w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs'
  const chip = (on: boolean) => `rounded-lg px-2.5 py-1 text-[11px] font-semibold ${on ? 'bg-brand-600 text-white' : 'border border-border text-text-secondary hover:bg-surface-secondary'} disabled:opacity-60`

  return (
    <div data-testid="pack-notes">
      <h4 className="mb-1 font-bold text-text-primary">{t.title}</h4>
      <p className="mb-2 text-xs text-text-muted">{t.hint}</p>

      {notes.length === 0 && <p className="mb-2 text-xs text-text-secondary">{t.empty}</p>}
      <ul className="mb-3 space-y-2">
        {notes.map((n, i) => (
          <li key={n.id} data-testid={`note-${n.id}`} className="rounded-xl border border-border p-2.5">
            <div className="mb-1.5 flex flex-wrap items-center gap-1.5">
              <span className="rounded-full bg-surface-secondary px-2 py-0.5 text-[11px] font-semibold text-text-secondary">{t.kinds[n.kind]}</span>
              {n.audience === 'internal' && <span className="flex items-center gap-1 rounded-full bg-warning/15 px-2 py-0.5 text-[11px] font-semibold text-warning"><EyeOff size={11} /> {t.internal}</span>}
              {open && (
                <span className="ms-auto flex items-center gap-1">
                  <button type="button" aria-label={t.up} disabled={busy || i === 0} onClick={() => move(i, -1)} className="rounded p-1 text-text-muted hover:text-text-primary disabled:opacity-30"><ArrowUp size={14} /></button>
                  <button type="button" aria-label={t.down} disabled={busy || i === notes.length - 1} onClick={() => move(i, 1)} className="rounded p-1 text-text-muted hover:text-text-primary disabled:opacity-30"><ArrowDown size={14} /></button>
                  <button type="button" data-testid={`note-audience-${n.id}`} disabled={busy} onClick={() => patch.mutate({ id: n.id, body: { audience: n.audience === 'client' ? 'internal' : 'client' } })} className={chip(false)}>{n.audience === 'client' ? t.internal : t.client}</button>
                  <button type="button" data-testid={`note-delete-${n.id}`} aria-label={t.remove} disabled={busy} onClick={() => remove.mutate(n.id)} className="rounded p-1 text-text-muted hover:text-danger"><Trash2 size={14} /></button>
                </span>
              )}
            </div>
            {open ? (
              <textarea
                data-testid={`note-text-${n.id}`}
                defaultValue={n.text_ar ?? n.text_en ?? ''}
                rows={2}
                className={field}
                onBlur={(e) => {
                  const v = e.target.value.trim()
                  const current = (n.text_ar ?? n.text_en ?? '')
                  if (v !== '' && v !== current) patch.mutate({ id: n.id, body: n.text_ar !== null ? { text_ar: v } : { text_en: v } })
                }}
              />
            ) : <p className="text-xs text-text-primary">{n.text_ar ?? n.text_en}</p>}
          </li>
        ))}
      </ul>

      {open && (
        <form
          className="grid gap-2 rounded-xl border border-dashed border-border p-3"
          onSubmit={(e) => { e.preventDefault(); if (textAr.trim() === '' && textEn.trim() === '') { setLocal(t.needText); return } setLocal(null); add.mutate() }}
        >
          <div className="flex flex-wrap gap-1.5" role="group">
            {NOTE_KINDS.map((k) => <button key={k} type="button" data-testid={`note-kind-${k}`} aria-pressed={kind === k} onClick={() => setKind(k)} className={chip(kind === k)}>{t.kinds[k]}</button>)}
            <span className="ms-auto flex gap-1.5">
              {(['client', 'internal'] as const).map((a) => <button key={a} type="button" data-testid={`note-new-audience-${a}`} aria-pressed={audience === a} onClick={() => setAudience(a)} className={chip(audience === a)}>{t[a]}</button>)}
            </span>
          </div>
          <textarea data-testid="note-new-ar" aria-label={t.textAr} placeholder={t.textAr} rows={2} className={field} dir="rtl" value={textAr} onChange={(e) => setTextAr(e.target.value)} maxLength={600} />
          <textarea data-testid="note-new-en" aria-label={t.textEn} placeholder={t.textEn} rows={2} className={field} dir="ltr" value={textEn} onChange={(e) => setTextEn(e.target.value)} maxLength={600} />
          <div className="flex items-center gap-2">
            <button type="submit" data-testid="note-add" disabled={busy} className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60">
              {add.isPending ? <Loader2 size={13} className="animate-spin" /> : <Plus size={13} />} {t.add}
            </button>
            {local && <span role="alert" className="text-xs text-danger">{local}</span>}
          </div>
        </form>
      )}
      {error && <p role="alert" className="mt-2 text-xs text-danger">{toApiError(error).message}</p>}
    </div>
  )
}
