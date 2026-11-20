import { X } from 'lucide-react'

/**
 * POPUP-CLOSE-001 — Owner 2026-11-20: «زر الإغلاق صغير». One close control for every window: the ✕ with the word
 * «إغلاق» beside it, in a bordered pill a finger and an eye find at once (40px). Kept lean: it ships in the public
 * report's bundle, which has a hard size budget — the language is read off the document, not from a store.
 */
export function CloseButton({ onClose, label, testid, autoFocus }: { onClose: () => void; label?: string; testid?: string; autoFocus?: boolean }) {
  const word = document.documentElement.lang === 'en' ? 'Close' : 'إغلاق'

  return (
    <button type="button" onClick={onClose} aria-label={label ?? word} data-testid={testid} data-autofocus={autoFocus || undefined} className="inline-flex h-10 items-center gap-1 rounded-xl border border-border px-3 text-sm font-semibold text-text-secondary hover:bg-surface-hover">
      <X size={16} aria-hidden />
      {word}
    </button>
  )
}
