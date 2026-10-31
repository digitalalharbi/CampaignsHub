import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Check, Copy, CreditCard, KeyRound, Loader2, Settings2, Star, Webhook } from 'lucide-react'
import {
  fetchPaymentSettings, fetchSecretRotation, savePaymentGateway, setPaymentGatewayEnabled, setPrimaryPaymentGateway,
  testPaymentProvider, type PaymentProviderSetting,
} from './api'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * The payment gateways, from the platform owner's console (PAYSET-001, PAY-GATEWAY-CONSOLE-001).
 *
 * Six approved gateways. Keys are pasted here and stored encrypted by the server; a saved secret is never shown again, only that it
 * is present. Saving keys, testing, enabling (live needs its own confirmation) and choosing the primary are separate acts, and the
 * server refuses to enable a gateway whose current keys have not passed a test. New payments go to the primary; nothing that exists
 * moves when it changes.
 */

const COPY = {
  ar: {
    title: 'وسائل الدفع',
    subtitle: 'ميسر هي البوابة الأساسية المستهدفة. أدخل مفاتيح كل بوابة هنا مباشرة؛ تُحفظ مشفّرة ولا تُعرض بعد الحفظ.',
    primary: 'المزوّد الأساسي', alternative: 'مزوّد بديل', isPrimary: 'الأساسية الآن',
    status: {
      not_implemented: 'غير منفّذة بعد', awaiting_credentials: 'بانتظار المفاتيح', needs_test: 'تحتاج اختبارًا',
      ready: 'جاهزة — غير مفعّلة', enabled: 'مفعّلة',
    } as Record<string, string>,
    modeTest: 'وضع الاختبار', modeLive: 'وضع الإنتاج (Live)', noKeys: 'لا توجد مفاتيح',
    sourceEnv: 'المفاتيح الحالية من بيئة التشغيل', sourceConsole: 'المفاتيح محفوظة من لوحة الأدمن',
    setup: 'إعداد الربط', close: 'إغلاق', save: 'حفظ', saving: 'جارٍ الحفظ…', saved: 'حُفظ.', nothing: 'لم يتغيّر شيء.',
    stored: 'محفوظ — اتركه فارغًا للإبقاء عليه', notStored: 'غير محفوظ', optional: 'اختياري',
    mode: 'الوضع', test: 'اختبار الاتصال', testing: 'جارٍ الاختبار…',
    reachable: 'البوابة استجابت بنجاح.', unreachable: 'البوابة رفضت الطلب.',
    lastTest: 'آخر اختبار', stale: 'المفاتيح تغيّرت بعد آخر اختبار — أعد الاختبار.',
    enable: 'تفعيل للمدفوعات الجديدة', disable: 'تعطيل', makePrimary: 'تعيين كبوابة أساسية',
    confirmLive: 'هذه مفاتيح إنتاج وستُحصّل أموالًا حقيقية. هل تريد التفعيل؟',
    confirmPrimary: 'ستتجه المدفوعات الجديدة فقط إلى هذه البوابة، وتبقى المدفوعات والاشتراكات السابقة على بوابتها الأصلية. متابعة؟',
    webhook: 'Webhook', copy: 'نسخ الرابط', copied: 'نُسخ', rotation: 'تدوير المفاتيح',
    methods: 'وسائل الدفع', notImplemented: 'هذه البوابة لا تملك Adapter بعد؛ لا يمكن حفظ مفاتيحها أو اختبارها.',
    mailTitle: 'قناة الإشعارات',
    mailSandbox: 'مزوّد محلي (Sandbox) — الرسائل تُكتب ولا تصل إلى أحد.',
    mailAwaiting: 'لا يوجد مزوّد بريد — لن تصل أي إشعارات.',
    mailLive: 'مزوّد بريد مفعّل.',
    recurringTitle: 'التجديد التلقائي',
    recurringReady: 'البوابة الحالية تستطيع خصم التجديد من بطاقة محفوظة.',
    recurringNoGateway: 'لا توجد بوابة دفع مهيّأة، لذلك كل تجديد يصل كفاتورة يسدّدها العميل بنفسه.',
    recurringUnsupported: 'البوابة الحالية لا تدعم الخصم التلقائي، لذلك كل تجديد يصل كفاتورة يسدّدها العميل بنفسه.',
    recurringCards: 'البطاقات المحفوظة',
  },
  en: {
    title: 'Payment methods',
    subtitle: 'Moyasar is the intended primary gateway. Enter each gateway’s keys here; they are stored encrypted and never shown again.',
    primary: 'Primary provider', alternative: 'Alternative provider', isPrimary: 'Primary now',
    status: {
      not_implemented: 'Not implemented yet', awaiting_credentials: 'Awaiting keys', needs_test: 'Needs a test',
      ready: 'Ready — not enabled', enabled: 'Enabled',
    } as Record<string, string>,
    modeTest: 'Test mode', modeLive: 'Live mode', noKeys: 'No keys',
    sourceEnv: 'Current keys come from the environment', sourceConsole: 'Keys saved from the console',
    setup: 'Set up', close: 'Close', save: 'Save', saving: 'Saving…', saved: 'Saved.', nothing: 'Nothing changed.',
    stored: 'Stored — leave blank to keep it', notStored: 'Not stored', optional: 'optional',
    mode: 'Mode', test: 'Test connection', testing: 'Testing…',
    reachable: 'The gateway accepted the request.', unreachable: 'The gateway refused the request.',
    lastTest: 'Last test', stale: 'The keys changed after the last test — test again.',
    enable: 'Enable for new payments', disable: 'Disable', makePrimary: 'Make primary',
    confirmLive: 'These are live keys and will take real money. Enable?',
    confirmPrimary: 'Only NEW payments will go to this gateway; existing payments and subscriptions stay with their own. Continue?',
    webhook: 'Webhook', copy: 'Copy URL', copied: 'Copied', rotation: 'Rotate keys',
    methods: 'Payment methods', notImplemented: 'This gateway has no adapter yet, so its keys cannot be stored or tested.',
    mailTitle: 'Notification channel',
    mailSandbox: 'A local provider (sandbox) — messages are written and reach nobody.',
    mailAwaiting: 'No mail provider — no notification will reach anyone.',
    mailLive: 'A mail provider is configured.',
    recurringTitle: 'Automatic renewal',
    recurringReady: 'The current gateway can take a renewal from a card on file.',
    recurringNoGateway: 'No payment gateway is configured, so every renewal arrives as an invoice the customer pays themselves.',
    recurringUnsupported: 'The current gateway does not take automatic payments, so every renewal arrives as an invoice the customer pays themselves.',
    recurringCards: 'Cards on file',
  },
} as const

type Copy = typeof COPY['en'] | typeof COPY['ar']

export function PaymentSettingsPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const c = COPY[ar ? 'ar' : 'en']

  const settings = useQuery({ queryKey: ['admin', 'payment-settings'], queryFn: fetchPaymentSettings })

  if (settings.isPending) {
    return <div className="flex justify-center p-10"><Loader2 className="animate-spin text-brand-600" /></div>
  }

  if (settings.isError || !settings.data) {
    return <p className="text-sm text-danger">{toApiError(settings.error).message}</p>
  }

  const mail = settings.data.mail
  const recurring = settings.data.recurring

  return (
    <div data-testid="admin-payment-settings" className="flex flex-col gap-5">
      <header>
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">{c.title}</h1>
        <p className="mt-1 max-w-2xl text-sm text-text-secondary">{c.subtitle}</p>
      </header>

      <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        {settings.data.providers.map((p) => (
          <ProviderCard key={p.provider} provider={p} ar={ar} copy={c} />
        ))}
      </div>

      <section data-testid="payment-mail-state" data-state={mail.state} className="rounded-2xl border border-border bg-surface p-4">
        <p className="text-sm font-bold text-text-primary">{c.mailTitle}</p>
        <p className="mt-1 text-sm text-text-secondary">
          {mail.state === 'live' ? c.mailLive : mail.state === 'sandbox' ? c.mailSandbox : c.mailAwaiting}
          <span className="ms-1.5 text-xs text-text-muted" dir="ltr">({mail.driver})</span>
        </p>
      </section>

      <section
        data-testid="payment-recurring-state"
        data-ready={recurring.ready}
        data-reason={recurring.reason}
        className="rounded-2xl border border-border bg-surface p-4"
      >
        <p className="text-sm font-bold text-text-primary">{c.recurringTitle}</p>
        <p className="mt-1 text-sm text-text-secondary">
          {recurring.ready
            ? c.recurringReady
            : recurring.reason === 'provider_unsupported' ? c.recurringUnsupported : c.recurringNoGateway}
        </p>
        <p data-testid="payment-recurring-cards" className="mt-1 text-xs text-text-muted">
          {c.recurringCards}: <span className="tnum font-semibold" dir="ltr">{recurring.saved_methods}</span>
        </p>
      </section>
    </div>
  )
}

function ProviderCard({ provider, ar, copy }: { provider: PaymentProviderSetting; ar: boolean; copy: Copy }) {
  const qc = useQueryClient()
  const [panel, setPanel] = useState<'setup' | 'webhook' | 'rotation' | null>(null)
  const [copied, setCopied] = useState(false)
  const refresh = () => qc.invalidateQueries({ queryKey: ['admin', 'payment-settings'] })

  const test = useMutation({ mutationFn: () => testPaymentProvider(provider.provider), onSettled: refresh })
  const activation = useMutation({
    mutationFn: (enabled: boolean) => setPaymentGatewayEnabled(provider.provider, enabled, enabled && provider.mode === 'live'),
    onSuccess: refresh,
  })
  const primary = useMutation({ mutationFn: () => setPrimaryPaymentGateway(provider.provider), onSuccess: refresh })
  const rotation = useQuery({
    queryKey: ['admin', 'payment-rotation', provider.provider],
    queryFn: () => fetchSecretRotation(provider.provider),
    enabled: panel === 'rotation',
  })

  const name = ar ? provider.label.ar : provider.label.en
  const statusTone = provider.status === 'enabled'
    ? 'bg-[var(--positive-background)] text-[var(--positive-foreground)]'
    : provider.status === 'needs_test' || provider.status === 'ready' ? 'bg-[var(--warning-background)] text-[var(--warning)]' : 'bg-surface-secondary text-text-muted'
  const actionError = activation.error ?? primary.error

  const copyUrl = async () => {
    try {
      await navigator.clipboard.writeText(provider.webhook_url)
      setCopied(true)
      window.setTimeout(() => setCopied(false), 1500)
    } catch {
      setCopied(false)
    }
  }

  return (
    <section
      data-testid={`payment-provider-${provider.provider}`}
      data-status={provider.status}
      data-environment={provider.environment}
      className="flex min-w-0 flex-col gap-3 rounded-2xl border border-border bg-surface p-4"
    >
      <div className="flex flex-wrap items-center gap-2">
        <CreditCard size={18} className="shrink-0 text-brand-600" />
        <span className="text-sm font-bold text-text-primary">{name}</span>
        <span className="rounded-lg bg-surface-secondary px-2 py-0.5 text-[11px] font-semibold text-text-secondary">
          {provider.role === 'primary' ? copy.primary : copy.alternative}
        </span>
        {provider.is_primary && (
          <span data-testid={`payment-primary-${provider.provider}`} className="flex items-center gap-1 rounded-lg bg-brand-primary-soft px-2 py-0.5 text-[11px] font-semibold text-brand-700">
            <Star size={11} /> {copy.isPrimary}
          </span>
        )}
        <span data-testid={`payment-status-${provider.provider}`} className={`ms-auto rounded-lg px-2.5 py-1 text-xs font-semibold ${statusTone}`}>
          {copy.status[provider.status] ?? provider.status}
        </span>
      </div>

      {provider.implemented ? (
        <p data-testid={`payment-environment-${provider.provider}`} className="text-xs font-semibold text-text-secondary">
          {provider.mode === 'live' ? copy.modeLive : provider.mode === 'test' ? copy.modeTest : copy.noKeys}
          {provider.source !== 'none' && (
            <span className="ms-1.5 font-normal text-text-muted">· {provider.source === 'console' ? copy.sourceConsole : copy.sourceEnv}</span>
          )}
        </p>
      ) : (
        <p className="flex items-center gap-1.5 text-xs text-text-muted"><AlertTriangle size={13} className="shrink-0" /> {copy.notImplemented}</p>
      )}

      {provider.methods.length > 0 && (
        <p data-testid={`payment-methods-${provider.provider}`} className="text-xs text-text-muted">
          {copy.methods}: <span dir="ltr">{provider.methods.join(' · ')}</span>
        </p>
      )}

      {provider.test.status && (
        <p data-testid={`payment-last-test-${provider.provider}`} data-current={provider.test.current} className="text-xs text-text-secondary">
          {copy.lastTest}: <span className={provider.test.status === 'passed' ? 'text-[var(--positive-foreground)]' : 'text-danger'}>
            {provider.test.status === 'passed' ? copy.reachable : copy.unreachable}
          </span>
          {provider.test.at && <span className="ms-1 text-text-muted" dir="ltr">{new Date(provider.test.at).toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB')}</span>}
          {provider.test.message && <span className="ms-1 text-text-muted" dir="ltr">— {provider.test.message}</span>}
          {provider.test.status === 'passed' && !provider.test.current && <span className="block text-danger">{copy.stale}</span>}
        </p>
      )}

      {provider.implemented && (
        <div className="flex flex-wrap gap-2">
          <Button data-testid={`payment-setup-${provider.provider}`} size="sm" variant="secondary" onClick={() => setPanel(panel === 'setup' ? null : 'setup')}>
            <Settings2 size={13} /> {panel === 'setup' ? copy.close : copy.setup}
          </Button>
          <Button
            data-testid={`payment-test-${provider.provider}`}
            size="sm"
            variant="secondary"
            disabled={provider.status === 'awaiting_credentials' || test.isPending}
            onClick={() => test.mutate()}
          >
            {test.isPending ? copy.testing : copy.test}
          </Button>
          {provider.enabled ? (
            !provider.is_primary && (
              <Button data-testid={`payment-disable-${provider.provider}`} size="sm" variant="secondary" disabled={activation.isPending} onClick={() => activation.mutate(false)}>
                {copy.disable}
              </Button>
            )
          ) : (
            <Button
              data-testid={`payment-enable-${provider.provider}`}
              size="sm"
              disabled={provider.status !== 'ready' || activation.isPending}
              onClick={() => { if (provider.mode !== 'live' || window.confirm(copy.confirmLive)) activation.mutate(true) }}
            >
              {copy.enable}
            </Button>
          )}
          {provider.enabled && !provider.is_primary && (
            <Button
              data-testid={`payment-make-primary-${provider.provider}`}
              size="sm"
              disabled={primary.isPending}
              onClick={() => { if (window.confirm(copy.confirmPrimary)) primary.mutate() }}
            >
              <Star size={13} /> {copy.makePrimary}
            </Button>
          )}
          <button type="button" data-testid={`payment-webhook-${provider.provider}`} onClick={() => setPanel(panel === 'webhook' ? null : 'webhook')}
            className="flex items-center gap-1.5 rounded-xl border border-border px-3 py-1.5 text-xs font-semibold text-text-secondary hover:text-text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600">
            <Webhook size={13} /> {copy.webhook}
          </button>
          <button type="button" data-testid={`payment-rotation-${provider.provider}`} onClick={() => setPanel(panel === 'rotation' ? null : 'rotation')}
            className="flex items-center gap-1.5 rounded-xl border border-border px-3 py-1.5 text-xs font-semibold text-text-secondary hover:text-text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-600">
            <KeyRound size={13} /> {copy.rotation}
          </button>
        </div>
      )}

      {test.data && (
        <p data-testid={`payment-test-result-${provider.provider}`} data-reachable={test.data.reachable}
          className={`text-xs font-semibold ${test.data.reachable ? 'text-[var(--positive-foreground)]' : 'text-danger'}`}>
          {test.data.reachable ? copy.reachable : copy.unreachable}
          {test.data.error && <span className="ms-1 font-normal text-text-muted" dir="ltr">{test.data.error}</span>}
        </p>
      )}
      {test.isError && (
        <p data-testid={`payment-test-result-${provider.provider}`} className="text-xs font-semibold text-danger">{toApiError(test.error).message}</p>
      )}
      {actionError && (
        <p data-testid={`payment-action-error-${provider.provider}`} className="text-xs font-semibold text-danger">{toApiError(actionError).message}</p>
      )}

      {panel === 'setup' && <SetupForm provider={provider} ar={ar} copy={copy} onSaved={refresh} />}

      {panel === 'webhook' && (
        <div data-testid={`payment-webhook-panel-${provider.provider}`} className="min-w-0 rounded-xl bg-surface-secondary p-3 text-xs">
          <div className="flex items-start gap-2">
            <p className="min-w-0 flex-1 break-all font-mono text-text-primary" dir="ltr">{provider.webhook_url}</p>
            <button type="button" data-testid={`payment-webhook-copy-${provider.provider}`} onClick={() => void copyUrl()}
              className="flex shrink-0 items-center gap-1 rounded-lg border border-border px-2 py-1 font-semibold text-text-secondary hover:text-text-primary">
              {copied ? <Check size={12} /> : <Copy size={12} />} {copied ? copy.copied : copy.copy}
            </button>
          </div>
          <p className="mt-1.5 text-text-secondary">{provider.webhook.scheme}</p>
          {provider.webhook.events.length > 0 && <p className="mt-1.5 text-text-muted" dir="ltr">{provider.webhook.events.join(' · ')}</p>}
        </div>
      )}

      {panel === 'rotation' && rotation.data && (
        <div data-testid={`payment-rotation-panel-${provider.provider}`} className="rounded-xl bg-surface-secondary p-3 text-xs">
          <p className="font-mono text-text-primary" dir="ltr">{rotation.data.fields.join(' · ')}</p>
          <ol className="mt-1.5 flex list-decimal flex-col gap-1 ps-4 text-text-secondary">
            {rotation.data.steps.map((s) => <li key={s}>{s}</li>)}
          </ol>
          <p className="mt-1.5 font-semibold text-text-muted">{rotation.data.note}</p>
        </div>
      )}
    </section>
  )
}

/** Only the fields the gateway's adapter reads. A stored secret is never shown — the box says it is stored and stays empty. */
function SetupForm({ provider, ar, copy, onSaved }: { provider: PaymentProviderSetting; ar: boolean; copy: Copy; onSaved: () => void }) {
  const [values, setValues] = useState<Record<string, string>>({})
  const [mode, setMode] = useState<'test' | 'live'>(provider.mode ?? 'test')
  const save = useMutation({
    mutationFn: () => savePaymentGateway(provider.provider, { ...values, mode }),
    onSuccess: () => { setValues({}); onSaved() },
  })

  return (
    <form
      data-testid={`payment-setup-form-${provider.provider}`}
      className="flex flex-col gap-3 rounded-xl border border-border p-3"
      onSubmit={(e) => { e.preventDefault(); save.mutate() }}
    >
      {provider.fields.map((f) => (
        <label key={f.key} className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
          <span>
            {ar ? f.label.ar : f.label.en}
            {!f.required && <span className="ms-1 font-normal text-text-muted">({copy.optional})</span>}
          </span>
          <input
            data-testid={`payment-field-${provider.provider}-${f.key}`}
            type={f.secret ? 'password' : 'text'}
            autoComplete="off"
            spellCheck={false}
            dir="ltr"
            value={values[f.key] ?? ''}
            placeholder={f.present ? (f.secret ? copy.stored : (f.value ?? copy.stored)) : copy.notStored}
            onChange={(e) => setValues((v) => ({ ...v, [f.key]: e.target.value }))}
            className="min-w-0 rounded-lg border border-border bg-surface px-2.5 py-1.5 font-mono text-sm font-normal text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600"
          />
        </label>
      ))}

      <fieldset className="flex flex-wrap items-center gap-3 text-xs">
        <legend className="mb-1 font-semibold text-text-secondary">{copy.mode}</legend>
        {(['test', 'live'] as const).map((m) => (
          <label key={m} className="flex items-center gap-1.5 text-text-primary">
            <input data-testid={`payment-mode-${provider.provider}-${m}`} type="radio" name={`mode-${provider.provider}`} checked={mode === m} onChange={() => setMode(m)} />
            {m === 'live' ? copy.modeLive : copy.modeTest}
          </label>
        ))}
      </fieldset>

      <div className="flex items-center gap-2">
        <Button data-testid={`payment-save-${provider.provider}`} type="submit" size="sm" disabled={save.isPending}>
          {save.isPending ? copy.saving : copy.save}
        </Button>
        {save.data && <span data-testid={`payment-saved-${provider.provider}`} className="text-xs text-text-muted">{save.data.changed.length > 0 ? copy.saved : copy.nothing}</span>}
        {save.isError && <span data-testid={`payment-save-error-${provider.provider}`} className="text-xs font-semibold text-danger">{toApiError(save.error).message}</span>}
      </div>
    </form>
  )
}
