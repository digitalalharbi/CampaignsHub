import { useState } from 'react'
import { Check, Copy } from 'lucide-react'
import { useUi } from '@/stores/ui'

/**
 * A provider's long identifier, kept behind a copy icon (Owner 2026-11-06).
 *
 * An `adacct_…` or a UUID is not something a reader reads — it is something they paste into the
 * platform's own console. So the row shows the NAME, and the identifier is one press away: the icon
 * copies it, says so, and carries it as its title for the reader who only wants to check a suffix.
 */
export function CopyId({ value, testId, className = '' }: { value: string; testId?: string; className?: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const [copied, setCopied] = useState(false)

  const copy = () => {
    void navigator.clipboard?.writeText(value).then(() => {
      setCopied(true)
      window.setTimeout(() => setCopied(false), 1500)
    }).catch(() => undefined)
  }

  return (
    <button
      type="button"
      onClick={(e) => { e.stopPropagation(); copy() }}
      title={value}
      aria-label={copied ? (ar ? 'تم النسخ' : 'Copied') : (ar ? 'نسخ المعرّف' : 'Copy the ID')}
      data-testid={testId}
      data-copied={copied ? 'true' : 'false'}
      className={`inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-text-muted transition-colors hover:bg-surface-hover hover:text-text-primary ${className}`}
    >
      {copied ? <Check size={13} aria-hidden /> : <Copy size={13} aria-hidden />}
    </button>
  )
}
