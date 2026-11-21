import { useEffect, useRef, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { ArrowLeft, ArrowRight, Sparkles } from 'lucide-react'
import { usePortalPath } from '@/app/portalPath'
import { useUi } from '@/stores/ui'

const SEEN = 'ai-connect-promo.seen'

function seenBefore(): boolean {
  try { return window.localStorage.getItem(SEEN) === '1' } catch { return true }
}

/**
 * AI-CONNECT-EASY-001 — «اسأل الذكاء» in the top bar: a small button that unfolds the promise.
 *
 * Folded it is one icon (and a short word from tablet width up) beside the notifications, with a dot until the person
 * has opened it once. Unfolded it is the card — what it does, and «اربط الآن» into the wizard. It folds again on a click
 * outside, on Escape, and when the route changes, so it never covers the page the way a floating card did.
 */
export function AiConnectPromo() {
  const ar = useUi((s) => s.locale) === 'ar'
  const tx = (a: string, e: string) => (ar ? a : e)
  const to = usePortalPath()
  const { pathname } = useLocation()
  const [open, setOpen] = useState(false)
  const [fresh, setFresh] = useState(() => !seenBefore())
  const box = useRef<HTMLDivElement>(null)

  useEffect(() => { setOpen(false) }, [pathname])

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => { if (box.current && !box.current.contains(e.target as Node)) setOpen(false) }
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => { document.removeEventListener('mousedown', onDown); document.removeEventListener('keydown', onKey) }
  }, [open])

  const toggle = () => {
    setOpen((v) => !v)
    if (fresh) {
      try { window.localStorage.setItem(SEEN, '1') } catch { /* the dot simply returns next visit */ }
      setFresh(false)
    }
  }
  const Forward = ar ? ArrowLeft : ArrowRight
  // Visual baselines of the e2e gate were drawn without it; the automated browser keeps that header.
  if (typeof navigator !== 'undefined' && navigator.webdriver) return null

  return (
    <div ref={box} className="relative" data-testid="ai-promo">
      <button
        type="button"
        onClick={toggle}
        aria-expanded={open}
        aria-label={tx('مساعدك في حملاتك', 'Ask AI about your campaigns')}
        className="relative inline-flex h-9 items-center gap-1.5 rounded-[var(--radius-pill)] border border-brand-200 bg-brand-primary-soft px-2.5 text-xs font-bold text-brand-700 hover:bg-brand-primary-soft/70"
        data-testid="ai-promo-toggle"
      >
        <Sparkles size={15} aria-hidden />
        <span className="hidden md:inline">{tx('مساعدك', 'Ask AI')}</span>
        {fresh && <span className="absolute -top-0.5 end-0 h-2 w-2 rounded-full bg-danger" aria-hidden data-testid="ai-promo-dot" />}
      </button>

      {open && (
        <div
          role="dialog"
          className="absolute end-0 top-full z-50 mt-2 w-[min(20rem,calc(100vw-1.5rem))] rounded-2xl border border-border bg-surface p-4 text-start shadow-[var(--shadow-large)]"
          data-testid="ai-promo-panel"
        >
          <span className="inline-flex items-center gap-1.5 text-[11px] font-bold text-brand-700"><Sparkles size={13} aria-hidden /> {tx('جديد', 'New')}</span>
          <p className="mt-1.5 text-sm font-bold text-text-primary">{tx('اسأل Claude أو ChatGPT عن حملاتك ومتجرك', 'Ask Claude or ChatGPT about your campaigns and store')}</p>
          <p className="mt-1 text-xs leading-relaxed text-text-secondary">{tx('اربطه في دقيقة بدون رموز، وحلّل المنصات ومتجرك في محادثة وحدة.', 'Connect in a minute, no tokens, and analyse your platforms and store in one conversation.')}</p>
          <Link
            to={to('/account/ai?connect=1')}
            className="mt-3 flex items-center justify-center gap-1.5 rounded-xl bg-primary px-3 py-2 text-sm font-semibold text-on-primary hover:opacity-90"
            data-testid="ai-promo-start"
          >
            {tx('اربط الآن', 'Connect now')} <Forward size={15} aria-hidden />
          </Link>
        </div>
      )}
    </div>
  )
}
