import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { BellRing, Clock, ListChecks, Users } from 'lucide-react'
import { TabStrip } from '@/components/fit/TabStrip'
import { Pager } from '@/components/fit/Pager'
import { ResponsiveList } from '@/components/fit/ResponsiveList'
import { useFitRows } from '@/components/fit/useFitRows'
import { FIT_ROW_HEIGHT } from '@/styles/scale'
import { Button } from '@/components/ui/Button'
import { Switch } from '@/components/ui/Switch'
import { Skeleton } from '@/components/ui/States'
import { usePortalPath } from '@/app/portalPath'
import { NotificationRecipients } from '@/features/settings/tabs/NotificationRecipients'
import { TeamNotifications } from '@/features/settings/tabs/TeamNotifications'
import { NotificationTemplates } from '@/features/settings/tabs/NotificationTemplates'
import { DeliveryLog } from '@/features/settings/tabs/DeliveryLog'
import type { Channel, Priority } from '@/lib/notifications'
import { useSaveWorkspaceSettings, type WorkspaceEvent, type WorkspaceSettingsData } from './api'
import type { WordKey } from './strings'
import { useTypeWords } from './typeWords'
import { Labelled, LockedNote, TabBody, TextBox, ToggleChip, useGate, fieldCls } from './ui'

type RouteEntry = { roles?: string[]; channels?: Channel[]; enabled?: boolean }
const PRIORITIES: Priority[] = ['info', 'important', 'critical']
const PRIORITY_WORD: Record<Priority, WordKey> = { info: 'pInfo', important: 'pImportant', critical: 'pCritical' }
const CHANNEL_WORD: Record<Channel, WordKey> = { in_app: 'chInApp', push: 'chPush', email: 'chEmail' }
const DAY_AR = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت']
const DAY_EN = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const ZONES = ['Asia/Riyadh', 'Asia/Dubai', 'Asia/Kuwait', 'Africa/Cairo', 'Europe/London', 'UTC']

export function InternalSection({ data }: { data: WorkspaceSettingsData }) {
  const type = data.workspace.type
  const { t } = useTypeWords(type)
  const [params, setParams] = useSearchParams()
  const tab = ['routing', 'delivery', 'followup', 'team'].includes(params.get('tab2') ?? '') ? (params.get('tab2') as string) : 'routing'
  const setTab = (k: string) => { const p = new URLSearchParams(params); p.set('tab2', k); setParams(p, { replace: true }) }

  return (
    <div data-testid="internal-section" className="flex min-h-0 flex-1 flex-col gap-2.5">
      <TabStrip
        label={t('internal')}
        testid="internal-tabs"
        active={tab}
        onChange={setTab}
        items={[
          { key: 'routing', label: t('tRouting'), icon: BellRing },
          { key: 'delivery', label: t('tDelivery'), icon: Clock },
          { key: 'followup', label: t('tFollowup'), icon: ListChecks },
          { key: 'team', label: t('tTeam'), icon: Users },
        ]}
      />
      {tab === 'routing' && <RoutingMatrix data={data} />}
      {tab === 'delivery' && <DeliveryPanel data={data} />}
      {tab === 'followup' && <FollowupPanel data={data} />}
      {tab === 'team' && (
        <TabBody testid="internal-team">
          <p className="mb-3 text-sm text-text-secondary">{t('teamHint')}</p>
          <div className="space-y-6">
            <NotificationRecipients />
            <TeamNotifications />
            <DeliveryLog />
            <NotificationTemplates />
          </div>
        </TabBody>
      )}
    </div>
  )
}

/** The routing matrix: event -> priority, roles, channels, on/off. Edits are local until «حفظ». */
function RoutingMatrix({ data }: { data: WorkspaceSettingsData }) {
  const type = data.workspace.type
  const { t, ar, lang } = useTypeWords(type)
  const save = useSaveWorkspaceSettings()
  const gate = useGate('notifications.internal')
  const events = useMemo(() => data.events.filter((e) => e.scope !== 'external'), [data.events])
  const savedPriorities = (data.settings.settings.priorities ?? {}) as Record<string, Priority>
  const [routing, setRouting] = useState<Record<string, RouteEntry>>(data.settings.routing)
  const [priorities, setPriorities] = useState<Record<string, Priority>>(savedPriorities)
  const [page, setPage] = useState(1)
  // Rows here hold wrapping chips, so they are taller than the standard 44px row: measure the real row height
  // and fit by that, or the bottom rows are cut off by the box.
  const [rowH, setRowH] = useState(FIT_ROW_HEIGHT)
  const { ref, rows: perPage } = useFitRows({ min: 3, max: 30, fallback: 8, rowHeight: rowH })
  const boxRef = useRef<HTMLDivElement | null>(null)
  const setBox = (el: HTMLDivElement | null) => { boxRef.current = el; (ref as (el: HTMLElement | null) => void)(el) }
  const dirty = JSON.stringify(routing) !== JSON.stringify(data.settings.routing) || JSON.stringify(priorities) !== JSON.stringify(savedPriorities)
  const pages = Math.max(1, Math.ceil(events.length / perPage))
  useEffect(() => { if (page > pages) setPage(pages) }, [page, pages])
  const shown = events.slice((page - 1) * perPage, page * perPage)
  useLayoutEffect(() => {
    const trs = boxRef.current?.querySelectorAll<HTMLElement>('tbody tr')
    if (!trs || trs.length === 0) return
    const tallest = Math.ceil(Math.max(...Array.from(trs, (tr) => tr.getBoundingClientRect().height)))
    const next = Math.max(FIT_ROW_HEIGHT, tallest)
    setRowH((cur) => (Math.abs(cur - next) <= 2 ? cur : next))
  })

  const entry = (e: WorkspaceEvent): Required<RouteEntry> => ({
    roles: routing[e.key]?.roles ?? e.route.roles,
    channels: routing[e.key]?.channels ?? e.route.channels,
    enabled: routing[e.key]?.enabled ?? e.route.enabled,
  })
  const patch = (e: WorkspaceEvent, next: Partial<RouteEntry>) => setRouting((r) => ({ ...r, [e.key]: { ...entry(e), ...next } }))
  const toggle = <T,>(list: T[], v: T): T[] => (list.includes(v) ? list.filter((x) => x !== v) : [...list, v])
  const title = (e: WorkspaceEvent) => (e.title.internal ?? e.title.external)?.[lang] ?? e.key
  const prio = (e: WorkspaceEvent): Priority => priorities[e.key] ?? e.priority

  const roleChips = (e: WorkspaceEvent) => (
    <div className="flex min-w-0 flex-wrap gap-1">
      {type.roles.map((r) => (
        <ToggleChip key={r.key} on={entry(e).roles.includes(r.key)} disabled={!gate.editable} testid={`role-${e.key}-${r.key}`} onClick={() => patch(e, { roles: toggle(entry(e).roles, r.key) })}>
          {type.role_labels[r.key]?.[lang] ?? r.key}
        </ToggleChip>
      ))}
    </div>
  )
  const channelChips = (e: WorkspaceEvent) => (
    <div className="flex flex-wrap gap-1">
      {e.channels.map((c) => (
        <ToggleChip key={c} on={entry(e).channels.includes(c)} disabled={!gate.editable} testid={`ch-${e.key}-${c}`} onClick={() => patch(e, { channels: toggle(entry(e).channels, c) })}>
          {t(CHANNEL_WORD[c])}
        </ToggleChip>
      ))}
    </div>
  )
  const prioButton = (e: WorkspaceEvent) => (
    <button
      type="button"
      disabled={!gate.editable}
      data-testid={`prio-${e.key}`}
      onClick={() => setPriorities((p) => ({ ...p, [e.key]: PRIORITIES[(PRIORITIES.indexOf(prio(e)) + 1) % PRIORITIES.length] }))}
      className="rounded-full border border-border px-2 py-0.5 text-xs font-semibold text-text-secondary hover:bg-surface-hover disabled:opacity-60"
    >
      {t(PRIORITY_WORD[prio(e)])}
    </button>
  )

  return (
    <div data-testid="routing-matrix" className="flex min-h-0 flex-1 flex-col gap-2">
      <LockedNote entitlement="notifications.internal" />
      <div className="flex flex-wrap items-center gap-2">
        <p className="min-w-0 flex-1 text-xs text-text-muted">{t('routingHint')}</p>
        <Button size="sm" disabled={!dirty || !gate.editable} loading={save.isPending} data-testid="routing-save" onClick={() => save.mutate({ routing, settings: { priorities } })}>
          {save.isSuccess && !dirty ? t('saved') : t('save')}
        </Button>
      </div>
      <div ref={setBox} className="flex min-h-0 flex-1 flex-col gap-1">
        <div className="min-h-0 flex-1 overflow-y-auto">
          <ResponsiveList
            testid="routing"
            rows={shown}
            rowKey={(e) => e.key}
            columns={[
              { key: 'event', header: t('colEvent'), cell: (e) => <span title={title(e)}>{title(e)}{routing[e.key] ? <span className="ms-1 text-xs text-brand-600">{t('customised')}</span> : null}</span> },
              { key: 'prio', header: t('colPriority'), width: '84px', cell: prioButton },
              { key: 'roles', header: t('colRoles'), width: '38%', cell: roleChips },
              { key: 'ch', header: t('colChannels'), width: '20%', cell: channelChips },
              { key: 'on', header: t('colOn'), width: '72px', align: 'center', cell: (e) => <Switch testId={`on-${e.key}`} checked={entry(e).enabled} disabled={!gate.editable} onCheckedChange={(v) => patch(e, { enabled: v })} /> },
            ]}
            renderCard={(e) => (
              <div className="space-y-2 rounded-xl border border-border bg-surface p-3">
                <div className="flex items-center justify-between gap-2">
                  <span className="min-w-0 text-sm font-semibold">{title(e)}</span>
                  <Switch checked={entry(e).enabled} disabled={!gate.editable} onCheckedChange={(v) => patch(e, { enabled: v })} />
                </div>
                {prioButton(e)}
                {roleChips(e)}
                {channelChips(e)}
              </div>
            )}
          />
        </div>
        <Pager page={page} pages={pages} onPage={setPage} total={events.length} ar={ar} testid="routing-pager" hideWhenSingle />
      </div>
    </div>
  )
}

function DeliveryPanel({ data }: { data: WorkspaceSettingsData }) {
  const type = data.workspace.type
  const { t, ar } = useTypeWords(type)
  const save = useSaveWorkspaceSettings()
  const gate = useGate('notifications.internal')
  const s = data.settings
  const [quiet, setQuiet] = useState(s.quiet_hours)
  const [days, setDays] = useState(s.work_days)
  const [zone, setZone] = useState(s.timezone)
  const [holidays, setHolidays] = useState(s.holidays)
  const [newHoliday, setNewHoliday] = useState('')
  const [sound, setSound] = useState(s.settings.default_sound !== false)
  const [grouping, setGrouping] = useState(Number(s.settings.grouping_window_minutes ?? 10))
  const names = ar ? DAY_AR : DAY_EN
  const zones = ZONES.includes(zone) ? ZONES : [zone, ...ZONES]

  return (
    <TabBody testid="delivery-panel">
      <div className="space-y-4">
        <LockedNote entitlement="notifications.internal" />
        <div className="grid gap-4 md:grid-cols-2">
          <section className="space-y-2 rounded-xl border border-border bg-surface p-3">
            <div className="flex items-center justify-between"><h3 className="text-sm font-bold">{t('quietHours')}</h3>
              <Switch testId="quiet-enabled" checked={quiet.enabled} disabled={!gate.editable} onCheckedChange={(v) => setQuiet({ ...quiet, enabled: v })} /></div>
            <p className="text-xs text-text-muted">{t('quietHint')}</p>
            <div className="grid grid-cols-2 gap-2">
              <Labelled label={t('from')}><TextBox type="time" dir="ltr" value={quiet.start} disabled={!gate.editable} onChange={(e) => setQuiet({ ...quiet, start: e.target.value })} /></Labelled>
              <Labelled label={t('to')}><TextBox type="time" dir="ltr" value={quiet.end} disabled={!gate.editable} onChange={(e) => setQuiet({ ...quiet, end: e.target.value })} /></Labelled>
            </div>
          </section>
          <section className="space-y-2 rounded-xl border border-border bg-surface p-3">
            <h3 className="text-sm font-bold">{t('workDays')}</h3>
            <div className="flex flex-wrap gap-1">
              {names.map((n, i) => <ToggleChip key={i} on={days.includes(i)} disabled={!gate.editable} testid={`day-${i}`} onClick={() => setDays(days.includes(i) ? days.filter((d) => d !== i) : [...days, i].sort())}>{n}</ToggleChip>)}
            </div>
            <Labelled label={t('timezone')}>
              <select className={fieldCls} value={zone} disabled={!gate.editable} onChange={(e) => setZone(e.target.value)}>
                {zones.map((z) => <option key={z} value={z}>{z}</option>)}
              </select>
            </Labelled>
          </section>
          <section className="space-y-2 rounded-xl border border-border bg-surface p-3">
            <h3 className="text-sm font-bold">{t('holidays')}</h3>
            <div className="flex flex-wrap gap-1">
              {holidays.map((h) => <ToggleChip key={h} on onClick={() => setHolidays(holidays.filter((x) => x !== h))} disabled={!gate.editable}><span dir="ltr">{h}</span></ToggleChip>)}
            </div>
            <div className="flex gap-2">
              <TextBox type="date" dir="ltr" value={newHoliday} disabled={!gate.editable} onChange={(e) => setNewHoliday(e.target.value)} />
              <Button size="sm" variant="secondary" disabled={!newHoliday || !gate.editable} onClick={() => { if (!holidays.includes(newHoliday)) setHolidays([...holidays, newHoliday].sort()); setNewHoliday('') }}>{t('addHoliday')}</Button>
            </div>
          </section>
          <section className="space-y-3 rounded-xl border border-border bg-surface p-3">
            <Switch testId="default-sound" checked={sound} disabled={!gate.editable} onCheckedChange={setSound} label={t('defaultSound')} />
            <Labelled label={t('grouping')}><TextBox type="number" min={1} max={120} dir="ltr" value={grouping} disabled={!gate.editable} onChange={(e) => setGrouping(Number(e.target.value))} /></Labelled>
          </section>
        </div>
        <Button data-testid="delivery-save" loading={save.isPending} disabled={!gate.editable} onClick={() => save.mutate({
          quiet_hours: quiet, work_days: days, timezone: zone, holidays,
          settings: { default_sound: sound, grouping_window_minutes: Math.max(1, Math.min(120, grouping || 10)) },
        })}>{save.isSuccess ? t('saved') : t('save')}</Button>
      </div>
    </TabBody>
  )
}

function FollowupPanel({ data }: { data: WorkspaceSettingsData }) {
  const type = data.workspace.type
  const { t } = useTypeWords(type)
  const to = usePortalPath()
  const save = useSaveWorkspaceSettings()
  const brief = useGate('followup.morning_brief')
  const tasks = useGate('followup.tasks')
  const f = (data.settings.settings.followup ?? {}) as { morning_brief?: boolean; morning_time?: string; tasks?: boolean }
  const [morning, setMorning] = useState(f.morning_brief !== false)
  const [time, setTime] = useState(f.morning_time ?? '08:00')
  const [auto, setAuto] = useState(f.tasks !== false)
  if (!data) return <Skeleton className="h-24" />

  return (
    <TabBody testid="followup-panel">
      <div className="space-y-3">
        <LockedNote entitlement="followup.morning_brief" />
        <LockedNote entitlement="followup.tasks" />
        <section className="space-y-3 rounded-xl border border-border bg-surface p-3">
          <Switch testId="followup-brief" checked={morning} disabled={!brief.editable} onCheckedChange={setMorning} label={t('followupBrief')} />
          <div className="max-w-40"><Labelled label={t('followupBriefTime')}><TextBox type="time" dir="ltr" value={time} disabled={!brief.editable} onChange={(e) => setTime(e.target.value)} /></Labelled></div>
          <Switch testId="followup-tasks" checked={auto} disabled={!tasks.editable} onCheckedChange={setAuto} label={t('followupTasks')} />
          <p className="text-xs text-text-muted">{t('followupNote')}</p>
        </section>
        <Link to={to('/alerts')} data-testid="alert-rules-link" className="inline-block text-sm font-semibold text-brand-600 hover:underline">{t('alertRules')}</Link>
        <div>
          <Button data-testid="followup-save" loading={save.isPending} disabled={!brief.editable && !tasks.editable} onClick={() => save.mutate({ settings: { followup: { morning_brief: morning, morning_time: time, tasks: auto } } })}>{save.isSuccess ? t('saved') : t('save')}</Button>
        </div>
      </div>
    </TabBody>
  )
}
