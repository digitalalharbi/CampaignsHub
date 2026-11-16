import { DetailPanel } from '@/components/fit/DetailPanel'
import { AgencyFit, FitPanel, useMeasuredPage } from '@/components/fit/AgencyFit'
import { FitPaged } from '@/components/fit/FitPaged'
import { Pager } from '@/components/fit/Pager'
import { OpsHubBar } from '@/features/ops/OpsHubBar'
import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useLocation } from 'react-router-dom'
import { FlaskConical, Pencil, Play, Plus, ShieldCheck, Trash2 } from 'lucide-react'
import {
  deleteRule, dryRunRule, getCatalog, getSummary, listActions, listChanges, listRules, runRuleNow, toggleRule,
  type AutomationRule, type DryRunResult,
} from './api'
import { RuleBuilder } from './RuleBuilder'
import { LEVEL_WORDS, MODE_WORDS, RESULT_WORDS, STATUS_WORDS, actionWord, conditionsSentence, readingText, statusWord } from './ruleCopy'
import { Badge } from '@/components/ui/Badge'
import { Num } from '@/components/ui/Num'
import { Button } from '@/components/ui/Button'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { PageIntro } from '@/components/ui/PageIntro'
import { ProjectChooser } from '@/features/projects/ProjectChooser'
import { toApiError } from '@/lib/api/client'
import { platformLabel } from '@/lib/platforms'
import { days } from '@/lib/counted'
import { usePortalPath } from '@/app/portalPath'
import { useAuth } from '@/stores/auth'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { confirmDialog } from '@/stores/dialogs'

type Tab = 'rules' | 'history' | 'changes'

/**
 * AUTO-RULES-DASHBOARD-002 — Automated Rules, the operating surface (TikTok Ads Manager's model, across
 * every platform this project reads): what the rules did, the rules themselves with their last result,
 * every action with its before and after values, and the change log. Chat is not here on purpose: the
 * Agent Center helps write rules; this is where they live.
 */
export function AutomationPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const { currentProjectId } = useProject()
  const canManage = useAuth((s) => s.hasPermission('campaigns.view'))
  const portalTo = usePortalPath()
  const qc = useQueryClient()
  const [tab, setTab] = useState<Tab>('rules')
  /* AF2 — under /agency the page fits the screen: tables page themselves and a dry run opens in a layer. */
  const fit = useLocation().pathname.startsWith('/agency')
  const [editing, setEditing] = useState<AutomationRule | null | 'new'>(null)
  const [filter, setFilter] = useState<'all' | 'on' | 'off'>('all')
  const [q, setQ] = useState('')
  const [dry, setDry] = useState<{ rule: AutomationRule; results: DryRunResult[] } | null>(null)

  const p = currentProjectId
  const catalog = useQuery({ queryKey: ['automation', 'catalog', p], queryFn: () => getCatalog(p!), enabled: Boolean(p), retry: false })
  const summary = useQuery({ queryKey: ['automation', 'summary', p], queryFn: () => getSummary(p!), enabled: Boolean(p), retry: false })
  const rules = useQuery({ queryKey: ['automation', 'rules', p], queryFn: () => listRules(p!), enabled: Boolean(p), retry: false })
  const history = useQuery({ queryKey: ['automation', 'history', p], queryFn: () => listActions(p!), enabled: Boolean(p) && tab === 'history', retry: false })
  const changes = useQuery({ queryKey: ['automation', 'changes', p], queryFn: () => listChanges(p!), enabled: Boolean(p) && tab === 'changes', retry: false })
  const refresh = () => void qc.invalidateQueries({ queryKey: ['automation'] })
  const toggle = useMutation({ mutationFn: ({ id, on }: { id: string; on: boolean }) => toggleRule(p!, id, on), onSuccess: refresh })
  const remove = useMutation({ mutationFn: (id: string) => deleteRule(p!, id), onSuccess: refresh })
  const run = useMutation({ meta: { feedback: 'none' }, mutationFn: (id: string) => runRuleNow(p!, id), onSuccess: refresh })
  const dryRun = useMutation({ meta: { feedback: 'none' }, mutationFn: (r: AutomationRule) => dryRunRule(p!, r.id).then((d) => ({ rule: r, results: d.results })), onSuccess: setDry })

  const title = ar ? 'القواعد الآلية' : 'Automated rules'
  const purpose = ar
    ? 'قواعد تراقب الحملات على كل المنصات وتطلب التغيير وفق الوضع الذي تختاره: مراقبة، أو توصية للاعتماد، أو تنفيذ تلقائي حيث منحت الإذن.'
    : 'Rules that watch campaigns on every platform and ask for changes in the mode you choose: monitor, recommend for approval, or auto-execute where you granted it.'

  if (!p) {
    return <div className="space-y-6"><PageIntro title={title} purpose={purpose} /><OpsHubBar /><ProjectChooser purpose="recommendations" /></div>
  }

  const s = summary.data
  const failure = [toggle, remove, run, dryRun].find((m) => m.isError)?.error
  const shown = (rules.data?.rules ?? []).filter((r) => (filter === 'all' || (filter === 'on') === r.enabled) && r.name.toLowerCase().includes(q.toLowerCase()))

  return (
    <AgencyFit className="space-y-4" testid="automation-page">
      <PageIntro title={title} purpose={purpose}
        badges={<Link to={portalTo('/agents/approvals')} className="text-xs font-semibold text-brand-700 hover:underline" data-testid="automation-approvals"><ShieldCheck size={13} className="me-1 inline" aria-hidden />{ar ? 'طلبات الموافقة' : 'Approvals'}</Link>} />
      <OpsHubBar />

      <section aria-label={ar ? 'ملخص القواعد' : 'Rules summary'} className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6 [&>*]:min-w-0" data-testid="automation-summary">
        {summary.isLoading && <Skeleton className="col-span-full h-20" />}
        {s && ([
          ['all', ar ? 'كل الإجراءات' : 'All actions', s.all],
          ['turn_on', ar ? 'تشغيل' : 'Turn on', s.turn_on],
          ['turn_off', ar ? 'إيقاف' : 'Turn off', s.turn_off],
          ['budget', ar ? 'تعديل الميزانية' : 'Adjust budget', s.budget],
          ['awaiting', ar ? 'بانتظار الموافقة' : 'Awaiting approval', s.awaiting_approval],
          ['idle', ar ? 'قواعد بلا نشاط 30 يومًا' : 'Rules idle for 30 days', s.rules_idle_30d],
        ] as const).map(([k, label, n]) => (
          <div key={k} className="rounded-xl border border-border bg-surface px-3 py-2.5" data-testid={`automation-card-${k}`}>
            <p className="truncate text-xs text-text-secondary" title={label}>{label}</p>
            <p className="tnum mt-1 text-xl font-bold text-text-primary">{n}</p>
          </div>
        ))}
      </section>
      {s && <p className="text-[11px] text-text-muted">{ar ? `آخر ${days(s.days, 'ar')} · فشل ${s.failed} · قواعد مفعّلة ${s.rules_enabled} · متوقفة ${s.rules_disabled}` : `Last ${days(s.days, 'en')} · failed ${s.failed} · rules on ${s.rules_enabled} · off ${s.rules_disabled}`}</p>}

      <div role="tablist" className="flex gap-1 border-b border-border" aria-label={ar ? 'الأقسام' : 'Sections'}>
        {([['rules', ar ? 'إدارة القواعد' : 'Manage rules'], ['history', ar ? 'سجل الإجراءات' : 'Action history'], ['changes', ar ? 'سجل التغييرات' : 'Change log']] as const).map(([k, label]) => (
          <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => setTab(k)} data-testid={`automation-tab-${k}`}
            className={`-mb-px border-b-2 px-3 py-2 text-sm font-semibold ${tab === k ? 'border-brand-600 text-brand-700' : 'border-transparent text-text-secondary hover:text-text-primary'}`}>{label}</button>
        ))}
      </div>

      {failure && <p role="alert" className="text-sm text-danger" data-testid="automation-error">{toApiError(failure).message}</p>}
      <FitPanel className="space-y-4">

      {tab === 'rules' && (
        <>
          <div className="flex flex-wrap items-center gap-2">
            {canManage && <Button size="sm" onClick={() => setEditing('new')} disabled={!catalog.data} data-testid="automation-new"><Plus size={14} /> {ar ? 'قاعدة جديدة' : 'Create new rule'}</Button>}
            <select value={filter} onChange={(e) => setFilter(e.target.value as typeof filter)} aria-label={ar ? 'تصفية' : 'Filter'} className="rounded-lg border border-border bg-surface px-2 py-1.5 text-sm" data-testid="automation-filter">
              <option value="all">{ar ? 'كل القواعد' : 'All rules'}</option>
              <option value="on">{ar ? 'المفعّلة' : 'On'}</option>
              <option value="off">{ar ? 'المتوقفة' : 'Off'}</option>
            </select>
            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder={ar ? 'ابحث باسم القاعدة' : 'Search by rule name'} aria-label={ar ? 'بحث' : 'Search'} className="min-w-0 flex-1 rounded-lg border border-border bg-surface px-3 py-1.5 text-sm" data-testid="automation-search" />
          </div>

          {rules.isLoading && <Skeleton className="h-40" />}
          {rules.data && shown.length === 0 && (
            <EmptyState title={ar ? 'لا قواعد بعد' : 'No rules yet'} description={ar ? 'أنشئ قاعدة مثل: إذا تجاوز الإنفاق 500 خلال 3 أيام والعائد أقل من 3، أوقف الحملة.' : 'Create one, e.g. if spend over 3 days is above 500 and ROAS is below 3, turn the campaign off.'} />
          )}
          {shown.length > 0 && (
            <div className="overflow-x-auto rounded-xl border border-border">
              <PagedTable
                className="w-full min-w-[860px] text-sm"
                testid="automation-rules"
                ar={ar}
                head={<thead className="bg-surface-secondary text-start text-xs text-text-secondary">
                  <tr>
                    <th className="px-3 py-2 text-start">{ar ? 'تشغيل' : 'On/off'}</th>
                    <th className="px-3 py-2 text-start">{ar ? 'القاعدة' : 'Rule'}</th>
                    <th className="px-3 py-2 text-start">{ar ? 'الشروط' : 'Conditions'}</th>
                    <th className="px-3 py-2 text-start">{ar ? 'الإجراء' : 'Action'}</th>
                    <th className="px-3 py-2 text-start">{ar ? 'الكائن' : 'Object'}</th>
                    <th className="px-3 py-2 text-start">{ar ? 'آخر نتيجة' : 'Last run result'}</th>
                    <th className="px-3 py-2"><span className="sr-only">{ar ? 'إجراءات' : 'Actions'}</span></th>
                  </tr>
                </thead>}
                rows={shown.map((r) => (
                    <tr key={r.id} className="border-t border-border align-top" data-testid={`automation-rule-${r.id}`}>
                      <td className="px-3 py-2.5">
                        <input type="checkbox" role="switch" aria-checked={r.enabled} checked={r.enabled} disabled={toggle.isPending} onChange={(e) => toggle.mutate({ id: r.id, on: e.target.checked })} aria-label={ar ? 'تشغيل القاعدة' : 'Rule on'} className="h-4 w-4 accent-brand-600" data-testid={`automation-toggle-${r.id}`} />
                      </td>
                      <td className="px-3 py-2.5">
                        <p className="font-semibold text-text-primary">{r.name}</p>
                        <span className="mt-0.5 flex flex-wrap gap-1">
                          <Badge tone={r.mode === 'auto' ? 'warning' : r.mode === 'recommend' ? 'info' : 'neutral'}>{ar ? MODE_WORDS[r.mode].ar : MODE_WORDS[r.mode].en}</Badge>
                          {/* AUTO-RULES-AGENTS-009 — an agent's suggestion, saved off until a person reviews it. */}
                          {r.origin === 'agent' && <span data-testid={`automation-agent-draft-${r.id}`}><Badge tone="info">{ar ? 'مسودة من وكيل' : 'Agent draft'}</Badge></span>}
                        </span>
                      </td>
                      <td className="max-w-xs px-3 py-2.5 text-text-secondary" data-testid={`automation-conditions-${r.id}`}>{conditionsSentence(catalog.data, r, ar)}</td>
                      <td className="px-3 py-2.5">{actionWord(catalog.data, r, ar)}</td>
                      <td className="px-3 py-2.5 text-text-secondary">{ar ? LEVEL_WORDS[r.level].ar : LEVEL_WORDS[r.level].en}{r.scope?.platforms?.length ? ` · ${r.scope.platforms.map((x) => platformLabel(x, ar)).join('، ')}` : ''}</td>
                      <td className="px-3 py-2.5 text-xs text-text-secondary" data-testid={`automation-result-${r.id}`}>
                        {r.last_result ? (ar ? RESULT_WORDS[r.last_result]?.ar : RESULT_WORDS[r.last_result]?.en) ?? r.last_result : (ar ? 'لم تُشغَّل بعد' : "Rule hasn't run yet")}
                        {r.last_run_at && <span className="block text-text-muted" dir="ltr">{new Date(r.last_run_at).toLocaleString('en-GB', { hour12: false })}</span>}
                      </td>
                      <td className="px-3 py-2.5">
                        <div className="flex gap-1">
                          <IconButton label={ar ? 'تجربة دون تنفيذ' : 'Dry run'} onClick={() => dryRun.mutate(r)} testid={`automation-dry-${r.id}`}><FlaskConical size={15} /></IconButton>
                          <IconButton label={ar ? 'تشغيل الآن' : 'Run now'} onClick={() => run.mutate(r.id)} testid={`automation-run-${r.id}`}><Play size={15} /></IconButton>
                          <IconButton label={ar ? 'تعديل' : 'Edit'} onClick={() => setEditing(r)} testid={`automation-edit-${r.id}`}><Pencil size={15} /></IconButton>
                          <IconButton label={ar ? 'حذف' : 'Delete'} onClick={async () => { if (await confirmDialog({ title: ar ? 'احذف القاعدة؟' : 'Delete this rule?', confirmLabel: ar ? 'احذف' : 'Delete', danger: true })) remove.mutate(r.id) }} testid={`automation-delete-${r.id}`}><Trash2 size={15} /></IconButton>
                        </div>
                      </td>
                    </tr>
                  ))}
              />
            </div>
          )}

          {dry && (fit ? (
            <DetailPanel open onClose={() => setDry(null)} title={dry.rule.name} closeLabel={ar ? 'إغلاق' : 'Close'} testid="automation-dry-panel">
            <section className="rounded-xl border border-border p-3" data-testid="automation-dry-results">
              <div className="mb-2 flex items-center justify-between gap-2">
                <h3 className="text-sm font-bold text-text-primary">{ar ? `تجربة «${dry.rule.name}» — لم يتغير شيء` : `Dry run of «${dry.rule.name}» — nothing changed`}</h3>
                <button type="button" className="text-xs text-text-secondary hover:underline" onClick={() => setDry(null)}>{ar ? 'إغلاق' : 'Close'}</button>
              </div>
              {dry.results.length === 0 ? <p className="text-xs text-text-muted">{ar ? 'لا كائنات في نطاق القاعدة.' : 'No objects in the rule scope.'}</p> : (
                <ul className="divide-y divide-border text-xs">
                  {dry.results.map((x) => (
                    <li key={`${x.provider}-${x.external_id}`} className="flex flex-wrap items-center justify-between gap-2 py-1.5" data-testid="automation-dry-row">
                      <span className="min-w-0 truncate font-semibold text-text-primary">{platformLabel(x.provider, ar)} · {x.name ?? x.external_id}</span>
                      {x.readings && x.readings.length > 0 ? (
                        <span className="flex flex-wrap gap-x-3 text-text-secondary" data-testid="automation-dry-readings">
                          {x.readings.map((r) => (
                            <span key={`${r.metric}-${r.window_days}`}>{ar ? r.name_ar : r.name_en}: <bdi dir={ar ? 'rtl' : 'ltr'} className="font-semibold text-text-primary">{readingText(r, ar)}</bdi></span>
                          ))}
                        </span>
                      ) : (
                        <span className="text-text-secondary" dir="ltr">{Object.entries(x.metrics).map(([k, v]) => `${k}: ${typeof v === 'number' ? Math.round(v * 100) / 100 : v ?? '—'}`).join(' · ')}</span>
                      )}
                      <Badge tone={x.matched ? 'warning' : 'neutral'}>{statusWord(x.status, ar)}</Badge>
                      {x.reason && <p className="basis-full text-text-muted" data-testid="automation-dry-reason">{x.reason.ar}</p>}
                    </li>
                  ))}
                </ul>
              )}
            </section>
            </DetailPanel>
          ) : (
            <section className="rounded-xl border border-border p-3" data-testid="automation-dry-results">
              <div className="mb-2 flex items-center justify-between gap-2">
                <h3 className="text-sm font-bold text-text-primary">{ar ? `تجربة «${dry.rule.name}» — لم يتغير شيء` : `Dry run of «${dry.rule.name}» — nothing changed`}</h3>
                <button type="button" className="text-xs text-text-secondary hover:underline" onClick={() => setDry(null)}>{ar ? 'إغلاق' : 'Close'}</button>
              </div>
              {dry.results.length === 0 ? <p className="text-xs text-text-muted">{ar ? 'لا كائنات في نطاق القاعدة.' : 'No objects in the rule scope.'}</p> : (
                <ul className="divide-y divide-border text-xs">
                  {dry.results.map((x) => (
                    <li key={`${x.provider}-${x.external_id}`} className="flex flex-wrap items-center justify-between gap-2 py-1.5" data-testid="automation-dry-row">
                      <span className="min-w-0 truncate font-semibold text-text-primary">{platformLabel(x.provider, ar)} · {x.name ?? x.external_id}</span>
                      {x.readings && x.readings.length > 0 ? (
                        <span className="flex flex-wrap gap-x-3 text-text-secondary" data-testid="automation-dry-readings">
                          {x.readings.map((r) => (
                            <span key={`${r.metric}-${r.window_days}`}>{ar ? r.name_ar : r.name_en}: <bdi dir={ar ? 'rtl' : 'ltr'} className="font-semibold text-text-primary">{readingText(r, ar)}</bdi></span>
                          ))}
                        </span>
                      ) : (
                        <span className="text-text-secondary" dir="ltr">{Object.entries(x.metrics).map(([k, v]) => `${k}: ${typeof v === 'number' ? Math.round(v * 100) / 100 : v ?? '—'}`).join(' · ')}</span>
                      )}
                      <Badge tone={x.matched ? 'warning' : 'neutral'}>{statusWord(x.status, ar)}</Badge>
                      {x.reason && <p className="basis-full text-text-muted" data-testid="automation-dry-reason">{x.reason.ar}</p>}
                    </li>
                  ))}
                </ul>
              )}
            </section>
          ))}
        </>
      )}

      {tab === 'history' && (
        <div className="overflow-x-auto rounded-xl border border-border" data-testid="automation-history">
          {history.isLoading && <Skeleton className="h-32" />}
          {history.data && history.data.actions.length === 0 && <p className="p-4 text-sm text-text-muted">{ar ? 'لم تتخذ القواعد أي إجراء بعد.' : 'The rules have not acted yet.'}</p>}
          {history.data && history.data.actions.length > 0 && (
            <PagedTable
              className="w-full min-w-[820px] text-xs"
              ar={ar}
              head={<thead className="bg-surface-secondary text-text-secondary"><tr>
                {[ar ? 'الوقت' : 'Time', ar ? 'القاعدة' : 'Rule', ar ? 'الكائن' : 'Object', ar ? 'الإجراء' : 'Action', ar ? 'قبل → بعد' : 'Before → after', ar ? 'النتيجة' : 'Result'].map((h) => <th key={h} className="px-3 py-2 text-start">{h}</th>)}
              </tr></thead>}
              rows={history.data.actions.map((a) => {
                  const w = STATUS_WORDS[a.status]
                  return (
                    <tr key={a.id} className="border-t border-border align-top" data-testid="automation-history-row">
                      <td className="px-3 py-2 text-text-muted"><Num>{a.at ? new Date(a.at).toLocaleString('en-GB', { hour12: false }) : '—'}</Num></td>
                      <td className="px-3 py-2">{a.rule ?? '—'}</td>
                      <td className="px-3 py-2">{platformLabel(a.provider, ar)} · {a.target}</td>
                      <td className="px-3 py-2">{actionWord(catalog.data, { action: { type: a.action } }, ar)}</td>
                      <td className="px-3 py-2 text-text-secondary"><Num>{`${a.before ? JSON.stringify(a.before) : '—'} → ${a.after ? JSON.stringify(a.after) : '—'}`}</Num></td>
                      <td className="px-3 py-2"><Badge tone={w?.tone ?? 'neutral'}>{statusWord(a.status, ar)}</Badge>{a.note && <span className="mt-0.5 block text-text-muted">{a.note}</span>}</td>
                    </tr>
                  )
                })}
            />
          )}
        </div>
      )}

      {tab === 'changes' && (
        <FitPaged
          items={changes.data?.changes ?? []}
          itemHeight={44}
          gap={0}
          rowKey={(c) => `${c.rule_id}-${c.at}`}
          ar={ar}
          testid="automation-changes-fit"
          render={(c) => (
            <div className="flex h-full flex-wrap items-center justify-between gap-2 overflow-hidden border-b border-border px-3 py-2 text-sm" data-testid="automation-change-row">
              <span className="font-semibold text-text-primary">{c.rule ?? c.rule_id}</span>
              <span className="text-text-secondary">{CHANGE_WORDS[c.action] ? (ar ? CHANGE_WORDS[c.action].ar : CHANGE_WORDS[c.action].en) : c.action}</span>
              <span className="text-xs text-text-muted" dir="ltr">{c.at ? new Date(c.at).toLocaleString('en-GB', { hour12: false }) : ''}</span>
            </div>
          )}
          fallback={() => (
            <ul className="divide-y divide-border rounded-xl border border-border text-sm" data-testid="automation-changes">
                      {changes.isLoading && <li className="p-3"><Skeleton className="h-16" /></li>}
                      {changes.data?.changes.length === 0 && <li className="p-4 text-text-muted">{ar ? 'لا تغييرات بعد.' : 'No changes yet.'}</li>}
                      {changes.data?.changes.map((c, i) => (
                        <li key={`${c.rule_id}-${c.at}-${i}`} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2" data-testid="automation-change-row">
                          <span className="font-semibold text-text-primary">{c.rule ?? c.rule_id}</span>
                          <span className="text-text-secondary">{CHANGE_WORDS[c.action] ? (ar ? CHANGE_WORDS[c.action].ar : CHANGE_WORDS[c.action].en) : c.action}</span>
                          <span className="text-xs text-text-muted" dir="ltr">{c.at ? new Date(c.at).toLocaleString('en-GB', { hour12: false }) : ''}</span>
                        </li>
                      ))}
                    </ul>
          )}
        />
      )}

      </FitPanel>
      {editing !== null && catalog.data && (
        <RuleBuilder projectId={p} catalog={catalog.data} rule={editing === 'new' ? null : editing} ar={ar} onClose={() => setEditing(null)} />
      )}
    </AgencyFit>
  )
}

const CHANGE_WORDS: Record<string, { ar: string; en: string }> = {
  'automation.rule.created': { ar: 'أُنشئت', en: 'Created' },
  'automation.rule.updated': { ar: 'عُدّلت', en: 'Edited' },
  'automation.rule.enabled': { ar: 'شُغّلت', en: 'Turned on' },
  'automation.rule.disabled': { ar: 'أُوقفت', en: 'Turned off' },
  'automation.rule.deleted': { ar: 'حُذفت', en: 'Deleted' },
}

function IconButton({ label, onClick, children, testid }: { label: string; onClick: () => void; children: React.ReactNode; testid: string }) {
  return <button type="button" title={label} aria-label={label} onClick={onClick} data-testid={testid} className="rounded p-1.5 text-text-secondary hover:bg-surface-hover hover:text-text-primary">{children}</button>
}

/**
 * AF2 — a plain table that pages itself to the screen on a fitted `/agency` page: as many rows as fit the panel, the
 * rest a page away. Everywhere else — a phone, a short window, `/app` — every row is drawn, as it always was.
 */
function PagedTable({ head, rows, className, testid, ar }: { head: React.ReactNode; rows: React.ReactNode[]; className: string; testid?: string; ar: boolean }) {
  const { ref, size } = useMeasuredPage(rows.length)
  const [page, setPage] = useState(1)
  const pages = size ? Math.max(1, Math.ceil(rows.length / size)) : 1
  const current = Math.min(page, pages)
  const shown = size ? rows.slice((current - 1) * size, current * size) : rows

  return (
    <div ref={ref}>
      <table className={className} data-testid={testid}>
        {head}
        <tbody>{shown}</tbody>
      </table>
      {size !== undefined && pages > 1 && <Pager page={current} pages={pages} onPage={setPage} total={rows.length} ar={ar} testid={testid ? `${testid}-pager` : undefined} />}
    </div>
  )
}
