import { useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { ArrowLeft, ArrowRight, Sparkles, X } from 'lucide-react'
import { usePortalPath } from '@/app/portalPath'
import { useUi } from '@/stores/ui'

const KEY = 'ai-connect-promo.dismissed'

function dismissed(): boolean {
  try { return window.localStorage.getItem(KEY) === '1' } catch { return false }
}

/**
 * AI-CONNECT-EASY-001 — the small card in the corner that tells people the feature exists.
 *
 * One line of promise, one button into the wizard, and a ✕ that is remembered on this browser. It hides on the page it
 * points at, and on phones (the tab bar owns the bottom of the screen there).
 */
export function AiConnectPromo() {
  const ar = useUi((s) => s.locale) === 'ar'
  const tx = (a: string, e: string) => (ar ? a : e)
  const to = usePortalPath()
  const { pathname } = useLocation()
  const [hidden, setHidden] = useState(dismissed)

  if (hidden || pathname.endsWith('/account/ai')) return null

  const close = () => {
    try { window.localStorage.setItem(KEY, '1') } catch { /* the card simply returns next visit */ }
    setHidden(true)
  }
  const Forward = ar ? ArrowLeft : ArrowRight

  return (
    <aside className="fixed bottom-4 end-4 z-40 hidden w-80 rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-large)] md:block" data-testid="ai-promo">
      <div className="flex items-start gap-2">
        <span className="inline-flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-brand-700"><Sparkles size={13} aria-hidden /> {tx('جديد', 'New')}</span>
        <button type="button" onClick={close} aria-label={tx('إغلاق', 'Close')} className="ms-auto rounded-md p-0.5 text-text-muted hover:bg-surface-hover" data-testid="ai-promo-close"><X size={15} /></button>
      </div>
      <p className="mt-1.5 text-sm font-bold text-text-primary">{tx('اسأل Claude أو ChatGPT عن حملاتك ومتجرك', 'Ask Claude or ChatGPT about your campaigns and store')}</p>
      <p className="mt-1 text-xs leading-relaxed text-text-secondary">{tx('اربطه في دقيقة بدون رموز، وحلّل المنصات ومتجرك في محادثة وحدة.', 'Connect in a minute, no tokens, and analyse your platforms and store in one conversation.')}</p>
      <Link
        to={to('/account/ai?connect=1')}
        onClick={close}
        className="mt-3 flex items-center justify-center gap-1.5 rounded-xl bg-primary px-3 py-2 text-sm font-semibold text-on-primary hover:opacity-90"
        data-testid="ai-promo-start"
      >
        {tx('اربط الآن', 'Connect now')} <Forward size={15} aria-hidden />
      </Link>
    </aside>
  )
}
