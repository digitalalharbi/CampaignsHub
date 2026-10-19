import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2, X } from 'lucide-react'
import { getTargets, saveRule, type AutomationRule, type Catalog, type RuleCondition, type RuleInput, type RuleLevel, type RuleMode } from './api'
import { FREQ_WORDS, LEVEL_WORDS, MODE_WORDS, OP } from './ruleCopy'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'
import { platformLabel, sortPlatforms } from '@/lib/platforms'
import { days } from '@/lib/counted'

const EMPTY: RuleInput = {
  name: '', enabled: true, mode: 'recommend', level: 'campaign', logic: 'all', scope: {},
  conditions: [{ metric: 'spend', operator: 'gt', value: 500, window_days: 3 }, { metric: 'roas', operator: 'lt', value: 3, window_days: 3 }],
  action: { type: 'pause' }, schedule: { frequency: 'hourly', at: '09:00', timezone: 'Asia/Riyadh' }, cooldown_hours: 24, min_spend: null,
}

const input = 'w-full rounded-lg border border-border bg-surface px-2.5 py-1.5 text-sm text-text-primary focus-visible:outline-2 focus-visible:outline-brand-600'

/**
 * AUTO-RULES-BUILDER-003 — the rule, built visually: object level, scope (platform → ad account → campaign →
 * ad group, each a multi-select narrowed by the one before), conditions joined by AND or OR, the action,
 * the mode, the schedule and the safeguards. The project (and so the client) is the page's; a scope can
 * only name that project's own objects, and the server re-checks it.
 */
export function RuleBuilder({ projectId, catalog, rule, ar, onClose }: { projectId: string; catalog: Catalog; rule: AutomationRule | null; ar: boolean; onClose: () => void }) {
  const qc = useQueryClient()
  const [f, setF] = useState<RuleInput>(() => (rule ? { ...EMPTY, ...rule, scope: rule.scope ?? {} } : EMPTY))
  const targets = useQuery({ queryKey: ['automation-targets', projectId], queryFn: () => getTargets(projectId), retry: false })
  const save = useMutation({
    mutationFn: () => saveRule(projectId, f, rule?.id),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['automation'] }); onClose() },
  })
  const set = <K extends keyof RuleInput>(k: K, v: RuleInput[K]) => setF((x) => ({ ...x, [k]: v }))
  const setScope = (k: keyof RuleInput['scope'], v: string[]) => setF((x) => ({ ...x, scope: { ...x.scope, [k]: v.length ? v : undefined } }))
  const setCond = (i: number, c: Partial<RuleCondition>) => setF((x) => ({ ...x, conditions: x.conditions.map((o, j) => (j === i ? { ...o, ...c } : o)) }))

  const t = targets.data
  const platforms = useMemo(() => sortPlatforms(Array.from(new Set((t?.accounts ?? []).map((a) => a.provider)))), [t])
  const accounts = (t?.accounts ?? []).filter((a) => !f.scope.platforms?.length || f.scope.platforms.includes(a.provider))
  const campaigns = (t?.campaigns ?? []).filter((c) => accounts.some((a) => a.id === c.account_id) && (!f.scope.account_ids?.length || f.scope.account_ids.includes(c.account_id)))
  const adSets = (t?.ad_sets ?? []).filter((s) => campaigns.some((c) => c.id === s.campaign_id) && (!f.scope.campaign_ids?.length || f.scope.campaign_ids.includes(s.campaign_id)))
  const isBudget = f.action.type === 'budget_increase' || f.action.type === 'budget_decrease'
  const statusMetric = (k: string) => catalog.metrics.find((m) => m.key === k)?.kind === 'status'

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/40" role="dialog" aria-modal="true" aria-label={ar ? 'قاعدة آلية' : 'Automated rule'} data-testid="rule-builder">
      <div className="flex h-full w-full max-w-2xl flex-col overflow-y-auto bg-surface p-4 sm:p-6">
        <div className="mb-4 flex items-center justify-between gap-2">
          <h2 className="text-lg font-bold text-text-primary">{rule ? (ar ? 'تعديل القاعدة' : 'Edit rule') : (ar ? 'قاعدة جديدة' : 'New rule')}</h2>
          <button type="button" onClick={onClose} aria-label={ar ? 'إغلاق' : 'Close'} className="rounded p-1 text-text-secondary hover:bg-surface-hover"><X size={18} /></button>
        </div>

        <form className="space-y-5" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
          <Field label={ar ? 'اسم القاعدة' : 'Rule name'}>
            <input className={input} value={f.name} onChange={(e) => set('name', e.target.value)} required maxLength={160} data-testid="rule-name" />
          </Field>

          <Section title={ar ? 'على ماذا تُطبَّق' : 'Applies to'}>
            <div className="flex flex-wrap gap-2" role="radiogroup">
              {(['campaign', 'ad_set', 'ad'] as RuleLevel[]).map((l) => (
                <Chip key={l} on={f.level === l} onClick={() => set('level', l)} testid={`rule-level-${l}`}>{ar ? LEVEL_WORDS[l].ar : LEVEL_WORDS[l].en}</Chip>
              ))}
            </div>
            <Pick label={ar ? 'المنصات' : 'Platforms'} all={ar ? 'كل المنصات' : 'All platforms'} options={platforms.map((p) => ({ id: p, name: platformLabel(p, ar) }))} value={f.scope.platforms ?? []} onChange={(v) => setScope('platforms', v)} testid="rule-platforms" />
            <Pick label={ar ? 'الحسابات الإعلانية' : 'Ad accounts'} all={ar ? 'كل الحسابات' : 'All accounts'} options={accounts.map((a) => ({ id: a.id, name: `${platformLabel(a.provider, ar)} · ${a.name}` }))} value={f.scope.account_ids ?? []} onChange={(v) => setScope('account_ids', v)} testid="rule-accounts" />
            <Pick label={ar ? 'الحملات' : 'Campaigns'} all={ar ? 'كل الحملات' : 'All campaigns'} options={campaigns.map((c) => ({ id: c.id, name: `${platformLabel(c.provider, ar)} · ${c.name ?? c.id}` }))} value={f.scope.campaign_ids ?? []} onChange={(v) => setScope('campaign_ids', v)} testid="rule-campaigns" />
            {f.level !== 'campaign' && (
              <Pick label={ar ? 'المجموعات الإعلانية' : 'Ad groups'} all={ar ? 'كل المجموعات' : 'All ad groups'} options={adSets.map((s) => ({ id: s.id, name: s.name ?? s.id }))} value={f.scope.ad_set_ids ?? []} onChange={(v) => setScope('ad_set_ids', v)} testid="rule-adsets" />
            )}
          </Section>

          <Section title={ar ? 'الشروط' : 'Conditions'}>
            <div className="flex items-center gap-2 text-xs text-text-secondary">
              {ar ? 'تتحقق القاعدة عند' : 'The rule matches when'}
              <Chip on={f.logic === 'all'} onClick={() => set('logic', 'all')} testid="rule-logic-all">{ar ? 'كل الشروط (و)' : 'all (AND)'}</Chip>
              <Chip on={f.logic === 'any'} onClick={() => set('logic', 'any')} testid="rule-logic-any">{ar ? 'أي شرط (أو)' : 'any (OR)'}</Chip>
            </div>
            <ul className="space-y-2">
              {f.conditions.map((c, i) => (
                <li key={i} className="grid grid-cols-2 gap-2 rounded-lg border border-border p-2 sm:grid-cols-[minmax(0,2fr)_auto_minmax(0,1fr)_auto_auto]" data-testid={`rule-condition-${i}`}>
                  <select className={input} value={c.metric} onChange={(e) => setCond(i, { metric: e.target.value, ...(statusMetric(e.target.value) ? { operator: 'eq', value: 'active' } : {}) })} aria-label={ar ? 'المقياس' : 'Metric'} data-testid={`rule-condition-${i}-metric`}>
                    {catalog.metrics.map((m) => <option key={m.key} value={m.key}>{ar ? m.ar : m.en}</option>)}
                  </select>
                  <select className={input} value={c.operator} onChange={(e) => setCond(i, { operator: e.target.value as RuleCondition['operator'] })} aria-label={ar ? 'المقارنة' : 'Comparison'} disabled={statusMetric(c.metric)}>
                    {catalog.operators.map((o) => <option key={o} value={o}>{OP[o]}</option>)}
                  </select>
                  {statusMetric(c.metric) ? (
                    <select className={input} value={String(c.value)} onChange={(e) => setCond(i, { value: e.target.value })} aria-label={ar ? 'القيمة' : 'Value'}>
                      <option value="active">{ar ? 'نشطة' : 'Active'}</option>
                      <option value="paused">{ar ? 'متوقفة' : 'Paused'}</option>
                    </select>
                  ) : (
                    <input className={input} type="number" step="any" dir="ltr" value={c.value} onChange={(e) => setCond(i, { value: e.target.value === '' ? '' : Number(e.target.value) })} aria-label={ar ? 'القيمة' : 'Value'} data-testid={`rule-condition-${i}-value`} />
                  )}
                  <select className={input} value={c.window_days} onChange={(e) => setCond(i, { window_days: Number(e.target.value) })} aria-label={ar ? 'الفترة' : 'Window'}>
                    {catalog.windows.map((w) => <option key={w} value={w}>{ar ? `آخر ${days(w, 'ar')}` : `last ${days(w, 'en')}`}</option>)}
                  </select>
                  <button type="button" disabled={f.conditions.length === 1} onClick={() => setF((x) => ({ ...x, conditions: x.conditions.filter((_, j) => j !== i) }))} aria-label={ar ? 'حذف الشرط' : 'Remove condition'} className="justify-self-end rounded p-1 text-text-muted hover:text-danger disabled:opacity-40"><Trash2 size={15} /></button>
                </li>
              ))}
            </ul>
            <button type="button" disabled={f.conditions.length >= 10} onClick={() => setF((x) => ({ ...x, conditions: [...x.conditions, { metric: 'cpa', operator: 'gt', value: 50, window_days: 7 }] }))} className="inline-flex items-center gap-1 text-xs font-semibold text-brand-700 hover:underline" data-testid="rule-add-condition">
              <Plus size={13} /> {ar ? 'إضافة شرط' : 'Add condition'}
            </button>
            <Field label={ar ? 'لا يُحكم قبل إنفاق (كفاية البيانات)' : 'Judge only after spend of (data sufficiency)'}>
              <input className={input} type="number" min={0} step="any" dir="ltr" value={f.min_spend ?? ''} onChange={(e) => set('min_spend', e.target.value === '' ? null : Number(e.target.value))} placeholder={ar ? 'اختياري' : 'Optional'} data-testid="rule-min-spend" />
            </Field>
          </Section>

          <Section title={ar ? 'الإجراء' : 'Action'}>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
              <select className={input} value={f.action.type} onChange={(e) => set('action', { type: e.target.value, percent: e.target.value.startsWith('budget') ? (f.action.percent ?? 10) : null })} aria-label={ar ? 'الإجراء' : 'Action'} data-testid="rule-action">
                {catalog.actions.map((a) => <option key={a.key} value={a.key}>{(ar ? a.ar : a.en) + (a.executable ? '' : ar ? ' (غير قابل للتنفيذ بعد)' : ' (not executable yet)')}</option>)}
              </select>
              {isBudget && (
                <label className="flex items-center gap-1 text-sm">
                  <input className={input} type="number" min={1} max={50} dir="ltr" value={f.action.percent ?? 10} onChange={(e) => set('action', { ...f.action, percent: Number(e.target.value) })} aria-label={ar ? 'النسبة' : 'Percent'} data-testid="rule-action-percent" />%
                </label>
              )}
            </div>
            <div className="grid gap-2" role="radiogroup" aria-label={ar ? 'وضع التشغيل' : 'Operating mode'}>
              {(['monitor', 'recommend', 'auto'] as RuleMode[]).map((m) => (
                <label key={m} className={`flex cursor-pointer items-start gap-2 rounded-lg border p-2 text-sm ${f.mode === m ? 'border-brand-600 bg-brand-50/40' : 'border-border'}`}>
                  <input type="radio" name="mode" checked={f.mode === m} onChange={() => set('mode', m)} className="mt-1 accent-brand-600" data-testid={`rule-mode-${m}`} />
                  <span><span className="font-semibold text-text-primary">{ar ? MODE_WORDS[m].ar : MODE_WORDS[m].en}</span><span className="block text-xs text-text-secondary">{ar ? MODE_WORDS[m].hint_ar : MODE_WORDS[m].hint_en}</span></span>
                </label>
              ))}
            </div>
          </Section>

          <Section title={ar ? 'الجدولة والضمانات' : 'Schedule and safeguards'}>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
              <Field label={ar ? 'التكرار' : 'Frequency'}>
                <select className={input} value={f.schedule.frequency} onChange={(e) => set('schedule', { ...f.schedule, frequency: e.target.value })} data-testid="rule-frequency">
                  {catalog.frequencies.map((k) => <option key={k} value={k}>{FREQ_WORDS[k] ? (ar ? FREQ_WORDS[k].ar : FREQ_WORDS[k].en) : k}</option>)}
                </select>
              </Field>
              {f.schedule.frequency === 'daily' && (
                <Field label={ar ? 'الوقت' : 'Time'}>
                  <input className={input} type="text" inputMode="numeric" pattern="\d{2}:\d{2}" dir="ltr" value={f.schedule.at ?? '09:00'} onChange={(e) => set('schedule', { ...f.schedule, at: e.target.value })} />
                </Field>
              )}
              <Field label={ar ? 'المنطقة الزمنية' : 'Time zone'}>
                <input className={input} dir="ltr" value={f.schedule.timezone ?? 'Asia/Riyadh'} onChange={(e) => set('schedule', { ...f.schedule, timezone: e.target.value })} />
              </Field>
              <Field label={ar ? 'فترة التهدئة (ساعات)' : 'Cooldown (hours)'}>
                <input className={input} type="number" min={1} max={720} dir="ltr" value={f.cooldown_hours} onChange={(e) => set('cooldown_hours', Number(e.target.value))} data-testid="rule-cooldown" />
              </Field>
            </div>
          </Section>

          {save.isError && <p role="alert" className="text-sm text-danger" data-testid="rule-save-error">{toApiError(save.error).message}</p>}
          <div className="flex justify-end gap-2 border-t border-border pt-3">
            <Button type="button" variant="secondary" onClick={onClose}>{ar ? 'إلغاء' : 'Cancel'}</Button>
            <Button type="submit" disabled={save.isPending || f.name.trim() === ''} data-testid="rule-save">{ar ? 'حفظ القاعدة' : 'Save rule'}</Button>
          </div>
        </form>
      </div>
    </div>
  )
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return <fieldset className="space-y-2.5"><legend className="mb-1 text-sm font-bold text-text-primary">{title}</legend>{children}</fieldset>
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <label className="block min-w-0 space-y-1"><span className="text-xs font-semibold text-text-secondary">{label}</span>{children}</label>
}

function Chip({ on, onClick, children, testid }: { on: boolean; onClick: () => void; children: React.ReactNode; testid?: string }) {
  return (
    <button type="button" role="radio" aria-checked={on} onClick={onClick} data-testid={testid}
      className={`rounded-full border px-3 py-1 text-xs font-semibold ${on ? 'border-brand-600 bg-brand-600 text-white' : 'border-border text-text-primary hover:border-brand-400'}`}>
      {children}
    </button>
  )
}

/** A multi-select as a short checklist: nothing ticked means «all», which is said rather than implied. */
function Pick({ label, all, options, value, onChange, testid }: { label: string; all: string; options: Array<{ id: string; name: string }>; value: string[]; onChange: (v: string[]) => void; testid: string }) {
  const [q, setQ] = useState('')
  const shown = options.filter((o) => o.name.toLowerCase().includes(q.toLowerCase())).slice(0, 200)
  return (
    <div className="space-y-1" data-testid={testid}>
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs font-semibold text-text-secondary">{label}</span>
        <span className="text-[11px] text-text-muted">{value.length === 0 ? all : `${value.length} / ${options.length}`}</span>
      </div>
      {options.length > 8 && <input className={input} value={q} onChange={(e) => setQ(e.target.value)} placeholder="…" aria-label={label} />}
      <ul className="max-h-36 space-y-0.5 overflow-y-auto rounded-lg border border-border p-1.5">
        {shown.length === 0 && <li className="px-1 text-xs text-text-muted">—</li>}
        {shown.map((o) => (
          <li key={o.id}>
            <label className="flex items-center gap-2 rounded px-1 py-0.5 text-sm hover:bg-surface-hover">
              <input type="checkbox" className="h-3.5 w-3.5 accent-brand-600" checked={value.includes(o.id)} onChange={(e) => onChange(e.target.checked ? [...value, o.id] : value.filter((x) => x !== o.id))} />
              <span className="min-w-0 truncate">{o.name}</span>
            </label>
          </li>
        ))}
      </ul>
    </div>
  )
}
