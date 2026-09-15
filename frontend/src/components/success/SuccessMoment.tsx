import { useEffect, useRef } from 'react'
import { createPortal } from 'react-dom'
import { Check, X } from 'lucide-react'
import { useUi } from '@/stores/ui'

/**
 * SUCCESS-MOMENT-001 — one premium confirmation, reusable for every milestone that earns one.
 *
 * A launch is the moment an operator finds out their work is live, and the product answered it with
 * a row quietly changing colour. This is the answer: what happened, to what, where it is running,
 * when — and the one thing they want next.
 *
 * ## It renders only what it was given
 *
 * Every field here comes from the caller, and the caller may only build one from a server response.
 * There is no default headline, no assumed platform list and no clock of its own: the timestamp is
 * the one the backend confirmed. A component that could compose a plausible success from nothing is
 * a component that will eventually be shown after a failure.
 *
 * ## Motion
 *
 * A ring draws, a mark lands, a few sparks drift and stop. `prefers-reduced-motion` removes all of
 * it and leaves the same information — the animation is the moment's manners, never its content.
 *
 * ## Focus
 *
 * Focus moves to the dialog on open and returns to whatever opened it on close; Escape closes; the
 * primary action is focused first because it is what most people came for. Rendered in a portal so
 * a card's `overflow-hidden` cannot clip it.
 */
export interface SuccessMomentTone {
  /** `success` for a clean result, `mixed` where some of it did not land. The two never look alike. */
  kind: 'success' | 'mixed'
}

export function SuccessMoment({
  open,
  onClose,
  headline,
  subject,
  badges,
  facts,
  at,
  atLabel,
  note,
  tone = { kind: 'success' },
  primary,
  secondary,
  testid = 'success-moment',
}: {
  open: boolean
  onClose: () => void
  headline: string
  /** What the milestone happened TO — a campaign name, a provider, a report. */
  subject: string
  /** Platform or scope chips. Empty renders nothing rather than an empty row. */
  badges?: string[]
  /** One line of qualification — what is NOT done. Only ever a sentence the caller was given. */
  note?: string
  /**
   * Short label/value pairs: objective, budget, accounts. Kept to the few that help a decision.
   *
   * `ltr` marks a value whose ORDER carries meaning — a date, a ratio, a count. Arabic is laid out
   * right-to-left, and a bidi-neutral string like `2026-09-15 15:55` gets its runs re-ordered on the
   * way to the screen, which is how a correct timestamp arrives reading `15:55 15-09-2026`.
   */
  facts?: Array<{ label: string; value: string; ltr?: boolean }>
  /** The server's own timestamp for the event, already formatted. Never generated here. */
  at?: string | null
  /** What the timestamp is — kept apart from the stamp so only the stamp is forced LTR. */
  atLabel?: string
  tone?: SuccessMomentTone
  primary?: { label: string; onClick: () => void }
  secondary?: { label: string; onClick: () => void }
  testid?: string
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const dialog = useRef<HTMLDivElement>(null)
  const primaryRef = useRef<HTMLButtonElement>(null)
  const restoreTo = useRef<Element | null>(null)

  useEffect(() => {
    if (!open) return

    restoreTo.current = document.activeElement
    primaryRef.current?.focus()

    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }

    document.addEventListener('keydown', onKey)

    return () => {
      document.removeEventListener('keydown', onKey)
      /*
       * Focus goes back where it came from. A dialog that drops focus on the body leaves a keyboard
       * reader at the top of the document, which is worse than never having moved it.
       */
      if (restoreTo.current instanceof HTMLElement) restoreTo.current.focus()
    }
  }, [open, onClose])

  if (!open) return null

  const mixed = tone.kind === 'mixed'

  return createPortal(
    <div
      className="fixed inset-0 z-[120] flex items-center justify-center bg-black/55 p-4 backdrop-blur-sm motion-safe:animate-[fadeIn_160ms_ease-out]"
      onClick={onClose}
      data-testid={`${testid}-backdrop`}
    >
      <div
        ref={dialog}
        role="dialog"
        aria-modal="true"
        aria-labelledby={`${testid}-headline`}
        data-testid={testid}
        onClick={(e) => e.stopPropagation()}
        className="relative w-full max-w-md overflow-hidden rounded-3xl border border-border bg-surface p-7 text-center shadow-2xl motion-safe:animate-[riseIn_260ms_cubic-bezier(.2,.9,.3,1)]"
      >
        <button
          onClick={onClose}
          aria-label={ar ? 'إغلاق' : 'Close'}
          data-testid={`${testid}-close`}
          className="absolute top-3 end-3 rounded-lg p-1.5 text-text-muted hover:bg-surface-hover hover:text-text-primary"
        >
          <X size={16} />
        </button>

        {/*
          * The mark. A ring draws, the tick lands, and a glow breathes once beneath it — three small
          * things rather than one large one, which is the difference between premium and loud.
          */}
        <div className="relative mx-auto mb-5 h-20 w-20">
          <span
            aria-hidden
            className={`absolute inset-0 rounded-full blur-xl motion-safe:animate-[pulseGlow_2.4s_ease-in-out_infinite] ${mixed ? 'bg-warning/25' : 'bg-success/25'}`}
          />
          <svg viewBox="0 0 80 80" className="relative h-20 w-20" aria-hidden>
            <circle cx="40" cy="40" r="34" fill="none" stroke="currentColor" strokeWidth="3" className={mixed ? 'text-warning/25' : 'text-success/25'} />
            <circle
              cx="40" cy="40" r="34" fill="none" strokeWidth="3" strokeLinecap="round"
              stroke="currentColor"
              className={`${mixed ? 'text-warning' : 'text-success'} motion-safe:animate-[drawRing_620ms_ease-out_forwards]`}
              style={{ strokeDasharray: 214, strokeDashoffset: 0, transform: 'rotate(-90deg)', transformOrigin: '50% 50%' }}
            />
          </svg>
          <span className={`absolute inset-0 flex items-center justify-center ${mixed ? 'text-warning' : 'text-success'} motion-safe:animate-[markIn_420ms_220ms_both]`}>
            <Check size={30} strokeWidth={3} />
          </span>
          {/* Four sparks, once. Restraint is the brief: this is a confirmation, not a party. */}
          {!mixed && [0, 1, 2, 3].map((i) => (
            <span
              key={i}
              aria-hidden
              className="absolute left-1/2 top-1/2 h-1 w-1 rounded-full bg-success/70 motion-safe:animate-[spark_900ms_ease-out_forwards] motion-reduce:hidden"
              style={{ ['--a' as string]: `${i * 90 + 45}deg`, animationDelay: `${260 + i * 60}ms` }}
            />
          ))}
        </div>

        <h2 id={`${testid}-headline`} className="text-xl font-extrabold tracking-tight text-text-primary">{headline}</h2>
        <p className="mt-1 truncate text-sm font-semibold text-text-secondary" title={subject}>{subject}</p>

        {/* Visible text, never a tooltip — a qualification nobody can see is not a qualification. */}
        {note !== undefined && note !== '' && (
          <p className="mt-2 text-xs text-warning" data-testid={`${testid}-note`}>{note}</p>
        )}

        {badges !== undefined && badges.length > 0 && (
          <div className="mt-3 flex flex-wrap items-center justify-center gap-1.5" data-testid={`${testid}-badges`}>
            {badges.map((b) => (
              <span key={b} className="rounded-full border border-border px-2.5 py-0.5 text-[11px] font-semibold text-text-secondary">{b}</span>
            ))}
          </div>
        )}

        {facts !== undefined && facts.length > 0 && (
          <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-start" data-testid={`${testid}-facts`}>
            {facts.map((f) => (
              <div key={f.label}>
                <dt className="text-[11px] text-text-muted">{f.label}</dt>
                <dd className="tnum text-sm font-semibold text-text-primary" dir={f.ltr === true ? 'ltr' : undefined}>{f.value}</dd>
              </div>
            ))}
          </dl>
        )}

        {at !== undefined && at !== null && at !== '' && (
          <p className="mt-4 text-[11px] text-text-muted" data-testid={`${testid}-at`}>
            {atLabel !== undefined && <span>{atLabel} · </span>}
            {/* The stamp itself is LTR whatever the page is — see `facts.ltr`. */}
            <span dir="ltr" className="tnum">{at}</span>
          </p>
        )}

        <div className="mt-6 flex flex-col gap-2">
          {primary !== undefined && (
            <button
              ref={primaryRef}
              onClick={primary.onClick}
              data-testid={`${testid}-primary`}
              className="rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-bold text-white shadow-[var(--shadow-small)] transition-colors hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:ring-offset-1 focus-visible:ring-offset-surface"
            >
              {primary.label}
            </button>
          )}
          {secondary !== undefined && (
            <button
              onClick={secondary.onClick}
              data-testid={`${testid}-secondary`}
              className="rounded-xl border border-border-strong px-4 py-2.5 text-sm font-semibold text-text-secondary hover:bg-surface-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40"
            >
              {secondary.label}
            </button>
          )}
        </div>
      </div>
    </div>,
    document.body,
  )
}
