import { useEffect, useState } from 'react'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { useDialogs } from '@/stores/dialogs'
import { useUi } from '@/stores/ui'

const COPY = {
  ar: { ok: 'تأكيد', cancel: 'إلغاء', save: 'احفظ' },
  en: { ok: 'Confirm', cancel: 'Cancel', save: 'Save' },
} as const

/** DIALOG-001 — draws whichever confirm or prompt is next in line. Mounted once, in `Providers`. */
export function DialogHost() {
  const current = useDialogs((s) => s.current)
  const close = useDialogs((s) => s.close)
  const locale = useUi((s) => s.locale)
  const c = COPY[locale]
  const [text, setText] = useState('')

  useEffect(() => {
    setText(current?.kind === 'prompt' ? (current.initial ?? '') : '')
  }, [current])

  if (!current) return null

  const finish = (value: boolean | string | null) => {
    if (current.kind === 'confirm') current.resolve(value === true)
    else current.resolve(typeof value === 'string' ? value : null)
    close()
  }
  const cancel = () => finish(current.kind === 'confirm' ? false : null)
  const blocked = current.kind === 'prompt' && ((current.required !== false && text.trim() === '') || text.trim().length < (current.minLength ?? 0))

  return (
    <Modal
      open
      onClose={cancel}
      title={current.title}
      size="sm"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={cancel} data-testid="dialog-cancel">{current.cancelLabel ?? c.cancel}</Button>
          <Button
            variant={current.kind === 'confirm' && current.danger ? 'danger' : 'primary'}
            disabled={blocked}
            data-testid="dialog-confirm"
            onClick={() => finish(current.kind === 'confirm' ? true : text.trim())}
          >
            {current.confirmLabel ?? (current.kind === 'prompt' ? c.save : c.ok)}
          </Button>
        </div>
      }
    >
      <div data-testid="dialog-body" className="flex flex-col gap-3">
        {current.body ? <p className="text-sm text-text-secondary">{current.body}</p> : null}
        {current.kind === 'prompt' ? (
          <label className="flex flex-col gap-1 text-sm font-medium text-text-primary">
            {current.label ?? ''}
            <input
              data-autofocus
              data-testid="dialog-input"
              value={text}
              onChange={(e) => setText(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter' && !blocked) finish(text.trim()) }}
              className="h-10 rounded-xl border border-border-strong bg-surface px-3 text-sm text-text-primary"
            />
          </label>
        ) : null}
      </div>
    </Modal>
  )
}
