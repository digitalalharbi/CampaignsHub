import { useState } from 'react'

/**
 * A chip that does one real thing for the signed-in person and says, in one short word, what happened —
 * NOTIFICATION-ENROLMENT-001 («أرسل لي الآن» per digest, «أرسل تنبيهًا تجريبيًا» for an alert).
 *
 * Same pill as the matrix chips (28px, never moves the row). The result is an `aria-live` span of fixed minimum height, so
 * the answer appearing never shifts the layout, and a second press while one is running is ignored.
 */
interface Props {
  label: string
  /** Resolves to the word to show. Never throws: the caller turns a failure into a word. */
  run: () => Promise<string>
  testId: string
}

export function SendNowChip({ label, run, testId }: Props) {
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState('')

  const press = async () => {
    if (busy) return
    setBusy(true)
    setResult('…')
    try {
      setResult(await run())
    } finally {
      setBusy(false)
    }
  }

  return (
    <span className="inline-flex flex-wrap items-center gap-2">
      <button
        type="button"
        disabled={busy}
        onClick={() => void press()}
        data-testid={testId}
        className="inline-flex h-7 items-center justify-center rounded-[var(--radius-pill)] border border-border-strong bg-surface px-3 text-xs font-bold leading-none whitespace-nowrap text-text-primary hover:bg-surface-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 disabled:cursor-not-allowed disabled:opacity-55"
      >
        {label}
      </button>
      <span data-testid={`${testId}-result`} aria-live="polite" className="min-h-5 text-[13px] text-text-secondary">{result}</span>
    </span>
  )
}
