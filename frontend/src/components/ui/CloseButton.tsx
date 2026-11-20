import { X } from 'lucide-react'
import { useUi } from '@/stores/ui'

/**
 * POPUP-CLOSE-001 — Owner 2026-11-20: «زر الإغلاق صغير». One close control for every window: the ✕ with the word
 * «إغلاق» beside it, in a bordered pill a finger and an eye find at once. On a phone the word stays; the target is 40px.
 */
export function CloseButton({ onClose, label, testid, autoFocus = false }: { onClose: () => void; label?: string; testid?: string; autoFocus?: boolean }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const word = ar ? 'إغلاق' : 'Close'

  return (
    <button
      type="button"
      onClick={onClose}
      aria-label={label ?? word}
      data-testid={testid}
      data-autofocus={autoFocus || undefined}
      className="inline-flex h-10 items-center gap-1 rounded-xl border border-border px-3 text-sm font-semibold text-text-secondary hover:bg-surface-hover hover:text-text-primary"
    >
      <X size={16} aria-hidden />
      <span>{word}</span>
    </button>
  )
}
