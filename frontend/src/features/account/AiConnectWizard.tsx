import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, ArrowRight, Check, Copy, ExternalLink, Loader2, MessageSquareText, Sparkles } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Num } from '@/components/ui/Num'
import { getData } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { ASSISTANTS, FIRST_ASKS, MCP_HOST, MCP_URL, type Assistant } from './aiConnect'

type Stage = 'app' | 'copy' | 'paste' | 'ask'

const STAGES: Stage[] = ['app', 'copy', 'paste', 'ask']

const LABELS: Record<Stage, { ar: string; en: string }> = {
  app: { ar: 'اختر المساعد', en: 'Pick the assistant' },
  copy: { ar: 'انسخ الرابط', en: 'Copy the link' },
  paste: { ar: 'الصقه واسمح', en: 'Paste and allow' },
  ask: { ar: 'ابدأ اسأل', en: 'Start asking' },
}

/**
 * AI-CONNECT-EASY-001 — «اربط مساعدك الذكي»: the same next-next journey as connecting a platform under Integrations.
 *
 * Four stages, one decision each: which assistant, copy one link, paste it there and press «سماح» on our page, then what to
 * ask. While the person is away in Claude or ChatGPT the stage watches their connections, and moves on by itself the moment
 * a new one appears — nobody has to come back and say «done».
 */
export function AiConnectWizard({ onClose, initial = null }: { onClose: () => void; initial?: Assistant | null }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const tx = (a: string, e: string) => (ar ? a : e)
  const [stage, setStage] = useState<Stage>(initial ? 'copy' : 'app')
  const [assistant, setAssistant] = useState<Assistant>(initial ?? 'claude')
  const [copied, setCopied] = useState<string | null>(null)
  const guide = ASSISTANTS.find((a) => a.key === assistant) ?? ASSISTANTS[0]

  // How many connections existed when the wizard opened; one more means the person pressed «سماح».
  const tokens = useQuery({
    queryKey: ['me', 'mcp-tokens'],
    queryFn: () => getData<{ tokens: { id: string }[] }>('/me/mcp-tokens'),
    refetchInterval: stage === 'paste' ? 4000 : false,
  })
  const baseline = useRef<number | null>(null)
  const count = tokens.data?.tokens.length ?? null
  if (baseline.current === null && count !== null) baseline.current = count
  const connected = stage === 'paste' && count !== null && baseline.current !== null && count > baseline.current

  useEffect(() => {
    if (connected) setStage('ask')
  }, [connected])

  const copy = async (value: string) => {
    try { await navigator.clipboard.writeText(value) } catch { /* the field stays selectable */ }
    setCopied(value)
  }

  const at = STAGES.indexOf(stage)
  const back = at > 0 ? () => setStage(STAGES[at - 1]) : null
  const Forward = ar ? ArrowLeft : ArrowRight
  const Backward = ar ? ArrowRight : ArrowLeft

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:p-8" role="dialog" aria-modal="true">
      <div className="w-full max-w-2xl rounded-2xl border border-border bg-surface shadow-2xl" data-testid="ai-wizard">
        <header className="flex flex-col gap-4 border-b border-border p-5">
          <div className="flex items-center gap-3">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-primary-soft text-brand-700"><Sparkles size={18} aria-hidden /></span>
            <p className="min-w-0 flex-1 truncate text-base font-bold text-text-primary">{tx('اربط مساعدك الذكي', 'Connect your AI assistant')}</p>
            <Button variant="ghost" onClick={onClose} data-testid="ai-wizard-close">{tx('إغلاق', 'Close')}</Button>
          </div>
          <ol className="flex flex-wrap items-center gap-x-2 gap-y-1.5" data-testid="ai-wizard-stepper">
            {STAGES.map((s, i) => (
              <li key={s} className="flex items-center gap-2">
                <span
                  data-state={i < at ? 'done' : i === at ? 'current' : 'todo'}
                  aria-current={i === at ? 'step' : undefined}
                  className={`inline-flex items-center gap-1.5 rounded-[var(--radius-pill)] px-2.5 py-1 text-xs font-semibold ${
                    i === at ? 'bg-primary text-on-primary' : i < at ? 'bg-surface-secondary text-text-primary' : 'bg-surface-secondary text-text-muted'
                  }`}
                >
                  <span className="inline-flex h-4 w-4 items-center justify-center" aria-hidden>{i < at ? <Check size={12} /> : <Num>{i + 1}</Num>}</span>
                  {ar ? LABELS[s].ar : LABELS[s].en}
                </span>
                {i < STAGES.length - 1 && <span className="text-text-muted" aria-hidden>·</span>}
              </li>
            ))}
          </ol>
        </header>

        <div className="space-y-4 p-5">
          {stage === 'app' && (
            <section className="space-y-3" data-testid="ai-wizard-app">
              <p className="text-sm text-text-secondary">{tx('وين تبي تسأل عن حملاتك ومتجرك؟', 'Where do you want to ask about your campaigns and store?')}</p>
              <div className="grid gap-3 sm:grid-cols-3">
                {ASSISTANTS.map((a) => (
                  <button
                    key={a.key}
                    type="button"
                    disabled={!a.ready}
                    onClick={() => { setAssistant(a.key); setStage('copy') }}
                    className="flex flex-col items-start gap-1 rounded-2xl border border-border bg-surface p-4 text-start transition hover:border-brand-400 hover:bg-surface-hover disabled:cursor-not-allowed disabled:opacity-60"
                    data-testid={`ai-wizard-pick-${a.key}`}
                  >
                    <span className="text-base font-bold text-text-primary" dir="ltr">{a.label}</span>
                    <span className="text-xs text-text-muted">{a.ready ? tx('جاهز للربط', 'Ready to connect') : tx('قريبًا', 'Coming soon')}</span>
                  </button>
                ))}
              </div>
              <p className="text-xs text-text-muted">{ASSISTANTS.find((a) => !a.ready)?.note?.[ar ? 'ar' : 'en']}</p>
            </section>
          )}

          {stage === 'copy' && (
            <section className="space-y-3" data-testid="ai-wizard-copy">
              <p className="text-sm text-text-secondary">{tx('هذا رابط كامبينز هب. انسخه، بتلصقه في الخطوة الجاية.', 'This is the CampaignsHub link. Copy it; you paste it in the next step.')}</p>
              <div className="flex items-center gap-2 rounded-2xl border-2 border-dashed border-brand-300 bg-brand-primary-soft/40 p-3">
                <code dir="ltr" className="min-w-0 flex-1 truncate text-center font-mono text-lg font-bold text-text-primary" data-testid="ai-wizard-url">{MCP_HOST}</code>
                <Button onClick={() => copy(MCP_URL)} data-testid="ai-wizard-copy-url">
                  {copied === MCP_URL ? <><Check size={16} aria-hidden /> {tx('تم النسخ', 'Copied')}</> : <><Copy size={16} aria-hidden /> {tx('نسخ', 'Copy')}</>}
                </Button>
              </div>
              <p className="text-xs text-text-muted">{tx('ما يحتاج رموز ولا أوامر: الرابط هذا كل اللي تحتاجه.', 'No tokens, no commands: this link is all you need.')}</p>
            </section>
          )}

          {stage === 'paste' && (
            <section className="space-y-3" data-testid="ai-wizard-paste">
              <ol className="space-y-2">
                {guide.steps.map((s, i) => (
                  <li key={s.en} className="flex gap-3 rounded-xl border border-border bg-surface-secondary p-3 text-sm text-text-primary">
                    <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-primary-soft text-xs font-bold text-brand-700"><Num>{i + 1}</Num></span>
                    <span>{ar ? s.ar : s.en}</span>
                  </li>
                ))}
              </ol>
              {guide.open && (
                <a href={guide.open} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 hover:underline" data-testid="ai-wizard-open">
                  <ExternalLink size={14} aria-hidden /> {tx('افتح', 'Open')} <span dir="ltr">{guide.label}</span>
                </a>
              )}
              {guide.note && <p className="text-xs text-text-muted">{ar ? guide.note.ar : guide.note.en}</p>}
              <p className="flex items-center gap-2 rounded-xl bg-surface-secondary p-3 text-xs text-text-secondary" data-testid="ai-wizard-waiting">
                <Loader2 size={14} className="animate-spin" aria-hidden />
                {tx('ننتظر ضغطك على «سماح»… ننقلك تلقائيًا أول ما يرتبط.', 'Waiting for your «Allow»… we move on as soon as it connects.')}
              </p>
            </section>
          )}

          {stage === 'ask' && (
            <section className="space-y-3" data-testid="ai-wizard-ask">
              <p className="flex items-center gap-2 text-sm font-semibold text-success"><Check size={16} aria-hidden /> {tx('تم الربط! اكتب أي سؤال في المحادثة، مثل:', 'Connected! Type any question in the chat, such as:')}</p>
              <ul className="space-y-2">
                {FIRST_ASKS.map((q) => {
                  const text = ar ? q.ar : q.en
                  return (
                    <li key={q.en}>
                      <button type="button" onClick={() => copy(text)} className="flex w-full items-center gap-2 rounded-xl border border-border bg-surface p-3 text-start text-sm text-text-primary hover:bg-surface-hover">
                        <MessageSquareText size={16} className="shrink-0 text-brand-600" aria-hidden />
                        <span className="min-w-0 flex-1">{text}</span>
                        {copied === text ? <Check size={14} className="text-success" aria-hidden /> : <Copy size={14} className="text-text-muted" aria-hidden />}
                      </button>
                    </li>
                  )
                })}
              </ul>
              <p className="text-xs text-text-muted">{tx('يقرأ بصلاحياتك أنت فقط، وأي تعديل يقترحه يوصلك في «الموافقات» تعتمده أو ترفضه.', 'It reads with your permissions only; any change it suggests waits for you in «Approvals».')}</p>
            </section>
          )}
        </div>

        <footer className="flex items-center justify-between gap-2 border-t border-border p-4">
          {back ? <Button variant="ghost" onClick={back} data-testid="ai-wizard-back"><Backward size={16} aria-hidden /> {tx('السابق', 'Back')}</Button> : <span />}
          {stage === 'copy' && <Button onClick={() => setStage('paste')} disabled={copied !== MCP_URL} data-testid="ai-wizard-next">{tx('التالي', 'Next')} <Forward size={16} aria-hidden /></Button>}
          {stage === 'paste' && <Button variant="secondary" onClick={() => setStage('ask')} data-testid="ai-wizard-done">{tx('ربطته', 'I connected it')} <Forward size={16} aria-hidden /></Button>}
          {stage === 'ask' && <Button onClick={onClose} data-testid="ai-wizard-finish">{tx('تم', 'Done')}</Button>}
        </footer>
      </div>
    </div>
  )
}
