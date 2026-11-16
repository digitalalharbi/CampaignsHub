import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bot, KeyRound } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { Switch } from '@/components/ui/Switch'
import { getData, putData, toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

interface ProviderRow { provider: 'anthropic' | 'openai' | 'gemini'; configured: boolean; key: string | null; enabled: boolean; updated_at: string | null }
interface PlanRow { code: string; name: string; name_ar: string; messages: number | null }
interface AiSettings {
  providers: ProviderRow[]
  active_provider: string | null
  allowance: { unit: 'message'; per: 'person_month'; plans: PlanRow[] }
  this_month: { messages: number; tokens: number }
}

const API = '/admin/settings/ai'
const NAMES: Record<ProviderRow['provider'], string> = { anthropic: 'Claude (Anthropic)', openai: 'ChatGPT (OpenAI)', gemini: 'Gemini (Google)' }

/**
 * AI-PLATFORM-KEY-001 — «الذكاء الاصطناعي» in the admin console: the platform's own key and each plan's monthly allowance per
 * person. The key is write-only: once saved only its last four characters come back, and the field never shows it again.
 */
export function AiKeyPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const tx = (a: string, e: string) => (ar ? a : e)
  const qc = useQueryClient()
  const q = useQuery({ queryKey: ['admin', 'ai'], queryFn: () => getData<AiSettings>(API) })
  const done = (data: AiSettings) => qc.setQueryData(['admin', 'ai'], data)

  if (q.isLoading) return <Skeleton className="h-64" />
  if (q.isError || !q.data) return <ErrorState error={q.error} onRetry={() => void q.refetch()} ar={ar} title={tx('الذكاء الاصطناعي', 'AI')} />

  const d = q.data

  return (
    <div className="space-y-3" data-testid="admin-ai">
      <header className="flex flex-wrap items-center gap-2">
        <h1 className="me-auto font-heading text-2xl font-bold text-text-primary">{tx('الذكاء الاصطناعي', 'AI')}</h1>
        <Badge tone={d.active_provider ? 'success' : 'warning'} data-testid="ai-active">
          {d.active_provider ? `${tx('المفعّل', 'Active')}: ${NAMES[d.active_provider as ProviderRow['provider']] ?? d.active_provider}` : tx('لا يوجد مفتاح مفعّل', 'No active key')}
        </Badge>
        <Badge tone="info" data-testid="ai-month">
          {tx('هذا الشهر', 'This month')}: <bdi dir="ltr" className="tnum">{d.this_month.messages.toLocaleString('en-US')}</bdi> {tx('رسالة', 'messages')}
        </Badge>
      </header>

      <section className="rounded-xl border border-border bg-surface p-3" data-testid="ai-keys">
        <h2 className="mb-2 flex items-center gap-1.5 text-sm font-bold text-text-primary"><KeyRound size={16} aria-hidden /> {tx('مفتاح المنصة', 'Platform key')}</h2>
        <p className="mb-3 text-xs text-text-secondary">
          {tx('يُستخدم لأي مساحة عمل ليس لها مفتاح خاص. يُحفظ مشفّرًا، ولا يظهر بعد الحفظ إلا آخر 4 أحرف.', 'Used by any workspace without its own key. Stored encrypted; only the last 4 characters show after saving.')}
        </p>
        <ul className="flex flex-col gap-2">
          {d.providers.map((p) => <KeyRow key={p.provider} row={p} ar={ar} onDone={done} />)}
        </ul>
      </section>

      <Allowance plans={d.allowance.plans} ar={ar} onDone={done} />
    </div>
  )
}

function KeyRow({ row, ar, onDone }: { row: ProviderRow; ar: boolean; onDone: (d: AiSettings) => void }) {
  const tx = (a: string, e: string) => (ar ? a : e)
  const [secret, setSecret] = useState('')
  const save = useMutation({
    mutationFn: (body: { secret?: string; enabled?: boolean }) => putData<AiSettings>(`${API}/${row.provider}`, body),
    onSuccess: (d) => { setSecret(''); onDone(d) },
  })
  const error = save.error ? toApiError(save.error) : null

  return (
    <li className="flex flex-wrap items-center gap-2 rounded-lg border border-border px-3 py-2" data-testid={`ai-key-${row.provider}`}>
      <span className="w-40 shrink-0 text-sm font-semibold text-text-primary">{NAMES[row.provider]}</span>
      <span className="w-24 shrink-0 text-xs text-text-muted" dir="ltr">{row.key ?? tx('غير محفوظ', 'Not stored')}</span>
      <input
        type="password"
        autoComplete="off"
        spellCheck={false}
        value={secret}
        onChange={(e) => setSecret(e.target.value)}
        placeholder={row.configured ? tx('استبدال المفتاح', 'Replace key') : tx('ألصق المفتاح', 'Paste the key')}
        aria-label={`${NAMES[row.provider]} — ${tx('المفتاح', 'key')}`}
        dir="ltr"
        className="min-w-0 flex-1 rounded-lg border border-border bg-surface-secondary px-2.5 py-1.5 text-sm"
        data-testid={`ai-key-input-${row.provider}`}
      />
      <Button size="sm" disabled={secret.trim().length < 20 || save.isPending} onClick={() => save.mutate({ secret: secret.trim() })} data-testid={`ai-key-save-${row.provider}`}>
        {tx('حفظ', 'Save')}
      </Button>
      <Switch
        checked={row.enabled}
        disabled={!row.configured || save.isPending}
        onCheckedChange={(on: boolean) => save.mutate({ enabled: on })}
        label={tx('مفعّل', 'Enabled')}
        testId={`ai-key-enabled-${row.provider}`}
      />
      {error && <span role="alert" className="w-full text-xs text-danger">{error.status === 422 ? tx('تحقّق من المفتاح ثم حاول مرة أخرى.', 'Check the key and try again.') : tx('تعذّر الحفظ.', 'Could not save.')}</span>}
    </li>
  )
}

function Allowance({ plans, ar, onDone }: { plans: PlanRow[]; ar: boolean; onDone: (d: AiSettings) => void }) {
  const tx = (a: string, e: string) => (ar ? a : e)
  const [draft, setDraft] = useState<Record<string, string>>({})
  useEffect(() => { setDraft(Object.fromEntries(plans.map((p) => [p.code, p.messages === null ? '' : String(p.messages)]))) }, [plans])
  const save = useMutation({
    mutationFn: () => putData<AiSettings>(`${API}/allowance`, {
      plans: Object.fromEntries(Object.entries(draft).map(([k, v]) => [k, v.trim() === '' ? null : Number(v)])),
    }),
    onSuccess: onDone,
  })

  return (
    <section className="rounded-xl border border-border bg-surface p-3" data-testid="ai-allowance">
      <h2 className="mb-2 flex items-center gap-1.5 text-sm font-bold text-text-primary"><Bot size={16} aria-hidden /> {tx('حد الاستخدام', 'Usage limit')}</h2>
      <p className="mb-3 text-xs text-text-secondary">
        {tx('عدد ردود المساعد الشهرية لكل مستخدم على مفتاح المنصة، حسب الباقة. اتركه فارغًا بدون حد.', 'Monthly assistant replies per person on the platform key, by plan. Leave empty for no limit.')}
      </p>
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        {plans.map((p) => (
          <label key={p.code} className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
            {ar ? p.name_ar : p.name}
            <input
              type="number"
              min={0}
              inputMode="numeric"
              value={draft[p.code] ?? ''}
              onChange={(e) => setDraft((s) => ({ ...s, [p.code]: e.target.value }))}
              dir="ltr"
              className="tnum rounded-lg border border-border bg-surface-secondary px-2.5 py-1.5 text-sm text-text-primary"
              data-testid={`ai-allowance-${p.code}`}
            />
          </label>
        ))}
      </div>
      <div className="mt-3 flex items-center gap-2">
        <Button size="sm" onClick={() => save.mutate()} disabled={save.isPending} data-testid="ai-allowance-save">{tx('حفظ الحدود', 'Save limits')}</Button>
        {save.isSuccess && <span className="text-xs text-success">{tx('تم الحفظ', 'Saved')}</span>}
        {save.isError && <span role="alert" className="text-xs text-danger">{tx('تعذّر الحفظ.', 'Could not save.')}</span>}
      </div>
    </section>
  )
}
