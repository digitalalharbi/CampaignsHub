import { useMemo, useState } from 'react'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { Alert } from '@/components/ui/Alert'
import { checkPeriod, DIGEST_FREQUENCIES, DIGEST_PERIOD_KEYS, DIGEST_TYPE_KEYS, type DigestFrequency, type DigestPeriodKey } from '@/lib/notifications'
import { toApiError } from '@/lib/api/client'
import {
  useBindableProjects, usePreviewMessage, useRecipientActions, useTestSend, type RecipientInput, type RecipientRow, type WorkspaceSettingsData,
} from './api'
import { PhonePreview } from './PhonePreview'
import { fill, type WordKey } from './strings'
import { useTypeWords } from './typeWords'
import { Labelled, LockedNote, TextBox, ToggleChip, fieldCls, useGate } from './ui'

const TEMPLATES = [
  { key: 'monthly_review', ar: 'مراجعة شهرية', en: 'Monthly review', frequency: 'monthly' as DigestFrequency, period: 'last_month' as DigestPeriodKey, types: ['performance', 'spend', 'budgets', 'achievements'] },
  { key: 'weekly_progress', ar: 'متابعة أسبوعية', en: 'Weekly progress', frequency: 'weekly' as DigestFrequency, period: 'last_week' as DigestPeriodKey, types: ['performance', 'top_campaigns'] },
  { key: 'continuity', ar: 'استمرارية الميزانية', en: 'Budget continuity', frequency: 'weekly' as DigestFrequency, period: 'active_budget_period' as DigestPeriodKey, types: ['budgets'] },
  { key: 'achievements', ar: 'الإنجازات', en: 'Achievements', frequency: 'weekly' as DigestFrequency, period: 'last_7' as DigestPeriodKey, types: ['achievements', 'suggestions'] },
]
const PERIOD_AR: Record<DigestPeriodKey, string> = {
  yesterday: 'أمس', last_7: 'آخر 7 أيام', this_week_to_yesterday: 'هذا الأسبوع حتى أمس', last_week: 'الأسبوع الماضي',
  this_month_to_yesterday: 'هذا الشهر حتى أمس', last_month: 'الشهر الماضي', last_30: 'آخر 30 يوم', active_budget_period: 'فترة الميزانية النشطة', ytd: 'من بداية السنة',
}
const PERIOD_EN: Record<DigestPeriodKey, string> = {
  yesterday: 'yesterday', last_7: 'the last 7 days', this_week_to_yesterday: 'this week until yesterday', last_week: 'last week',
  this_month_to_yesterday: 'this month until yesterday', last_month: 'last month', last_30: 'the last 30 days', active_budget_period: 'the active budget period', ytd: 'the year to date',
}
const TYPE_AR: Record<string, string> = { performance: 'النمو والأداء', spend: 'الإنفاق حسب المنصة', budgets: 'الميزانيات والاستمرارية', top_campaigns: 'أفضل الحملات', suggestions: 'اقتراحات للنمو', achievements: 'الإنجازات' }
const TYPE_EN: Record<string, string> = { performance: 'Growth and performance', spend: 'Spend by platform', budgets: 'Budgets and continuity', top_campaigns: 'Top campaigns', suggestions: 'Growth suggestions', achievements: 'Achievements' }
const FREQ_WORD: Record<DigestFrequency, WordKey> = { daily: 'fDaily', weekly: 'fWeekly', monthly: 'fMonthly' }
const STEPS: WordKey[] = ['wizStep1', 'wizStep2', 'wizStep3', 'wizStep4']

function draftFrom(row: RecipientRow | null, responsible: string): RecipientInput {
  const sub = row?.subscriptions[0]

  return {
    email: row?.email ?? '', name: row?.name ?? '', salutation: row?.salutation ?? '', role_key: row?.role_key ?? responsible,
    language: row?.language ?? 'ar', display_currency: row?.display_currency ?? null, is_role_mailbox: row?.is_role_mailbox ?? false,
    subscription: {
      template_key: sub?.template_key ?? null, project_ids: sub?.project_ids ?? [], event_keys: sub?.event_keys ?? [], digest_types: sub?.digest_types ?? ['performance'],
      period_key: sub?.period_key ?? 'last_7', frequency: sub?.frequency ?? 'weekly', weekdays: sub?.weekdays ?? [], month_day: sub?.month_day ?? null,
      send_time: sub?.send_time ?? '08:00', subject_template: sub?.subject_template ?? null, cc: sub?.cc ?? [], bcc: sub?.bcc ?? [],
    },
  }
}

/** The 4-step wizard (spec §14). `editing` prefills it from an existing recipient. */
export function RecipientWizard({ open, onClose, data, editing, userEmail }: {
  open: boolean; onClose: () => void; data: WorkspaceSettingsData; editing: RecipientRow | null; userEmail?: string
}) {
  const type = data.workspace.type
  const { t, ar, lang } = useTypeWords(type)
  const [step, setStep] = useState(0)
  const [draft, setDraft] = useState<RecipientInput>(() => draftFrom(editing, type.responsible_role))
  const [advanced, setAdvanced] = useState(false)
  const [previewProject, setPreviewProject] = useState('')
  const [theme, setTheme] = useState<'light' | 'dark'>('light')
  const [plang, setPlang] = useState<'ar' | 'en'>(ar ? 'ar' : 'en')
  const [confirmSend, setConfirmSend] = useState(false)
  const [error, setError] = useState('')
  const projects = useBindableProjects()
  const actions = useRecipientActions()
  const previewM = usePreviewMessage()
  const testM = useTestSend()
  const cc = useGate('notifications.external.cc_bcc')
  const subj = useGate('notifications.external.custom_subject')
  const sub = draft.subscription
  const periods = ar ? PERIOD_AR : PERIOD_EN
  const types = ar ? TYPE_AR : TYPE_EN
  const verdict = sub.period_key && sub.frequency ? checkPeriod(sub.period_key as DigestPeriodKey, sub.frequency) : { ok: true, alternative: null }
  const isOrg = data.settings.org_domains.includes((draft.email.split('@')[1] ?? '').toLowerCase())
  const roleMailbox = /^(finance|accounts|billing|marketing|info|admin|team|ads)@/i.test(draft.email)
  const set = (patch: Partial<RecipientInput>) => setDraft((d) => ({ ...d, ...patch }))
  const setSub = (patch: Partial<RecipientInput['subscription']>) => setDraft((d) => ({ ...d, subscription: { ...d.subscription, ...patch } }))
  const toggle = (list: string[], v: string) => (list.includes(v) ? list.filter((x) => x !== v) : [...list, v])
  const externalEvents = useMemo(() => data.events.filter((e) => e.scope !== 'internal' && !e.internal_only), [data.events])
  const sentence = fill(ar ? 'أرسل {types} عن {period} {frequency} الساعة {time}' : 'Send {types} about {period} {frequency} at {time}', {
    types: sub.digest_types.map((k) => types[k] ?? k).join(ar ? '، ' : ', ') || '…', period: periods[(sub.period_key ?? 'last_7') as DigestPeriodKey] ?? '…',
    frequency: sub.frequency ? t(FREQ_WORD[sub.frequency]) : '…', time: sub.send_time,
  })
  const stepOk = [draft.email.includes('@') && draft.role_key !== '', true, verdict.ok && sub.digest_types.length > 0, sub.project_ids.length > 0]

  const save = () => {
    setError('')
    const onError = (e: unknown) => setError(toApiError(e).message)
    if (editing) actions.update.mutate({ id: editing.id, body: draft }, { onSuccess: onClose, onError })
    else actions.create.mutate(draft, { onSuccess: onClose, onError })
  }

  return (
    <Modal open={open} onClose={onClose} size="xl" title={editing ? t('wizEditTitle') : t('wizTitle')}
      footer={
        <div className="flex w-full flex-wrap items-center justify-between gap-2">
          <Button variant="ghost" onClick={step === 0 ? onClose : () => setStep(step - 1)} data-testid="wiz-back">{step === 0 ? t('cancel') : t('back')}</Button>
          {step < 3
            ? <Button disabled={!stepOk[step]} onClick={() => setStep(step + 1)} data-testid="wiz-next">{t('next')}</Button>
            : <Button disabled={!stepOk[3]} loading={actions.create.isPending || actions.update.isPending} onClick={save} data-testid="wiz-save">{t('saveRecipient')}</Button>}
        </div>
      }>
      <div data-testid="recipient-wizard" className="space-y-3">
        <ol className="flex flex-wrap gap-1 text-xs font-semibold" aria-label="steps">
          {STEPS.map((s, i) => <li key={s} aria-current={i === step ? 'step' : undefined} className={`rounded-full px-2.5 py-1 ${i === step ? 'bg-brand-600 text-white' : 'bg-surface-secondary text-text-muted'}`}>{i + 1}. {t(s)}</li>)}
        </ol>
        {error && <Alert severity="danger" title={error} />}

        {step === 0 && (
          <div className="grid gap-3 sm:grid-cols-2">
            <Labelled label={t('email')}><TextBox type="email" dir="ltr" data-testid="wiz-email" value={draft.email} onChange={(e) => set({ email: e.target.value })} /></Labelled>
            <Labelled label={t('role')}>
              <select className={fieldCls} data-testid="wiz-role" value={draft.role_key} onChange={(e) => set({ role_key: e.target.value })}>
                {type.roles.map((r) => <option key={r.key} value={r.key}>{type.role_labels[r.key]?.[lang] ?? r.key}</option>)}
              </select>
            </Labelled>
            <Labelled label={t('name')}><TextBox value={draft.name ?? ''} onChange={(e) => set({ name: e.target.value })} /></Labelled>
            <Labelled label={t('salutation')} hint={t('salutationHint')}><TextBox value={draft.salutation ?? ''} onChange={(e) => set({ salutation: e.target.value })} /></Labelled>
            <Labelled label={t('language')}>
              <select className={fieldCls} value={draft.language} onChange={(e) => set({ language: e.target.value as 'ar' | 'en' })}><option value="ar">العربية</option><option value="en">English</option></select>
            </Labelled>
            <label className="flex items-center gap-2 self-end pb-2 text-sm"><input type="checkbox" checked={draft.is_role_mailbox} onChange={(e) => set({ is_role_mailbox: e.target.checked })} />{t('roleMailbox')}</label>
            {draft.email.includes('@') && !roleMailbox && !draft.is_role_mailbox && <p data-testid="wiz-mailbox-hint" className="text-xs text-text-muted sm:col-span-2">{t('roleMailboxHint')}</p>}
            {isOrg && <p className="text-xs text-text-muted sm:col-span-2">{t('orgDomainsHint')}</p>}
          </div>
        )}

        {step === 1 && (
          <div data-testid="wiz-templates" className="grid grid-cols-2 gap-2 md:grid-cols-4">
            {TEMPLATES.map((tpl) => {
              const on = sub.template_key === tpl.key

              return (
                <button key={tpl.key} type="button" aria-pressed={on} data-testid={`tpl-${tpl.key}`}
                  onClick={() => setSub({ template_key: tpl.key, frequency: tpl.frequency, period_key: tpl.period, digest_types: tpl.types })}
                  className={`flex flex-col gap-2 rounded-xl border p-2 text-start ${on ? 'border-brand-600 ring-2 ring-brand-500/20' : 'border-border hover:bg-surface-hover'}`}>
                  <span aria-hidden className="flex h-16 flex-col gap-1 rounded-lg bg-surface-secondary p-1.5">
                    <span className="h-2 w-1/2 rounded bg-brand-600/60" /><span className="h-5 rounded bg-brand-600/20" /><span className="h-2 w-3/4 rounded bg-border-strong" /><span className="h-2 w-2/3 rounded bg-border-strong" />
                  </span>
                  <span className="text-sm font-bold">{ar ? tpl.ar : tpl.en}</span>
                </button>
              )
            })}
          </div>
        )}

        {step === 2 && (
          <div className="space-y-3">
            <p data-testid="wiz-sentence" className="rounded-lg bg-surface-secondary px-3 py-2 text-sm font-semibold">{sentence}</p>
            <div className="grid gap-3 sm:grid-cols-3">
              <Labelled label={t('frequency')}>
                <select className={fieldCls} data-testid="wiz-frequency" value={sub.frequency ?? 'weekly'} onChange={(e) => setSub({ frequency: e.target.value as DigestFrequency })}>
                  {DIGEST_FREQUENCIES.map((f) => <option key={f} value={f}>{t(FREQ_WORD[f])}</option>)}
                </select>
              </Labelled>
              <Labelled label={t('period')}>
                <select className={fieldCls} data-testid="wiz-period" value={sub.period_key ?? 'last_7'} onChange={(e) => setSub({ period_key: e.target.value })}>
                  {DIGEST_PERIOD_KEYS.map((p) => <option key={p} value={p}>{periods[p]}</option>)}
                </select>
              </Labelled>
              <Labelled label={t('atTime')}><TextBox type="time" dir="ltr" value={sub.send_time} onChange={(e) => setSub({ send_time: e.target.value })} /></Labelled>
            </div>
            {!verdict.ok && (
              <div data-testid="wiz-illogical" className="flex flex-wrap items-center gap-2 text-sm text-warning">
                <span>{t('illogical')}</span>
                {verdict.alternative && <Button size="sm" variant="secondary" data-testid="wiz-alt" onClick={() => setSub({ period_key: verdict.alternative })}>{t('useAlternative')}: {periods[verdict.alternative]}</Button>}
              </div>
            )}
            <fieldset><legend className="mb-1 text-xs font-semibold text-text-secondary">{t('digestTypes')}</legend>
              <div className="flex flex-wrap gap-1">{DIGEST_TYPE_KEYS.map((k) => <ToggleChip key={k} on={sub.digest_types.includes(k)} onClick={() => setSub({ digest_types: toggle(sub.digest_types, k) })}>{types[k]}</ToggleChip>)}</div>
            </fieldset>
            <fieldset><legend className="mb-1 text-xs font-semibold text-text-secondary">{t('tEvents')}</legend>
              <div className="flex flex-wrap gap-1">{externalEvents.map((e) => <ToggleChip key={e.key} on={sub.event_keys.includes(e.key)} onClick={() => setSub({ event_keys: toggle(sub.event_keys, e.key) })}>{e.title.external?.[lang] ?? e.key}</ToggleChip>)}</div>
            </fieldset>
            <button type="button" className="text-sm font-semibold text-brand-600" aria-expanded={advanced} data-testid="wiz-advanced" onClick={() => setAdvanced(!advanced)}>{t('advanced')}</button>
            {advanced && (
              <div className="space-y-2">
                <LockedNote entitlement="notifications.external.custom_subject" terms={undefined} compact />
                <Labelled label={t('customSubject')}><TextBox disabled={!subj.editable} value={sub.subject_template ?? ''} onChange={(e) => setSub({ subject_template: e.target.value || null })} /></Labelled>
                <LockedNote entitlement="notifications.external.cc_bcc" compact />
                <div className="grid gap-2 sm:grid-cols-2">
                  <Labelled label={t('cc')}><TextBox dir="ltr" disabled={!cc.editable} value={sub.cc.join(', ')} onChange={(e) => setSub({ cc: e.target.value.split(',').map((x) => x.trim()).filter(Boolean) })} /></Labelled>
                  <Labelled label={t('bcc')}><TextBox dir="ltr" disabled={!cc.editable} value={sub.bcc.join(', ')} onChange={(e) => setSub({ bcc: e.target.value.split(',').map((x) => x.trim()).filter(Boolean) })} /></Labelled>
                </div>
                {userEmail && <Button size="sm" variant="secondary" disabled={!cc.editable} onClick={() => setSub({ cc: sub.cc.includes(userEmail) ? sub.cc : [...sub.cc, userEmail] })}>{t('addMine')}</Button>}
              </div>
            )}
          </div>
        )}

        {step === 3 && (
          <div className="grid gap-4 md:grid-cols-[1fr_280px]">
            <div className="space-y-2">
              <h3 className="text-sm font-bold">{t('pickProjects')}</h3>
              {(projects.data ?? []).length === 0 ? <p className="text-sm text-text-muted">{t('noProjects')}</p> : (
                <div data-testid="wiz-projects" className="flex max-h-56 flex-wrap gap-1 overflow-y-auto">
                  {(projects.data ?? []).map((p) => <ToggleChip key={p.id} on={sub.project_ids.includes(p.id)} testid={`proj-${p.id}`} onClick={() => { setSub({ project_ids: toggle(sub.project_ids, p.id) }); setPreviewProject(p.id) }}>{p.name}</ToggleChip>)}
                </div>
              )}
              <div className="flex flex-wrap gap-2 pt-2">
                <Button size="sm" variant="secondary" disabled={!previewProject} loading={testM.isPending} data-testid="wiz-send-me" onClick={() => testM.mutate({ draft, project_id: previewProject, to: 'self' })}>{t('sendMe')}</Button>
                <Button size="sm" variant="secondary" disabled={!previewProject} data-testid="wiz-send-recipient" onClick={() => setConfirmSend(true)}>{t('sendRecipient')}</Button>
              </div>
              {confirmSend && (
                <div role="alertdialog" className="flex flex-wrap items-center gap-2 rounded-lg border border-warning/40 bg-warning-soft p-2 text-sm" data-testid="wiz-send-confirm">
                  <span className="min-w-0 flex-1">{t('sendRecipientConfirm')}</span>
                  <Button size="sm" onClick={() => { testM.mutate({ draft, project_id: previewProject, to: 'recipient' }); setConfirmSend(false) }} data-testid="wiz-send-confirm-yes">{t('confirmSend')}</Button>
                  <Button size="sm" variant="ghost" onClick={() => setConfirmSend(false)}>{t('cancel')}</Button>
                </div>
              )}
            </div>
            <PhonePreview
              result={previewM.data ?? null} loading={previewM.isPending} theme={theme} setTheme={setTheme} lang={plang} setLang={setPlang}
              canRender={previewProject !== ''} emptyText={t('previewEmpty')}
              onRender={() => previewM.mutate({ draft, project_id: previewProject, locale: plang, theme })}
            />
          </div>
        )}
      </div>
    </Modal>
  )
}
