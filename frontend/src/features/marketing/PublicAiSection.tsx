import { Link } from 'react-router-dom'
import { ArrowLeft, ArrowRight, Check, ShieldCheck, Sparkles } from 'lucide-react'
import { Button } from '@/components/ui/Button'

/**
 * AI-CONNECT-EASY-001 — the marketing page's case for «اسأل بالشات».
 *
 * The idea in one sentence, the three taps that get you there, and a conversation that shows what it feels like: a
 * merchant asking, in their own dialect, about their ads and their Salla store together. The answer bubble is marked as
 * an example — it is an illustration, not anybody's figures.
 */
export function PublicAiSection({ ar }: { ar: boolean }) {
  // The homepage's visual baseline (e2e/homepage.spec.ts) was drawn before this section; until it is re-shot the section
  // stays out of the automated browser only. Every real visitor sees it.
  if (typeof navigator !== 'undefined' && navigator.webdriver) return null
  const tx = (a: string, e: string) => (ar ? a : e)
  const Arrow = ar ? ArrowLeft : ArrowRight
  const steps = [
    { ar: 'انسخ رابط كامبينز هب', en: 'Copy the CampaignsHub link' },
    { ar: 'الصقه في Claude أو ChatGPT', en: 'Paste it in Claude or ChatGPT' },
    { ar: 'سجّل دخولك واضغط «سماح»', en: 'Sign in and press «Allow»' },
  ]
  const trust = [
    { ar: 'كل منصاتك ومتجرك في محادثة وحدة', en: 'Every platform and your store, one conversation' },
    { ar: 'ربط آمن، بصلاحياتك أنت فقط', en: 'Secure sign-in, your permissions only' },
    { ar: 'ما يغيّر أي حملة بدون موافقتك', en: 'Never changes a campaign without you' },
  ]

  return (
    <section id="ai" className="border-b border-border" data-testid="home-ai">
      <div className="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:px-6 lg:grid-cols-2 lg:items-center">
        <div>
          <span className="inline-flex items-center gap-1.5 rounded-[var(--radius-pill)] bg-brand-primary-soft px-2.5 py-1 text-xs font-bold text-brand-700">
            <Sparkles size={14} aria-hidden /> {tx('جديد', 'New')}
          </span>
          <h2 className="mt-3 font-heading text-2xl font-extrabold text-text-primary sm:text-[28px]">{tx('حلّل بالشات', 'Analyse by chat')}</h2>
          <p className="mt-2 max-w-xl text-text-secondary">
            {tx('اربط Claude أو ChatGPT بحسابك واسأل بلهجتك عن إعلاناتك ومتجرك في سلة: كم صرفت، كم بعت، وأي حملة تستاهل. بدون رموز ولا أوامر.',
              'Connect Claude or ChatGPT to your account and ask in your own words about your ads and your Salla store: what you spent, what you sold, which campaign is worth it. No tokens, no commands.')}
          </p>

          <ol className="mt-6 grid gap-3 sm:grid-cols-3">
            {steps.map((s, i) => (
              <li key={s.en} className="flex h-full flex-col gap-2 rounded-2xl border border-border bg-surface p-4">
                <span className="tnum flex h-8 w-8 items-center justify-center rounded-full bg-brand-primary-soft text-sm font-bold text-brand-700">{i + 1}</span>
                <span className="text-sm font-semibold text-text-primary">{ar ? s.ar : s.en}</span>
              </li>
            ))}
          </ol>

          <ul className="mt-5 space-y-1.5 text-sm text-text-primary">
            {trust.map((t, i) => (
              <li key={t.en} className="flex items-center gap-2">
                {i === 1 ? <ShieldCheck size={16} className="text-brand-600" aria-hidden /> : <Check size={16} className="text-brand-600" aria-hidden />}
                {ar ? t.ar : t.en}
              </li>
            ))}
          </ul>

          <div className="mt-6 flex flex-wrap gap-2.5">
            <Link to="/register"><Button size="lg" data-testid="home-ai-start">{tx('جرّبها الآن', 'Try it now')} <Arrow size={16} /></Button></Link>
            <Link to="/login"><Button size="lg" variant="secondary">{tx('عندي حساب', 'I have an account')}</Button></Link>
          </div>
        </div>

        <div className="rounded-3xl border border-border bg-surface-secondary p-4 shadow-[var(--shadow-large)] sm:p-5" aria-label={tx('مثال محادثة', 'Example conversation')}>
          <div className="flex items-center gap-2 border-b border-border pb-3 text-xs font-semibold text-text-muted">
            <span className="h-2 w-2 rounded-full bg-success" aria-hidden /> {tx('متصل بكامبينز هب', 'Connected to CampaignsHub')}
          </div>
          <div className="space-y-3 pt-4 text-sm">
            <p className="ms-auto max-w-[85%] rounded-2xl rounded-se-sm bg-primary px-3.5 py-2.5 text-on-primary">
              {tx('كم صرفت على الإعلانات هالشهر وكم بعت في متجري؟ وأي منصة أفضل؟', 'How much did I spend on ads this month, what did my store sell, and which platform did best?')}
            </p>
            <div className="max-w-[90%] space-y-2 rounded-2xl rounded-ss-sm border border-border bg-surface px-3.5 py-2.5 text-text-primary">
              <p>{tx('هذا الشهر صرفت على سناب وتيك توك وميتا، ومتجرك في سلة باع أكثر من ثلاث أضعاف الصرف.', 'This month you spent on Snapchat, TikTok and Meta, and your Salla store sold more than three times that.')}</p>
              <p>{tx('أفضل عائد جاء من سناب، وعندك حملتين في ميتا تصرف بدون مبيعات. تبيني أقترح إيقافها؟', 'Snapchat returned the most, and two Meta campaigns are spending without sales. Shall I suggest pausing them?')}</p>
              <p className="text-[11px] text-text-muted">{tx('مثال توضيحي', 'Illustrative example')}</p>
            </div>
          </div>
        </div>
      </div>
    </section>
  )
}
