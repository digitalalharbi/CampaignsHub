import type { ReactNode } from 'react'

/**
 * One cell of the notification matrix — NOTIFICATION-CHANNELS-001.
 *
 * Five states, each a different promise, none conveyed by colour alone:
 *
 * - `on` / `off` — a switch the person controls (`role="switch"`, so it is keyboard- and screen-reader-operable).
 * - `locked` — on and cannot be switched off (account and security). A lock icon and the word, never a
 *   disabled checkbox, which reads as a bug.
 * - `na` — this message has no such channel (a digest has no bell item). A dash and nothing to press.
 * - `soon` — the channel is planned and not available yet. A chip that says so, and is not pressable.
 *
 * Fixed height and a minimum width, so a row never changes size when a state does.
 */
export type ChipState = 'on' | 'off' | 'locked' | 'na' | 'soon'

interface Props {
  state: ChipState
  /** The accessible name, e.g. «سرعة استهلاك الميزانية — بريد». */
  label: string
  onToggle?: (next: boolean) => void
  disabled?: boolean
  words: { on: string; off: string; locked: string; soon: string }
  testId?: string
}

const BASE = 'relative inline-flex h-7 min-w-[58px] items-center justify-center gap-1 rounded-[var(--radius-pill)] border px-2 text-xs font-bold leading-none whitespace-nowrap'

function Lock(): ReactNode {
  return (
    <svg width="11" height="11" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.8" aria-hidden>
      <rect x="3" y="7" width="10" height="7" rx="1.6" />
      <path d="M5.2 7V5a2.8 2.8 0 0 1 5.6 0v2" />
    </svg>
  )
}

function Tick(): ReactNode {
  return (
    <svg width="11" height="11" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
      <path d="m3.5 8.5 3 3 6-7" />
    </svg>
  )
}

export function ChannelChip({ state, label, onToggle, disabled, words, testId }: Props) {
  if (state === 'na') {
    return <span data-testid={testId} className="text-xs text-text-muted" aria-label={label}>—</span>
  }

  if (state === 'locked') {
    return (
      <span data-testid={testId} role="img" aria-label={`${label}: ${words.locked}`} className={`${BASE} border-border bg-surface-secondary text-text-secondary`}>
        <Lock />
        {words.locked}
      </span>
    )
  }

  if (state === 'soon') {
    return (
      <span data-testid={testId} role="img" aria-label={`${label}: ${words.soon}`} className={`${BASE} border-dashed border-border bg-transparent text-text-muted`}>
        {words.soon}
      </span>
    )
  }

  const on = state === 'on'

  return (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      aria-label={label}
      data-testid={testId}
      disabled={disabled}
      onClick={() => onToggle?.(!on)}
      // `after:` widens the tap target to a thumb without changing the chip, so a row keeps its height.
      className={`${BASE} after:absolute after:-inset-2 after:content-[''] transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
        on
          ? 'border-[var(--brand-600)] bg-[var(--brand-600)]/15 text-brand-600'
          : 'border-border bg-transparent text-text-muted hover:bg-surface-hover'
      }`}
    >
      {on ? <Tick /> : null}
      {on ? words.on : words.off}
    </button>
  )
}
