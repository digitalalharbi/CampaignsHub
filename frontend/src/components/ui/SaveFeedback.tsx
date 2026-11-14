import { useEffect } from 'react'
import { AlertTriangle, Check, Loader2, X } from 'lucide-react'
import { useSaveFeedback } from '@/stores/saveFeedback'
import { useUi } from '@/stores/ui'

const COPY = {
  ar: { saving: 'يحفظ…', saved: 'انحفظ', failed: 'ما انحفظ', close: 'إغلاق' },
  en: { saving: 'Saving…', saved: 'Saved', failed: 'Not saved', close: 'Close' },
} as const

/** «انحفظ» fades by itself; a failure stays until it is read, because its reason is the part that matters. */
const SAVED_MS = 2500
const FAILED_MS = 12000

/**
 * SAVE-STATE-001 — draws the shared save state. Mounted once, in `Providers`.
 *
 * `role="status"` with `aria-live="polite"`, so a screen reader hears the same three states a sighted person sees.
 */
export function SaveFeedback() {
  const notice = useSaveFeedback((s) => s.notice)
  const dismiss = useSaveFeedback((s) => s.dismiss)
  const locale = useUi((s) => s.locale)
  const c = COPY[locale]

  useEffect(() => {
    if (!notice || notice.kind === 'saving') return
    const timer = window.setTimeout(dismiss, notice.kind === 'saved' ? SAVED_MS : FAILED_MS)
    return () => window.clearTimeout(timer)
  }, [notice, dismiss])

  return (
    <div className="pointer-events-none fixed inset-x-0 bottom-4 z-[70] flex justify-center px-4" aria-live="polite" role="status">
      {notice ? (
        <div
          data-testid="save-feedback"
          data-state={notice.kind}
          className={`pointer-events-auto flex max-w-[min(92vw,520px)] items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold shadow-[var(--shadow-medium)] ${
            notice.kind === 'failed'
              ? 'border-danger/40 bg-surface text-danger'
              : notice.kind === 'saved'
                ? 'border-success/40 bg-surface text-success'
                : 'border-border-strong bg-surface text-text-secondary'
          }`}
        >
          {notice.kind === 'saving' ? <Loader2 size={16} className="animate-spin" aria-hidden /> : null}
          {notice.kind === 'saved' ? <Check size={16} aria-hidden /> : null}
          {notice.kind === 'failed' ? <AlertTriangle size={16} aria-hidden /> : null}
          <span>
            {c[notice.kind]}
            {notice.kind === 'failed' && notice.reason ? <span className="font-normal text-text-primary">{locale === 'ar' ? ': ' : ': '}{notice.reason}</span> : null}
          </span>
          {notice.kind === 'failed' ? (
            <button type="button" onClick={() => useSaveFeedback.setState({ notice: null })} aria-label={c.close} className="ms-1 rounded p-0.5 text-text-muted hover:text-text-primary">
              <X size={14} aria-hidden />
            </button>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}
