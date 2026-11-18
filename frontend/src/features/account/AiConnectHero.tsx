import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Check, ShieldCheck, Sparkles, Store } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { useUi } from '@/stores/ui'
import { ASSISTANTS, type Assistant } from './aiConnect'
import { AiConnectWizard } from './AiConnectWizard'

/**
 * AI-CONNECT-EASY-001 — the top of «ربط الذكاء الاصطناعي» for everyone who is not a developer.
 *
 * One promise (ask about your ads and your store in the chat you already use), three reasons to trust it, one button. The
 * button opens the next-next wizard; `?connect=1` (or `?connect=claude`) opens it straight away, which is where the promo
 * card and the marketing page send people.
 */
export function AiConnectHero() {
  const ar = useUi((s) => s.locale) === 'ar'
  const tx = (a: string, e: string) => (ar ? a : e)
  const [params, setParams] = useSearchParams()
  const asked = params.get('connect')
  const preset = ASSISTANTS.find((a) => a.key === asked && a.ready)?.key ?? null
  const [open, setOpen] = useState<boolean>(asked !== null)
  const [initial, setInitial] = useState<Assistant | null>(preset)

  const close = () => {
    setOpen(false)
    if (asked !== null) {
      params.delete('connect')
      setParams(params, { replace: true })
    }
  }

  return (
    <section className="overflow-hidden rounded-2xl border border-brand-200 bg-gradient-to-br from-brand-primary-soft via-surface to-surface p-5 sm:p-6" data-testid="ai-hero">
      <div className="flex flex-col gap-5 lg:flex-row lg:items-center">
        <div className="min-w-0 flex-1 space-y-3">
          <span className="inline-flex items-center gap-1.5 rounded-[var(--radius-pill)] bg-brand-primary-soft px-2.5 py-1 text-xs font-bold text-brand-700">
            <Sparkles size={14} aria-hidden /> {tx('بدون رموز ولا أوامر', 'No tokens, no commands')}
          </span>
          <p className="font-heading text-xl font-extrabold text-text-primary sm:text-2xl">{tx('اسأل عن إعلاناتك ومتجرك من الشات', 'Ask about your ads and your store from the chat')}</p>
          <p className="max-w-xl text-sm leading-relaxed text-text-secondary">
            {tx('اربط Claude أو ChatGPT بحسابك في دقيقة، واسأله بلهجتك: كم صرفت؟ كم بعت في سلة؟ أي حملة تستاهل؟ يجمع لك المنصات ومتجرك في محادثة وحدة.',
              'Connect Claude or ChatGPT to your account in a minute and ask in your own words: what did I spend, what did my Salla store sell, which campaign is worth it? Your platforms and your store, in one conversation.')}
          </p>
          <ul className="grid gap-1.5 text-sm text-text-primary sm:grid-cols-3">
            <li className="flex items-center gap-1.5"><Store size={15} className="text-brand-600" aria-hidden />{tx('المتجر + كل المنصات', 'Store + every platform')}</li>
            <li className="flex items-center gap-1.5"><ShieldCheck size={15} className="text-brand-600" aria-hidden />{tx('بصلاحياتك أنت فقط', 'Your permissions only')}</li>
            <li className="flex items-center gap-1.5"><Check size={15} className="text-brand-600" aria-hidden />{tx('ما يغيّر شي بدون موافقتك', 'Changes nothing without you')}</li>
          </ul>
        </div>
        <div className="flex shrink-0 flex-col gap-2 sm:flex-row lg:flex-col">
          {ASSISTANTS.filter((a) => a.ready).map((a, i) => (
            <Button
              key={a.key}
              size="lg"
              variant={i === 0 ? 'primary' : 'secondary'}
              onClick={() => { setInitial(a.key); setOpen(true) }}
              data-testid={`ai-hero-connect-${a.key}`}
            >
              {tx('اربط', 'Connect')} <span dir="ltr">{a.label}</span>
            </Button>
          ))}
        </div>
      </div>
      {open && <AiConnectWizard initial={initial} onClose={close} />}
    </section>
  )
}
