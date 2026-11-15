import { useState } from 'react'
import { Download } from 'lucide-react'
import { Pager } from '@/components/fit/Pager'
import { ResponsiveList } from '@/components/fit/ResponsiveList'
import { useFitRows } from '@/components/fit/useFitRows'
import { Button } from '@/components/ui/Button'
import { Switch } from '@/components/ui/Switch'
import { Skeleton } from '@/components/ui/States'
import { DELIVERY_STATUSES, type DeliveryStatus } from '@/lib/notifications'
import { LOG_EXPORT_URL, useDeliveryLogPage, useSaveWorkspaceSettings, useSubscriptionLogPage, type WorkspaceSettingsData } from './api'
import { useTypeWords } from './typeWords'
import { Labelled, LockedNote, StatusPill, TabBody, TextBox, ToggleChip, fieldCls, useGate } from './ui'

/** Tab 2 — which service-report events this workspace lets through. Sensitive operational events never appear here. */
export function EventsTab({ data }: { data: WorkspaceSettingsData }) {
  const type = data.workspace.type
  const { t, lang } = useTypeWords(type)
  const gate = useGate('notifications.external')
  const save = useSaveWorkspaceSettings()
  const events = data.events.filter((e) => e.scope !== 'internal' && !e.internal_only)

  return (
    <TabBody testid="events-tab">
      <div className="space-y-2">
        <LockedNote entitlement="notifications.external" />
        <p className="text-xs text-text-muted">{t('eventsHint')}</p>
        <ul className="grid gap-2 md:grid-cols-2">
          {events.map((e) => (
            <li key={e.key} className="flex items-center justify-between gap-2 rounded-xl border border-border bg-surface px-3 py-2">
              <div className="min-w-0"><div className="truncate text-sm font-semibold">{e.title.external?.[lang] ?? e.key}</div>
                <div className="truncate text-xs text-text-muted">{e.default_roles.map((r) => type.role_labels[r]?.[lang] ?? r).join(' / ')}</div></div>
              <Switch testId={`ext-event-${e.key}`} checked={e.route.enabled} disabled={!gate.editable || save.isPending}
                onCheckedChange={(v) => save.mutate({ routing: { ...data.settings.routing, [e.key]: { ...data.settings.routing[e.key], enabled: v } } })} />
            </li>
          ))}
        </ul>
      </div>
    </TabBody>
  )
}

const csv = (s: string) => s.split(',').map((x) => x.trim()).filter(Boolean)

/** Tab 3 — general settings: sender, CC/BCC, org domains, timezone, work days, phrasing, currency, disclaimer, roles, subjects, contact request. */
export function GeneralSettingsTab({ data }: { data: WorkspaceSettingsData }) {
  const type = data.workspace.type
  const { t, lang } = useTypeWords(type)
  const gate = useGate('notifications.external')
  const ccGate = useGate('notifications.external.cc_bcc')
  const subjGate = useGate('notifications.external.custom_subject')
  const save = useSaveWorkspaceSettings()
  const s = data.settings
  const x = s.settings as Record<string, Record<string, unknown> | string | boolean | undefined>
  const obj = (k: string) => (x[k] ?? {}) as Record<string, unknown>
  const str = (v: unknown, d = '') => (typeof v === 'string' ? v : d)
  const [sender, setSender] = useState({ name: str(obj('sender').name), reply_to: str(obj('sender').reply_to), signature: str(obj('sender').signature) })
  const [cc, setCc] = useState({ default_cc: ((obj('cc_bcc').default_cc as string[]) ?? []).join(', '), default_bcc: ((obj('cc_bcc').default_bcc as string[]) ?? []).join(', '), copy_delivery: str(obj('cc_bcc').copy_delivery, 'all') })
  const [domains, setDomains] = useState(s.org_domains.join(', '))
  const [zone, setZone] = useState(s.timezone)
  const [pref, setPref] = useState(((obj('phrasing').preferred as string[]) ?? []).join(', '))
  const [forb, setForb] = useState(((obj('phrasing').forbidden as string[]) ?? []).join(', '))
  const [currency, setCurrency] = useState(typeof x.currency === 'string' ? x.currency : '')
  const [disc, setDisc] = useState({ ar: str(obj('disclaimer').ar), en: str(obj('disclaimer').en) })
  const [subj, setSubj] = useState({ digest: str(obj('subject_templates').digest), best: str(obj('subject_templates').best) })
  const [contact, setContact] = useState(x.contact_request === true)
  const [labels, setLabels] = useState<Record<string, string>>((obj('role_labels') as Record<string, string>) ?? {})
  const ed = gate.editable

  return (
    <TabBody testid="general-settings-tab">
      <div className="space-y-3">
        <LockedNote entitlement="notifications.external" />
        <div className="grid gap-3 md:grid-cols-2">
          <section className="space-y-2 rounded-xl border border-border bg-surface p-3">
            <Labelled label={t('senderName')}><TextBox data-testid="set-sender-name" disabled={!ed} value={sender.name} onChange={(e) => setSender({ ...sender, name: e.target.value })} /></Labelled>
            <Labelled label={t('senderEmail')}><TextBox type="email" dir="ltr" disabled={!ed} value={sender.reply_to} onChange={(e) => setSender({ ...sender, reply_to: e.target.value })} /></Labelled>
            <Labelled label={t('signature')}><TextBox disabled={!ed} value={sender.signature} onChange={(e) => setSender({ ...sender, signature: e.target.value })} /></Labelled>
          </section>
          <section className="space-y-2 rounded-xl border border-border bg-surface p-3">
            <LockedNote entitlement="notifications.external.cc_bcc" compact />
            <Labelled label={t('ccDefault')}><TextBox dir="ltr" disabled={!ed || !ccGate.editable} value={cc.default_cc} onChange={(e) => setCc({ ...cc, default_cc: e.target.value })} /></Labelled>
            <Labelled label={t('bccDefault')}><TextBox dir="ltr" disabled={!ed || !ccGate.editable} value={cc.default_bcc} onChange={(e) => setCc({ ...cc, default_bcc: e.target.value })} /></Labelled>
            <Labelled label={t('copyDelivery')}>
              <select className={fieldCls} disabled={!ed || !ccGate.editable} value={cc.copy_delivery} onChange={(e) => setCc({ ...cc, copy_delivery: e.target.value })}>
                <option value="all">{t('copyAll')}</option><option value="events">{t('copyEvents')}</option><option value="digest">{t('copyDigest')}</option>
              </select>
            </Labelled>
          </section>
          <section className="space-y-2 rounded-xl border border-border bg-surface p-3">
            <Labelled label={t('orgDomains')} hint={t('orgDomainsHint')}><TextBox data-testid="set-org-domains" dir="ltr" disabled={!ed} value={domains} onChange={(e) => setDomains(e.target.value)} /></Labelled>
            <Labelled label={t('timezone')}><TextBox dir="ltr" disabled={!ed} value={zone} onChange={(e) => setZone(e.target.value)} /></Labelled>
            <Labelled label={t('currency')}><TextBox dir="ltr" maxLength={3} disabled={!ed} value={currency} onChange={(e) => setCurrency(e.target.value.toUpperCase())} /></Labelled>
          </section>
          <section className="space-y-2 rounded-xl border border-border bg-surface p-3">
            <Labelled label={t('phrasingPreferred')}><TextBox disabled={!ed} value={pref} onChange={(e) => setPref(e.target.value)} /></Labelled>
            <Labelled label={t('phrasingForbidden')} hint={t('phrasingHint')}><TextBox disabled={!ed} value={forb} onChange={(e) => setForb(e.target.value)} /></Labelled>
          </section>
          <section className="space-y-2 rounded-xl border border-border bg-surface p-3 md:col-span-2">
            <Labelled label={`${t('disclaimer')} (AR)`} hint={t('disclaimerHint', { workspace: '{workspace}' })}><textarea rows={2} disabled={!ed} className={`${fieldCls} h-auto py-2`} value={disc.ar} onChange={(e) => setDisc({ ...disc, ar: e.target.value })} /></Labelled>
            <Labelled label={`${t('disclaimer')} (EN)`}><textarea rows={2} dir="ltr" disabled={!ed} className={`${fieldCls} h-auto py-2`} value={disc.en} onChange={(e) => setDisc({ ...disc, en: e.target.value })} /></Labelled>
          </section>
          <section className="space-y-2 rounded-xl border border-border bg-surface p-3">
            <LockedNote entitlement="notifications.external.custom_subject" compact />
            <Labelled label={t('subjectDigest')} hint={t('subjectHint', { project: '{project}', workspace: '{workspace}' })}><TextBox disabled={!ed || !subjGate.editable} value={subj.digest} onChange={(e) => setSubj({ ...subj, digest: e.target.value })} /></Labelled>
            <Labelled label={t('subjectBest')}><TextBox disabled={!ed || !subjGate.editable} value={subj.best} onChange={(e) => setSubj({ ...subj, best: e.target.value })} /></Labelled>
          </section>
          <section className="space-y-2 rounded-xl border border-border bg-surface p-3">
            <h3 className="text-sm font-bold">{t('roles')}</h3>
            <div className="grid gap-2 sm:grid-cols-2">
              {type.roles.map((r) => (
                <Labelled key={r.key} label={type.role_labels[r.key]?.[lang] ?? r.key}>
                  <TextBox disabled={!ed} placeholder={type.role_labels[r.key]?.[lang]} value={labels[r.key] ?? ''} onChange={(e) => setLabels({ ...labels, [r.key]: e.target.value })} />
                </Labelled>
              ))}
            </div>
            <Switch testId="set-contact-request" checked={contact} disabled={!ed} onCheckedChange={setContact} label={t('contactRequest')} />
            <p className="text-xs text-text-muted">{t('contactRequestHint')}</p>
          </section>
        </div>
        <Button data-testid="general-save" disabled={!ed} loading={save.isPending} onClick={() => save.mutate({
          org_domains: csv(domains), timezone: zone,
          settings: {
            sender, cc_bcc: { default_cc: csv(cc.default_cc), default_bcc: csv(cc.default_bcc), copy_delivery: cc.copy_delivery },
            phrasing: { preferred: csv(pref), forbidden: csv(forb) }, currency, disclaimer: disc, subject_templates: subj, contact_request: contact, role_labels: labels,
          },
        })}>{save.isSuccess ? t('saved') : t('save')}</Button>
      </div>
    </TabBody>
  )
}

const LOG_TONE: Record<DeliveryStatus, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  sent: 'success', failed: 'danger', deferred: 'neutral', skipped: 'neutral', held_for_review: 'warning', held_for_privacy: 'warning', blocked_by_plan: 'info',
}
const STATUS_WORDS: Record<DeliveryStatus, { ar: string; en: string }> = {
  sent: { ar: 'أُرسلت', en: 'Sent' }, failed: { ar: 'تعذر الإرسال', en: 'Failed' }, deferred: { ar: 'مؤجلة', en: 'Deferred' }, skipped: { ar: 'تُخطيت', en: 'Skipped' },
  held_for_review: { ar: 'بانتظار المراجعة', en: 'Held for review' }, held_for_privacy: { ar: 'موقوفة للخصوصية', en: 'Held for privacy' }, blocked_by_plan: { ar: 'غير متاحة في الخطة', en: 'Blocked by plan' },
}
const ACTION_WORDS: Record<string, { ar: string; en: string }> = {
  created: { ar: 'إنشاء', en: 'Created' }, migrated: { ar: 'نقل', en: 'Migrated' }, confirmation_requested: { ar: 'طلب تأكيد', en: 'Confirmation requested' },
  confirmed: { ar: 'تأكيد', en: 'Confirmed' }, approved_internally: { ar: 'اعتماد داخلي', en: 'Approved internally' }, unsubscribed: { ar: 'إلغاء الاشتراك', en: 'Unsubscribed' },
  reduced: { ar: 'تقليل', en: 'Reduced' }, paused: { ar: 'إيقاف مؤقت', en: 'Paused' }, resumed: { ar: 'استئناف', en: 'Resumed' }, cancelled: { ar: 'إلغاء', en: 'Cancelled' },
  no_confirmation_7d: { ar: 'لا تأكيد خلال 7 أيام', en: 'No confirmation in 7 days' },
}

/** Tab 4 — the immutable log: messages (with status filter, CSV) and the subscription log. Read-only; there is no edit or delete anywhere. */
export function LogTab({ data }: { data: WorkspaceSettingsData }) {
  const { t, ar, lang } = useTypeWords(data.workspace.type)
  const [which, setWhich] = useState<'messages' | 'subscriptions'>('messages')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const { ref, rows: perPage } = useFitRows({ min: 3, max: 30, fallback: 10 })
  const msgs = useDeliveryLogPage(page, Math.max(10, perPage), status)
  const subs = useSubscriptionLogPage(page)
  const when = (iso: string) => new Date(iso.includes('T') ? iso : iso.replace(' ', 'T')).toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB')
  const active = which === 'messages' ? msgs : subs

  return (
    <div data-testid="log-tab" className="flex min-h-0 flex-1 flex-col gap-2">
      <div className="flex flex-wrap items-center gap-2">
        <ToggleChip on={which === 'messages'} onClick={() => { setWhich('messages'); setPage(1) }} testid="log-messages">{t('logMessages')}</ToggleChip>
        <ToggleChip on={which === 'subscriptions'} onClick={() => { setWhich('subscriptions'); setPage(1) }} testid="log-subscriptions">{t('logSubscriptions')}</ToggleChip>
        {which === 'messages' && (
          <select aria-label={t('colStatus')} className={`${fieldCls} w-auto`} value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
            <option value="">{t('colStatus')}</option>
            {DELIVERY_STATUSES.map((s) => <option key={s} value={s}>{STATUS_WORDS[s][lang]}</option>)}
          </select>
        )}
        <a href={LOG_EXPORT_URL} data-testid="log-export" className="ms-auto inline-flex h-9 items-center gap-1.5 rounded-xl border border-border-strong px-3 text-sm font-semibold hover:bg-surface-hover"><Download size={15} aria-hidden /> {t('exportCsv')}</a>
      </div>
      <p className="text-xs text-text-muted">{t('logImmutable')}</p>
      <div ref={ref as never} className="flex min-h-0 flex-1 flex-col gap-1">
        <div className="min-h-0 flex-1 overflow-y-auto md:overflow-hidden">
          {active.isLoading ? <Skeleton className="h-32" /> : which === 'messages' ? (
            <ResponsiveList
              testid="log-rows" rows={msgs.data?.rows ?? []} rowKey={(r) => r.id}
              empty={<p className="p-6 text-center text-sm text-text-muted">{t('noLog')}</p>}
              columns={[
                { key: 'when', header: t('colWhen'), width: '20%', cell: (r) => <span dir="ltr" className="text-xs">{when(r.created_at)}</span> },
                { key: 'ref', header: t('colRef'), width: '14%', cell: (r) => <span dir="ltr" className="text-xs">{r.reference}</span> },
                { key: 'to', header: t('colRecipient'), cell: (r) => <span dir="ltr">{r.recipient_address ?? '-'}</span> },
                { key: 'project', header: t('colProjects'), width: '20%', cell: (r) => r.project_name ?? '-' },
                { key: 'status', header: t('colStatus'), width: '18%', cell: (r) => <StatusPill tone={LOG_TONE[r.status]}>{STATUS_WORDS[r.status][lang]}</StatusPill> },
              ]}
              renderCard={(r) => (
                <div className="space-y-1 rounded-xl border border-border bg-surface p-3 text-sm">
                  <div className="flex items-center justify-between gap-2"><span dir="ltr" className="min-w-0 truncate">{r.recipient_address ?? '-'}</span><StatusPill tone={LOG_TONE[r.status]}>{STATUS_WORDS[r.status][lang]}</StatusPill></div>
                  <div className="text-xs text-text-muted"><span dir="ltr">{when(r.created_at)}</span> - {r.project_name ?? '-'}</div>
                </div>
              )}
            />
          ) : (
            <ResponsiveList
              testid="sublog-rows" rows={subs.data?.rows ?? []} rowKey={(r) => r.id}
              empty={<p className="p-6 text-center text-sm text-text-muted">{t('noLog')}</p>}
              columns={[
                { key: 'when', header: t('colWhen'), width: '24%', cell: (r) => <span dir="ltr" className="text-xs">{when(r.created_at)}</span> },
                { key: 'action', header: t('colAction'), cell: (r) => ACTION_WORDS[r.action]?.[lang] ?? r.action },
                { key: 'who', header: t('colWho'), width: '30%', cell: (r) => r.actor_label ?? '-' },
              ]}
              renderCard={(r) => (
                <div className="rounded-xl border border-border bg-surface p-3 text-sm"><div className="font-semibold">{ACTION_WORDS[r.action]?.[lang] ?? r.action}</div>
                  <div className="text-xs text-text-muted"><span dir="ltr">{when(r.created_at)}</span> - {r.actor_label ?? '-'}</div></div>
              )}
            />
          )}
        </div>
        <Pager page={page} pages={active.data?.lastPage ?? 1} onPage={setPage} total={active.data?.total} ar={ar} testid="log-pager" hideWhenSingle />
      </div>
    </div>
  )
}
