import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowLeft, ArrowRight, Sparkles, X } from 'lucide-react'

const KEY = 'home-ai-promo.dismissed'

function dismissed(): boolean {
  try { return window.localStorage.getItem(KEY) === '1' } catch { return false }
}

/**
 * AI-CONNECT-EASY-001 — the marketing page's corner card: the one feature worth interrupting a visitor for.
 *
 * Small on purpose. On a phone it is a slim bar above the browser's own toolbar (and above the iPhone home indicator,
 * through the safe-area inset); from tablet width up it is a card in the corner. Plain flex, fixed position and logical
 * insets only, so Safari, Chrome, Firefox, Edge and Samsung Internet draw it the same. ✕ is remembered on this browser.
 */
export function PublicAiPromo({ ar }: { ar: boolean }) {
  const tx = (a: string, e: string) => (ar ? a : e)
  const [hidden, setHidden] = useState(dismissed)

  // The automated browser (the e2e visual baseline) never sees it; every visitor does.
  if (hidden || (typeof navigator !== 'undefined' && navigator.webdriver)) return null

  const close = () => {
    try { window.localStorage.setItem(KEY, '1') } catch { /* it simply returns next visit */ }
    setHidden(true)
  }
  const Forward = ar ? ArrowLeft : ArrowRight

  return (
    <aside
      className="fixed inset-x-3 bottom-3 z-40 rounded-2xl border border-border bg-surface p-3 shadow-[var(--shadow-large)] sm:inset-x-auto sm:end-4 sm:bottom-4 sm:w-80 sm:p-4"
      style={{ marginBottom: 'env(safe-area-inset-bottom, 0px)' }}
      data-testid="home-ai-promo"
    >
      <div className="flex items-start gap-2">
        <span className="inline-flex items-center gap-1.5 rounded-[var(--radius-pill)] bg-brand-primary-soft px-2 py-0.5 text-[11px] font-bold text-brand-700">
          <Sparkles size={12} aria-hidden /> {tx('جديد', 'New')}
        </span>
        <button type="button" onClick={close} aria-label={tx('إغلاق', 'Close')} className="ms-auto rounded-md p-1 text-text-muted hover:bg-surface-hover" data-testid="home-ai-promo-close">
          <X size={15} aria-hidden />
        </button>
      </div>
      <p className="mt-1.5 text-sm font-bold leading-snug text-text-primary">{tx('اسأل Claude أو ChatGPT عن إعلاناتك ومتجرك', 'Ask Claude or ChatGPT about your ads and your store')}</p>
      <p className="mt-1 hidden text-xs leading-relaxed text-text-secondary sm:block">
        {tx('سناب وتيك توك وميتا ومتجرك في سلة، في محادثة وحدة وبلهجتك. ربط بدقيقة بدون رموز.', 'Snapchat, TikTok, Meta and your Salla store in one conversation. Connected in a minute, no tokens.')}
      </p>
      <div className="mt-2.5 flex items-center gap-2">
        <Link
          to="/register"
          onClick={close}
          className="flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-primary px-3 py-2 text-sm font-semibold text-on-primary hover:opacity-90"
          data-testid="home-ai-promo-start"
        >
          {tx('ابدأ الآن', 'Start now')} <Forward size={15} aria-hidden />
        </Link>
        <a href="#ai" className="rounded-xl border border-border px-3 py-2 text-sm font-semibold text-text-primary hover:bg-surface-hover" data-testid="home-ai-promo-more">
          {tx('كيف؟', 'How?')}
        </a>
      </div>
    </aside>
  )
}
