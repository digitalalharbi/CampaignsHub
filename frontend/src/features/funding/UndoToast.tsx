import { useEffect, useRef } from 'react'

/** How long «تراجع» stays offered after an action. The history list keeps it on the latest entry after that. */
export const UNDO_WINDOW_MS = 12_000

/**
 * After an add, an edit or a delete: what happened, and one button that undoes exactly that. It goes away by
 * itself; nothing waits for it and it never covers the board's controls (bottom, centred, narrow).
 */
export function UndoToast({ message, undoLabel, onUndo, onDismiss, pending = false, failed = null }: {
  message: string
  undoLabel: string
  /** Absent once the action was itself an undo: there is nothing further to offer. */
  onUndo?: () => void
  onDismiss: () => void
  pending?: boolean
  failed?: string | null
}) {
  const dismiss = useRef(onDismiss)
  dismiss.current = onDismiss
  useEffect(() => {
    const t = setTimeout(() => dismiss.current(), UNDO_WINDOW_MS)

    return () => clearTimeout(t)
  }, [message])

  return (
    <div
      role="status"
      data-testid="undo-toast"
      className="fixed bottom-4 start-1/2 z-50 flex max-w-[calc(100vw-2rem)] -translate-x-1/2 items-center gap-3 rounded-xl bg-text-primary px-4 py-2.5 text-sm font-semibold text-surface shadow-lg rtl:translate-x-1/2"
    >
      <span className="min-w-0 truncate">{failed ?? message}</span>
      {onUndo && (
        <button
          type="button"
          onClick={onUndo}
          disabled={pending}
          data-testid="undo-toast-undo"
          className="shrink-0 rounded-lg px-2 py-0.5 font-extrabold text-brand-300 underline-offset-2 hover:underline disabled:opacity-50"
        >
          {undoLabel}
        </button>
      )}
    </div>
  )
}
