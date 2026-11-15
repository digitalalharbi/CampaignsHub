import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Download, Pencil, Plus, Trash2, X } from 'lucide-react'
import { toApiError } from '@/lib/api/client'
import { addMemory, deleteMemory, editMemory, exportMemories, forgetAllMemories, listMemories } from './api'

/**
 * AGENTS-MEMORY-001 — «يتذكرني»: what the assistant remembers about ME in this workspace, to read, add, edit, delete, export or
 * forget at once. Nothing secret is accepted (the server refuses it); nobody else sees this list.
 */
export function MemoryManager({ ar }: { ar: boolean }) {
  const qc = useQueryClient()
  const [draft, setDraft] = useState('')
  const [editing, setEditing] = useState<{ id: string; text: string } | null>(null)
  const [confirmAll, setConfirmAll] = useState(false)
  const q = useQuery({ queryKey: ['ai-memories'], queryFn: listMemories, retry: false })
  const refresh = () => void qc.invalidateQueries({ queryKey: ['ai-memories'] })
  const add = useMutation({ meta: { feedback: 'none' }, mutationFn: (t: string) => addMemory(t), onSuccess: () => { setDraft(''); refresh() } })
  const edit = useMutation({ meta: { feedback: 'none' }, mutationFn: (v: { id: string; text: string }) => editMemory(v.id, v.text), onSuccess: () => { setEditing(null); refresh() } })
  const remove = useMutation({ meta: { feedback: 'none' }, mutationFn: (id: string) => deleteMemory(id), onSuccess: refresh })
  const forget = useMutation({ meta: { feedback: 'none' }, mutationFn: forgetAllMemories, onSuccess: () => { setConfirmAll(false); refresh() } })
  const download = useMutation({
    meta: { feedback: 'none' },
    mutationFn: exportMemories,
    onSuccess: (data) => {
      const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }))
      const a = document.createElement('a')
      a.href = url
      a.download = 'assistant-memory.json'
      a.click()
      URL.revokeObjectURL(url)
    },
  })

  const rows = q.data?.memories ?? []
  const max = q.data?.max ?? 30
  const maxChars = q.data?.max_chars ?? 280
  const error = add.error ?? edit.error ?? remove.error ?? forget.error

  return (
    <div className="space-y-2" data-testid="memory-manager">
      <p className="text-xs text-text-muted">{ar ? 'قل «تذكّر …» في المحادثة، أو أضف هنا. لا تُحفظ كلمات مرور أو مفاتيح أو أرقام تواصل.' : 'Say “remember …” in the chat, or add here. Passwords, keys and contact details are never saved.'}</p>
      {rows.length === 0 && <p className="text-xs text-text-muted" data-testid="memory-empty">{ar ? 'لا شيء محفوظ بعد.' : 'Nothing saved yet.'}</p>}
      <ul className="space-y-1">
        {rows.map((m) => (
          <li key={m.id} className="flex min-w-0 items-center gap-1.5 rounded-lg border border-border px-2 py-1.5 text-xs" data-testid="memory-row">
            {editing?.id === m.id
              ? (
                <form className="flex min-w-0 flex-1 items-center gap-1" onSubmit={(e) => { e.preventDefault(); if (editing.text.trim()) edit.mutate(editing) }}>
                  <input autoFocus value={editing.text} maxLength={maxChars} onChange={(e) => setEditing({ id: m.id, text: e.target.value })} dir="auto" className="h-7 min-w-0 flex-1 rounded-md border border-border bg-surface px-1.5" data-testid="memory-edit-input" />
                  <button type="submit" aria-label={ar ? 'حفظ' : 'Save'} className="text-brand-700"><Check size={13} /></button>
                  <button type="button" aria-label={ar ? 'إلغاء' : 'Cancel'} onClick={() => setEditing(null)} className="text-text-muted"><X size={13} /></button>
                </form>
              )
              : (
                <>
                  <span className="min-w-0 flex-1 break-words" dir="auto">{m.text}</span>
                  <button type="button" aria-label={ar ? 'تعديل' : 'Edit'} onClick={() => setEditing({ id: m.id, text: m.text })} className="text-text-muted hover:text-text-primary"><Pencil size={12} /></button>
                  <button type="button" aria-label={ar ? 'حذف' : 'Delete'} onClick={() => remove.mutate(m.id)} data-testid="memory-delete" className="text-text-muted hover:text-danger"><Trash2 size={12} /></button>
                </>
              )}
          </li>
        ))}
      </ul>
      {rows.length < max && (
        <form className="flex items-center gap-1.5" onSubmit={(e) => { e.preventDefault(); if (draft.trim()) add.mutate(draft.trim()) }}>
          <input value={draft} maxLength={maxChars} onChange={(e) => setDraft(e.target.value)} dir="auto" placeholder={ar ? 'مثلًا: اختصر دائمًا' : 'e.g. Keep it short'} data-testid="memory-input" className="h-8 min-w-0 flex-1 rounded-lg border border-border bg-surface px-2 text-xs" />
          <button type="submit" disabled={!draft.trim() || add.isPending} data-testid="memory-add" className="inline-flex h-8 items-center gap-1 rounded-lg bg-brand-600 px-2.5 text-xs font-semibold text-white disabled:opacity-50"><Plus size={12} />{ar ? 'أضف' : 'Add'}</button>
        </form>
      )}
      <div className="flex flex-wrap items-center gap-3 text-xs">
        <button type="button" onClick={() => download.mutate()} disabled={rows.length === 0} className="inline-flex items-center gap-1 text-text-secondary hover:text-text-primary disabled:opacity-50" data-testid="memory-export"><Download size={12} />{ar ? 'تصدير' : 'Export'}</button>
        {!confirmAll
          ? <button type="button" onClick={() => setConfirmAll(true)} disabled={rows.length === 0} className="text-danger disabled:opacity-50" data-testid="memory-forget-all">{ar ? 'انسَ الكل' : 'Forget all'}</button>
          : (
            <span className="flex items-center gap-2" data-testid="memory-forget-confirm">
              <span className="text-text-secondary">{ar ? 'سيُحذف كل ما تتذكره عنك نهائيًا.' : 'Everything it remembers about you will be deleted.'}</span>
              <button type="button" onClick={() => forget.mutate()} className="font-semibold text-danger" data-testid="memory-forget-yes">{ar ? 'نعم' : 'Yes'}</button>
              <button type="button" onClick={() => setConfirmAll(false)} className="text-text-muted">{ar ? 'إلغاء' : 'Cancel'}</button>
            </span>
          )}
      </div>
      {error && <p role="alert" className="text-xs text-danger">{toApiError(error).message === 'memory_rejected' ? (ar ? 'لم يُحفظ: لا كلمات مرور أو مفاتيح أو أرقام تواصل.' : 'Not saved: no passwords, keys or contact details.') : toApiError(error).message}</p>}
    </div>
  )
}
