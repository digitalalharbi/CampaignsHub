import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { FitPage } from '@/components/fit/FitPage'
import { KpiMini, KpiStrip } from '@/components/fit/KpiMini'
import { TabStrip } from '@/components/fit/TabStrip'
import { Pager } from '@/components/fit/Pager'
import { ResponsiveList } from '@/components/fit/ResponsiveList'
import { useFitRows } from '@/components/fit/useFitRows'
import { Button } from '@/components/ui/Button'
import { Switch } from '@/components/ui/Switch'
import { Skeleton } from '@/components/ui/States'
import { useAdminActions, useAdminNotifications, useAdminStats, type AdminData } from '@/features/notifications/workspace/adminApi'
import { useWords } from '@/features/notifications/workspace/strings'
import { Labelled, TabBody, TextBox, fieldCls } from '@/features/notifications/workspace/ui'
import { Activity, MailX, Send, UserMinus, MousePointerClick } from 'lucide-react'
import { PRIORITIES, type Priority } from '@/lib/notifications'

const TABS = ['catalog', 'templates', 'plans', 'footprint', 'stats'] as const
type Tab = (typeof TABS)[number]
const pct = (v: number | null) => (v === null ? '-' : `${(v * 100).toFixed(1)}%`)

/** `/admin/notifications` — the platform's layer: event catalog, templates and texts, plan features, footprint, statistics (spec §15). */
export function AdminNotificationsPage() {
  const { t, ar } = useWords()
  const data = useAdminNotifications()
  const [params, setParams] = useSearchParams()
  const asked = params.get('tab') as Tab | null
  const tab: Tab = asked && TABS.includes(asked) ? asked : 'catalog'
  const label: Record<Tab, string> = { catalog: t('aCatalog'), templates: t('aTemplates'), plans: t('aPlans'), footprint: t('aFootprint'), stats: t('aStats') }

  return (
    <FitPage testid="admin-notifications" title={t('aTitle')} info={t('aInfo')}
      toolbar={<TabStrip label={t('aTitle')} testid="admin-notif-tabs" active={tab} onChange={(k) => setParams({ tab: k }, { replace: true })} items={TABS.map((k) => ({ key: k, label: label[k] }))} />}>
      {data.isLoading && <Skeleton className="h-48" />}
      {data.data && tab === 'catalog' && <Catalog data={data.data} ar={ar} />}
      {data.data && tab === 'templates' && <Texts data={data.data} keys={['phrasing.dictionary', 'disclaimer.default', ...data.data.workspace_types.map((w) => `templates.${w.key}`)]} />}
      {data.data && tab === 'plans' && <Plans data={data.data} ar={ar} />}
      {data.data && tab === 'footprint' && <Footprint data={data.data} />}
      {tab === 'stats' && <Stats ar={ar} />}
    </FitPage>
  )
}

function Catalog({ data, ar }: { data: AdminData; ar: boolean }) {
  const { t } = useWords()
  const act = useAdminActions()
  const [page, setPage] = useState(1)
  const { ref, rows: perPage } = useFitRows({ min: 3, max: 30, fallback: 10 })
  const pages = Math.max(1, Math.ceil(data.catalog.length / perPage))
  const shown = data.catalog.slice((Math.min(page, pages) - 1) * perPage, Math.min(page, pages) * perPage)
  const pw = { info: t('pInfo'), important: t('pImportant'), critical: t('pCritical') } as Record<Priority, string>

  return (
    <div ref={ref as never} data-testid="admin-catalog" className="flex min-h-0 flex-1 flex-col gap-1">
      <div className="min-h-0 flex-1 overflow-y-auto md:overflow-hidden">
        <ResponsiveList testid="admin-events" rows={shown} rowKey={(e) => e.key}
          columns={[
            { key: 'e', header: t('colEvent'), cell: (e) => <span title={e.key}>{e.title[ar ? 'ar' : 'en']} <span dir="ltr" className="text-xs text-text-muted">{e.key}</span></span> },
            { key: 's', header: 'scope', width: '10%', cell: (e) => e.scope },
            { key: 'p', header: t('colPriority'), width: '16%', cell: (e) => (
              <select aria-label={t('colPriority')} className={`${fieldCls} h-8`} value={e.priority} onChange={(ev) => act.event.mutate({ key: e.key, body: { priority: ev.target.value as Priority } })}>
                {PRIORITIES.map((p) => <option key={p} value={p}>{pw[p]}</option>)}
              </select>) },
            { key: 'on', header: t('aEnabled'), width: '10%', align: 'center', cell: (e) => <Switch checked={e.enabled} onCheckedChange={(v) => act.event.mutate({ key: e.key, body: { enabled: v } })} /> },
          ]}
          renderCard={(e) => (
            <div className="flex items-center justify-between gap-2 rounded-xl border border-border bg-surface p-3"><div className="min-w-0"><div className="text-sm font-semibold">{e.title[ar ? 'ar' : 'en']}</div><div dir="ltr" className="truncate text-xs text-text-muted">{e.key} / {e.scope}</div></div>
              <Switch checked={e.enabled} onCheckedChange={(v) => act.event.mutate({ key: e.key, body: { enabled: v } })} /></div>
          )} />
      </div>
      <Pager page={Math.min(page, pages)} pages={pages} onPage={setPage} total={data.catalog.length} ar={ar} hideWhenSingle />
    </div>
  )
}

function Texts({ data, keys }: { data: AdminData; keys: string[] }) {
  const { t } = useWords()
  const act = useAdminActions()
  const [drafts, setDrafts] = useState<Record<string, string>>({})
  const current = (k: string) => drafts[k] ?? JSON.stringify(data.texts[k]?.value ?? {}, null, 2)

  return (
    <TabBody testid="admin-texts">
      <div className="grid gap-3 md:grid-cols-2">
        {keys.map((k) => {
          const type = k.startsWith('templates.') ? data.workspace_types.find((w) => `templates.${w.key}` === k) : null
          let valid = true
          try { JSON.parse(current(k)) } catch { valid = false }

          return (
            <section key={k} className="space-y-2 rounded-xl border border-border bg-surface p-3">
              <h3 className="text-sm font-bold">{k === 'phrasing.dictionary' ? t('aPhrasing') : k === 'disclaimer.default' ? t('aDefaultDisclaimer') : t('aTemplatesFor', { type: type?.key ?? k })}</h3>
              <textarea dir="ltr" rows={6} aria-label={k} data-testid={`text-${k}`} className={`${fieldCls} h-auto py-2 font-mono text-xs`} value={current(k)} onChange={(e) => setDrafts({ ...drafts, [k]: e.target.value })} />
              <Button size="sm" disabled={!valid} loading={act.text.isPending} data-testid={`save-${k}`} onClick={() => act.text.mutate({ key: k, value: JSON.parse(current(k)) })}>{t('save')}</Button>
            </section>
          )
        })}
      </div>
    </TabBody>
  )
}

function Plans({ data, ar }: { data: AdminData; ar: boolean }) {
  const { t } = useWords()
  const act = useAdminActions()
  const [plan, setPlan] = useState('')
  const [type, setType] = useState('')
  const rowFor = (key: string) => data.plan_features.find((f) => f.key === key && (f.plan_code ?? '') === plan && (f.workspace_type ?? '') === type)
  const limitKeys = Object.keys(data.limit_defaults)
  const save = (key: string, body: Record<string, unknown>) => act.planFeature.mutate({ key, plan_code: plan || null, workspace_type: type || null, ...body })

  return (
    <TabBody testid="admin-plans">
      <div className="space-y-3">
        <div className="grid gap-2 sm:grid-cols-2">
          <Labelled label={t('aPlan')}><select className={fieldCls} data-testid="plan-pick" value={plan} onChange={(e) => setPlan(e.target.value)}><option value="">{t('aAnyPlan')}</option>{data.plans.map((p) => <option key={p.code} value={p.code}>{ar ? (p.name_ar ?? p.name) : p.name}</option>)}</select></Labelled>
          <Labelled label={t('aType')}><select className={fieldCls} value={type} onChange={(e) => setType(e.target.value)}><option value="">{t('aAnyType')}</option>{data.workspace_types.map((w) => <option key={w.key} value={w.key}>{w.key}</option>)}</select></Labelled>
        </div>
        <ul className="grid gap-2 md:grid-cols-2">
          {data.entitlement_keys.map((k) => {
            const row = rowFor(k.key)
            const enabled = row ? row.enabled : k.default

            return (
              <li key={k.key} data-testid={`pf-${k.key}`} className="space-y-2 rounded-xl border border-border bg-surface p-3">
                <div className="flex items-center justify-between gap-2"><span dir="ltr" className="min-w-0 truncate text-sm font-semibold">{k.key}</span>
                  <Switch testId={`pf-on-${k.key}`} checked={enabled} onCheckedChange={(v) => save(k.key, { enabled: v, grace_days: row?.grace_days ?? data.grace_days_default, keep_existing: row?.keep_existing ?? true, limits: row?.limits ?? null })} /></div>
                {row && !row.enabled && (
                  <div className="flex flex-wrap items-end gap-2">
                    <div className="w-24"><Labelled label={t('aGraceDays')}><TextBox type="number" min={0} dir="ltr" defaultValue={row.grace_days} onBlur={(e) => save(k.key, { enabled: false, grace_days: Number(e.target.value), keep_existing: row.keep_existing, limits: row.limits })} /></Labelled></div>
                    <Switch checked={row.keep_existing} onCheckedChange={(v) => save(k.key, { enabled: false, grace_days: row.grace_days, keep_existing: v, limits: row.limits })} label={t('aKeepExisting')} />
                  </div>
                )}
                {limitKeys.filter((l) => limitOwner(l) === k.key).map((l) => (
                  <Labelled key={l} label={`${t('aLimits')}: ${l}`}>
                    <TextBox type="number" min={0} dir="ltr" placeholder={String(data.limit_defaults[l] ?? '')} defaultValue={row?.limits?.[l] ?? ''}
                      onBlur={(e) => save(k.key, { enabled, grace_days: row?.grace_days ?? data.grace_days_default, keep_existing: row?.keep_existing ?? true, limits: { ...(row?.limits ?? {}), [l]: e.target.value === '' ? null : Number(e.target.value) } })} />
                  </Labelled>
                ))}
              </li>
            )
          })}
        </ul>
      </div>
    </TabBody>
  )
}

/** Which key a limit belongs to (mirrors config notifications_v2.entitlements.limit_keys). */
function limitOwner(limit: string): string {
  return ({
    external_recipients: 'notifications.external', monthly_messages: 'notifications.external', cc_per_message: 'notifications.external.cc_bcc',
    devices_per_user: 'notifications.push', monthly_push: 'notifications.push', monthly_charts: 'notifications.external.charts',
  } as Record<string, string>)[limit] ?? ''
}

function Footprint({ data }: { data: AdminData }) {
  const { t } = useWords()
  const act = useAdminActions()
  const inv = (data.texts['footprint.invitation']?.value ?? {}) as { ar?: string; en?: string }
  const [line, setLine] = useState({ ar: inv.ar ?? '', en: inv.en ?? '' })
  const key = data.plan_features.find((f) => f.key === 'branding.remove_platform_footprint' && f.plan_code === null && f.workspace_type === null)
  const on = key ? key.enabled : false

  return (
    <TabBody testid="admin-footprint">
      <div className="max-w-2xl space-y-3">
        <section className="space-y-2 rounded-xl border border-border bg-surface p-3">
          <Switch testId="footprint-removal" checked={on} onCheckedChange={(v) => act.planFeature.mutate({ key: 'branding.remove_platform_footprint', plan_code: null, workspace_type: null, enabled: v, grace_days: key?.grace_days ?? data.grace_days_default, keep_existing: key?.keep_existing ?? true })} label={t('aFootprintKey')} />
        </section>
        <section className="space-y-2 rounded-xl border border-border bg-surface p-3">
          <Labelled label={`${t('aInvitation')} (AR)`}><TextBox data-testid="footprint-ar" value={line.ar} onChange={(e) => setLine({ ...line, ar: e.target.value })} /></Labelled>
          <Labelled label={`${t('aInvitation')} (EN)`}><TextBox dir="ltr" value={line.en} onChange={(e) => setLine({ ...line, en: e.target.value })} /></Labelled>
          <Button size="sm" loading={act.text.isPending} data-testid="footprint-save" onClick={() => act.text.mutate({ key: 'footprint.invitation', value: line })}>{t('save')}</Button>
        </section>
      </div>
    </TabBody>
  )
}

function Stats({ ar }: { ar: boolean }) {
  const { t } = useWords()
  const s = useAdminStats().data
  const [page, setPage] = useState(1)
  const { ref, rows: perPage } = useFitRows({ min: 3, max: 30, fallback: 8 })
  if (!s) return <Skeleton className="h-40" />
  const rows = s.per_workspace
  const pages = Math.max(1, Math.ceil(rows.length / perPage))
  const shown = rows.slice((Math.min(page, pages) - 1) * perPage, Math.min(page, pages) * perPage)

  return (
    <div data-testid="admin-stats" className="flex min-h-0 flex-1 flex-col gap-2">
      <KpiStrip cols={5}>
        <KpiMini icon={Send} tone="success" label={t('aSent')} value={s.messages_sent} />
        <KpiMini icon={MailX} tone="danger" label={t('aFailRate')} value={pct(s.failure_rate)} />
        <KpiMini icon={UserMinus} tone="neutral" label={t('aUnsubRate')} value={pct(s.unsubscribe_rate)} />
        <KpiMini icon={MousePointerClick} tone="info" label={t('aClicks')} value={s.footprint_clicks ?? '-'} />
        <KpiMini icon={Activity} tone="brand" label={t('uPeriod')} value={s.period} />
      </KpiStrip>
      <h3 className="text-sm font-bold">{t('aPerWorkspace')}</h3>
      <div ref={ref as never} className="flex min-h-0 flex-1 flex-col gap-1">
        <div className="min-h-0 flex-1 overflow-y-auto md:overflow-hidden">
          <ResponsiveList testid="admin-usage" rows={shown} rowKey={(r) => r.workspace} empty={<p className="p-6 text-center text-sm text-text-muted">{t('aNoData')}</p>}
            columns={[
              { key: 'w', header: t('aWorkspace'), cell: (r) => r.workspace },
              { key: 'p', header: t('aPlan'), width: '14%', cell: (r) => r.plan ?? '-' },
              { key: 'm', header: t('uMessages'), width: '14%', align: 'end', cell: (r) => <span className="tnum">{r.messages}</span> },
              { key: 'r', header: t('uRecipients'), width: '12%', align: 'end', cell: (r) => <span className="tnum">{r.recipients}</span> },
              { key: 'u', header: t('uPush'), width: '12%', align: 'end', cell: (r) => <span className="tnum">{r.push}</span> },
              { key: 'c', header: t('uCharts'), width: '12%', align: 'end', cell: (r) => <span className="tnum">{r.charts}</span> },
            ]}
            renderCard={(r) => <div className="rounded-xl border border-border bg-surface p-3 text-sm"><div className="font-semibold">{r.workspace}</div><div className="text-xs text-text-muted">{r.plan ?? '-'} / <span className="tnum">{r.messages}</span></div></div>} />
        </div>
        <Pager page={Math.min(page, pages)} pages={pages} onPage={setPage} total={rows.length} ar={ar} hideWhenSingle />
      </div>
    </div>
  )
}
