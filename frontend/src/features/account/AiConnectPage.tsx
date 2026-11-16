import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Copy, KeyRound, Plug, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { deleteData, getData, postData } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

interface McpToken { id: string; name: string; created_at: string | null; last_used_at: string | null }

type App = 'claude_code' | 'claude_desktop' | 'gemini' | 'openai'

const APPS: { key: App; label: string }[] = [
  { key: 'claude_code', label: 'Claude Code' },
  { key: 'claude_desktop', label: 'Claude Desktop' },
  { key: 'gemini', label: 'Gemini CLI' },
  { key: 'openai', label: 'ChatGPT / OpenAI API' },
]

/** The snippet for one app, with the server address and the person's token (or a placeholder) filled in. */
function snippet(app: App, url: string, token: string): string {
  switch (app) {
    case 'claude_code':
      return `claude mcp add --transport http campaignshub ${url} --header "Authorization: Bearer ${token}"`
    case 'claude_desktop':
      return JSON.stringify({ mcpServers: { campaignshub: { command: 'npx', args: ['-y', 'mcp-remote', url, '--header', `Authorization: Bearer ${token}`] } } }, null, 2)
    case 'gemini':
      return JSON.stringify({ mcpServers: { campaignshub: { httpUrl: url, headers: { Authorization: `Bearer ${token}` } } } }, null, 2)
    case 'openai':
      return JSON.stringify({ tools: [{ type: 'mcp', server_label: 'campaignshub', server_url: url, headers: { Authorization: `Bearer ${token}` }, require_approval: 'always' }] }, null, 2)
  }
}

const WHERE: Record<App, { ar: string; en: string }> = {
  claude_code: { ar: 'نفّذ الأمر في الطرفية (Terminal).', en: 'Run the command in your terminal.' },
  claude_desktop: { ar: 'أضفه في ملف claude_desktop_config.json من الإعدادات ← Developer ← Edit Config، ثم أعد تشغيل Claude. يحتاج Node.js.', en: 'Add it to claude_desktop_config.json (Settings → Developer → Edit Config), then restart Claude. Needs Node.js.' },
  gemini: { ar: 'أضفه في الملف ‎~/.gemini/settings.json ثم أعد تشغيل Gemini CLI.', en: 'Add it to ~/.gemini/settings.json, then restart Gemini CLI.' },
  openai: { ar: 'استخدمه ضمن أدوات طلب Responses في منصة OpenAI للمطورين. الإضافة كموصل داخل تطبيق ChatGPT نفسه تحتاج تسجيل دخول OAuth، وهي المرحلة القادمة.', en: 'Use it in the tools of a Responses request on the OpenAI platform. Adding it as a connector inside the ChatGPT app needs OAuth sign-in — the next phase.' },
}

const FIRST_READ_AR = 'استخدم CampaignsHub: اعرض مشاريعي، ثم لخّص أداء حملات المشروع الأول آخر 7 أيام: الإنفاق، النتائج، تكلفة النتيجة، وأفضل وأضعف حملة. لا تغيّر أي شيء.'
const FIRST_READ_EN = 'Use CampaignsHub: list my projects, then summarise the first project’s campaigns over the last 7 days: spend, results, cost per result, and the best and weakest campaign. Do not change anything.'

/**
 * MCP-SERVER-001 — «ربط الذكاء الاصطناعي»: connect Claude, Gemini or ChatGPT to CampaignsHub over MCP.
 *
 * The person creates a named token (shown ONCE), picks their app and copies a ready snippet. The connected assistant acts as
 * this person — their workspace, clients and permissions — reads freely, and can only PROPOSE a change for approval here.
 */
export function AiConnectPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const tx = (a: string, e: string) => (ar ? a : e)
  const qc = useQueryClient()
  const url = `${window.location.origin}/api/v1/mcp`
  const [app, setApp] = useState<App>('claude_code')
  const [name, setName] = useState('')
  const [fresh, setFresh] = useState<string | null>(null)

  const q = useQuery({ queryKey: ['me', 'mcp-tokens'], queryFn: () => getData<{ tokens: McpToken[] }>('/me/mcp-tokens') })
  const create = useMutation({
    mutationFn: () => postData<{ token: string; tokens: McpToken[] }>('/me/mcp-tokens', { name: name.trim() }),
    onSuccess: (d) => { setFresh(d.token); setName(''); qc.setQueryData(['me', 'mcp-tokens'], { tokens: d.tokens }) },
  })
  const revoke = useMutation({
    mutationFn: (id: string) => deleteData<{ tokens: McpToken[] }>(`/me/mcp-tokens/${id}`),
    onSuccess: (d) => qc.setQueryData(['me', 'mcp-tokens'], { tokens: d.tokens }),
  })
  const tokens = q.data?.tokens ?? []
  const placeholder = tx('ضع_الرمز_هنا', 'YOUR_TOKEN')

  return (
    <div className="space-y-4" data-testid="ai-connect">
      <header>
        <h1 className="flex items-center gap-2 font-heading text-2xl font-bold text-text-primary"><Plug size={22} aria-hidden /> {tx('ربط الذكاء الاصطناعي', 'Connect AI')}</h1>
        <p className="mt-1 text-sm text-text-secondary">
          {tx('اربط Claude أو Gemini أو ChatGPT بكامبينز هب، واسأله عن حملاتك مباشرة. يقرأ بصلاحياتك أنت فقط، ولا يغيّر أي حملة: أي تغيير يصلك هنا كاقتراح تعتمده أو ترفضه.',
            'Connect Claude, Gemini or ChatGPT to CampaignsHub and ask about your campaigns directly. It reads with your permissions only and never changes a campaign: any change arrives here as a proposal you approve or reject.')}
        </p>
      </header>

      <Step n={1} title={tx('رمز الوصول', 'Access token')}>
        <div className="flex flex-wrap items-center gap-2">
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder={tx('اسم الجهاز أو التطبيق، مثل: Claude المكتب', 'Device or app name, e.g. Office Claude')}
            className="min-w-0 flex-1 rounded-lg border border-border bg-surface-secondary px-2.5 py-1.5 text-sm"
            data-testid="mcp-token-name"
          />
          <Button size="sm" disabled={name.trim().length < 2 || create.isPending} onClick={() => create.mutate()} data-testid="mcp-token-create">
            <KeyRound className="h-4 w-4" aria-hidden /> {tx('إنشاء', 'Create')}
          </Button>
        </div>
        {create.isError && <p role="alert" className="mt-2 text-xs text-danger">{tx('تعذّر الإنشاء. الحد 10 رموز لكل مستخدم.', 'Could not create. The limit is 10 tokens per person.')}</p>}
        {fresh && (
          <div className="mt-3 rounded-lg border border-warning/50 bg-warning/5 p-2.5" data-testid="mcp-token-fresh">
            <p className="mb-1.5 text-xs font-semibold text-text-primary">{tx('انسخ الرمز الآن، لن يظهر مرة أخرى.', 'Copy the token now — it will not be shown again.')}</p>
            <CopyLine value={fresh} ar={ar} />
          </div>
        )}
        {tokens.length > 0 && (
          <ul className="mt-3 flex flex-col gap-1.5" data-testid="mcp-tokens">
            {tokens.map((t) => (
              <li key={t.id} className="flex items-center gap-2 rounded-lg border border-border px-2.5 py-1.5 text-xs">
                <span className="min-w-0 flex-1 truncate font-semibold text-text-primary">{t.name}</span>
                <span className="text-text-muted">{t.last_used_at ? `${tx('آخر استخدام', 'Last used')} ${t.last_used_at.slice(0, 10)}` : tx('لم يُستخدم بعد', 'Not used yet')}</span>
                <button type="button" onClick={() => revoke.mutate(t.id)} disabled={revoke.isPending} aria-label={tx('إلغاء الرمز', 'Revoke token')} title={tx('إلغاء الرمز', 'Revoke token')} className="rounded-md p-1 text-text-muted hover:bg-danger/10 hover:text-danger" data-testid={`mcp-token-revoke-${t.id}`}>
                  <Trash2 size={14} aria-hidden />
                </button>
              </li>
            ))}
          </ul>
        )}
      </Step>

      <Step n={2} title={tx('إعداد التطبيق', 'App setup')}>
        <p className="mb-2 text-xs text-text-secondary">{tx('عنوان الخادم', 'Server URL')}</p>
        <CopyLine value={url} ar={ar} />
        <div className="mt-3 flex flex-wrap gap-1.5" role="tablist">
          {APPS.map((a) => (
            <button
              key={a.key}
              type="button"
              role="tab"
              aria-selected={app === a.key}
              onClick={() => setApp(a.key)}
              className={`rounded-[var(--radius-pill)] border px-3 py-1 text-xs font-semibold ${app === a.key ? 'border-brand-600 bg-brand-600 text-white' : 'border-border text-text-secondary hover:bg-surface-hover'}`}
              data-testid={`mcp-app-${a.key}`}
            >
              {a.label}
            </button>
          ))}
        </div>
        <p className="mt-2 text-xs text-text-secondary">{ar ? WHERE[app].ar : WHERE[app].en}</p>
        <CopyBlock value={snippet(app, url, fresh ?? placeholder)} ar={ar} testid="mcp-snippet" />
      </Step>

      <Step n={3} title={tx('تحقق الربط', 'Check it works')}>
        <p className="mb-2 text-xs text-text-secondary">{tx('الصق هذا في التطبيق بعد الربط. النجاح: يذكر مشروعك وأرقامه وتاريخ الفترة.', 'Paste this into the app once connected. Success: it names your project, its figures and the dates.')}</p>
        <CopyBlock value={ar ? FIRST_READ_AR : FIRST_READ_EN} ar={ar} testid="mcp-first-read" />
      </Step>
    </div>
  )
}

function Step({ n, title, children }: { n: number; title: string; children: React.ReactNode }) {
  return (
    <section className="rounded-xl border border-border bg-surface p-3">
      <h2 className="mb-2 flex items-center gap-2 text-sm font-bold text-text-primary">
        <span className="tnum flex h-6 w-6 items-center justify-center rounded-full bg-brand-600 text-xs text-white">{n}</span>
        {title}
      </h2>
      {children}
    </section>
  )
}

function useCopy() {
  const [done, setDone] = useState(false)
  const copy = (v: string) => {
    void navigator.clipboard?.writeText(v).then(() => { setDone(true); window.setTimeout(() => setDone(false), 1500) }).catch(() => undefined)
  }
  return { done, copy }
}

function CopyLine({ value, ar }: { value: string; ar: boolean }) {
  const { done, copy } = useCopy()
  return (
    <div className="flex items-center gap-2 rounded-lg border border-border bg-surface-secondary px-2.5 py-1.5">
      <code dir="ltr" className="min-w-0 flex-1 truncate text-xs text-text-primary">{value}</code>
      <button type="button" onClick={() => copy(value)} aria-label={ar ? 'نسخ' : 'Copy'} className="shrink-0 rounded-md p-1 text-text-muted hover:text-text-primary">
        {done ? <Check size={14} aria-hidden /> : <Copy size={14} aria-hidden />}
      </button>
    </div>
  )
}

function CopyBlock({ value, ar, testid }: { value: string; ar: boolean; testid: string }) {
  const { done, copy } = useCopy()
  return (
    <div className="relative mt-2">
      <pre dir="ltr" className="max-h-64 overflow-auto whitespace-pre-wrap break-all rounded-lg border border-border bg-surface-secondary p-2.5 pe-9 text-xs text-text-primary" data-testid={testid}>{value}</pre>
      <button type="button" onClick={() => copy(value)} aria-label={ar ? 'نسخ' : 'Copy'} className="absolute end-1.5 top-1.5 rounded-md p-1 text-text-muted hover:text-text-primary">
        {done ? <Check size={14} aria-hidden /> : <Copy size={14} aria-hidden />}
      </button>
    </div>
  )
}
