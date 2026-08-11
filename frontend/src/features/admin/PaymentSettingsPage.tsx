import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { AlertTriangle, Check, CreditCard, KeyRound, Loader2, Webhook, X } from 'lucide-react'
import {
  fetchPaymentSettings, fetchPaymentWebhook, fetchSecretRotation, testPaymentProvider,
  type PaymentProviderSetting,
} from './api'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * The payment gateways, from the platform owner's console (PAYSET-001).
 *
 * Deliberately has no field for a secret key. A console able to change a gateway secret is a console
 * whose compromise redirects every customer payment, and the rotation an operator actually needs is
 * at the provider, then in the environment, then a restart. This page tells them WHAT the environment
 * currently supports and WHAT is missing, which is the question they have.
 *
 * The distinction it exists to make visible: `live`, `sandbox` and `awaiting_credentials` are three
 * different states, and a half-configured provider is shown as unusable rather than as an option
 * somebody could select and have fail silently.
 */

const COPY = {
  ar: {
    title: 'وسائل الدفع',
    subtitle: 'ميسر هي البوابة الرسمية والأساسية، وسترايب بوابة بديلة. المفاتيح تُدار من بيئة التشغيل ولا تُخزَّن هنا.',
    primary: 'المزوّد الأساسي', alternative: 'مزوّد بديل', isDefault: 'الافتراضي',
    live: 'مفعّل', awaiting: 'بانتظار بيانات الاعتماد',
    envSandbox: 'بيئة اختبار (Sandbox)', envLive: 'بيئة إنتاج (Live)', envUnset: 'لا توجد مفاتيح',
    required: 'المتطلبات', present: 'موجود', missing: 'ناقص',
    test: 'اختبار الاتصال', testing: 'جارٍ الاختبار…',
    reachable: 'البوابة استجابت بنجاح.', unreachable: 'البوابة رفضت الطلب.',
    cannotTest: 'لا يمكن الاختبار قبل إدخال بيانات الاعتماد.',
    webhook: 'إعدادات Webhook', rotation: 'تدوير الأسرار',
    mailTitle: 'قناة الإشعارات',
    mailSandbox: 'مزوّد محلي (Sandbox) — الرسائل تُكتب ولا تصل إلى أحد.',
    mailAwaiting: 'لا يوجد مزوّد بريد — لن تصل أي إشعارات.',
    mailLive: 'مزوّد بريد مفعّل.',
    recurringTitle: 'التجديد التلقائي',
    recurringReady: 'البوابة الحالية تستطيع خصم التجديد من بطاقة محفوظة.',
    recurringNoGateway: 'لا توجد بوابة دفع مهيّأة، لذلك كل تجديد يصل كفاتورة يسدّدها العميل بنفسه.',
    recurringUnsupported: 'البوابة الحالية لا تدعم الخصم التلقائي، لذلك كل تجديد يصل كفاتورة يسدّدها العميل بنفسه.',
    recurringCards: 'البطاقات المحفوظة',
    noWrite: 'لا يمكن تغيير أي مفتاح من هذه الصفحة.',
  },
  en: {
    title: 'Payment methods',
    subtitle: 'Moyasar is the official, primary gateway; Stripe is the alternative. Keys are managed in the environment and are never stored here.',
    primary: 'Primary provider', alternative: 'Alternative provider', isDefault: 'Default',
    live: 'Live', awaiting: 'Awaiting credentials',
    envSandbox: 'Sandbox keys', envLive: 'Live keys', envUnset: 'No keys',
    required: 'Requires', present: 'present', missing: 'missing',
    test: 'Test connection', testing: 'Testing…',
    reachable: 'The gateway accepted the request.', unreachable: 'The gateway refused the request.',
    cannotTest: 'There is nothing to test until credentials are supplied.',
    webhook: 'Webhook setup', rotation: 'Secret rotation',
    mailTitle: 'Notification channel',
    mailSandbox: 'A local provider (sandbox) — messages are written and reach nobody.',
    mailAwaiting: 'No mail provider — no notification will reach anyone.',
    mailLive: 'A mail provider is configured.',
    recurringTitle: 'Automatic renewal',
    recurringReady: 'The current gateway can take a renewal from a card on file.',
    recurringNoGateway: 'No payment gateway is configured, so every renewal arrives as an invoice the customer pays themselves.',
    recurringUnsupported: 'The current gateway does not take automatic payments, so every renewal arrives as an invoice the customer pays themselves.',
    recurringCards: 'Cards on file',
    noWrite: 'No key can be changed from this page.',
  },
} as const

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

      <div className="grid gap-3 lg:grid-cols-2">
        {settings.data.providers.map((p) => (
          <ProviderCard key={p.provider} provider={p} ar={ar} copy={c} />
        ))}
      </div>

      {/*
        The mail transport belongs on this page: a payment system that cannot tell anybody a charge
        failed is only half configured, and an operator checking one will want the other.
      */}
      <section data-testid="payment-mail-state" data-state={mail.state} className="rounded-2xl border border-border bg-surface p-4">
        <p className="text-sm font-bold text-text-primary">{c.mailTitle}</p>
        <p className="mt-1 text-sm text-text-secondary">
          {mail.state === 'live' ? c.mailLive : mail.state === 'sandbox' ? c.mailSandbox : c.mailAwaiting}
          <span className="ms-1.5 text-xs text-text-muted" dir="ltr">({mail.driver})</span>
        </p>
      </section>

      {/*
        Whether renewals take themselves — PAY-TOKEN-003.

        Both numbers, deliberately. «Ready» says the gateway could charge a saved card; the count
        says how many customers have one. On a fresh install that is ready-and-zero, which is the
        honest state: nothing is renewing itself yet, and a single badge would have implied
        otherwise.
      */}
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

      <p className="text-xs text-text-muted">{c.noWrite}</p>
    </div>
  )
}

type Copy = typeof COPY['en'] | typeof COPY['ar']

function ProviderCard({ provider, ar, copy }: { provider: PaymentProviderSetting; ar: boolean; copy: Copy }) {
  const [panel, setPanel] = useState<'webhook' | 'rotation' | null>(null)

  const test = useMutation({ mutationFn: () => testPaymentProvider(provider.provider) })
  const webhook = useQuery({
    queryKey: ['admin', 'payment-webhook', provider.provider],
    queryFn: () => fetchPaymentWebhook(provider.provider),
    enabled: panel === 'webhook',
  })
  const rotation = useQuery({
    queryKey: ['admin', 'payment-rotation', provider.provider],
    queryFn: () => fetchSecretRotation(provider.provider),
    enabled: panel === 'rotation',
  })

  const environment = provider.environment === 'sandbox'
    ? copy.envSandbox
    : provider.environment === 'live' ? copy.envLive : copy.envUnset

  return (
    <section
      data-testid={`payment-provider-${provider.provider}`}
      data-status={provider.status}
      data-environment={provider.environment}
      className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-4"
    >
      <div className="flex flex-wrap items-center gap-2">
        <CreditCard size={18} className="shrink-0 text-brand-600" />
        <span className="text-sm font-bold text-text-primary">{ar ? provider.label.ar : provider.label.en}</span>
        <span className="rounded-lg bg-surface-secondary px-2 py-0.5 text-[11px] font-semibold text-text-secondary">
          {provider.role === 'primary' ? copy.primary : copy.alternative}
        </span>
        {provider.is_default && (
          <span className="rounded-lg bg-brand-primary-soft px-2 py-0.5 text-[11px] font-semibold text-brand-700">{copy.isDefault}</span>
        )}

        {/* Never "enabled": a provider with no credentials is shown as unusable, not as an option. */}
        <span
          data-testid={`payment-status-${provider.provider}`}
          className={`ms-auto rounded-lg px-2.5 py-1 text-xs font-semibold ${provider.available ? 'bg-[var(--positive-background)] text-[var(--positive-foreground)]' : 'bg-surface-secondary text-text-muted'}`}
        >
          {provider.available ? copy.live : copy.awaiting}
        </span>
      </div>

      {/* Read from the key itself — a toggle that could disagree with the key in use is how somebody
          ends up certain they are in sandbox while taking real money. */}
      <p data-testid={`payment-environment-${provider.provider}`} className="text-xs font-semibold text-text-secondary">
        {environment}
      </p>

      {/* Exactly which piece is missing, rather than "not configured". */}
      <div>
        <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-text-muted">{copy.required}</p>
        <ul className="flex flex-col gap-1">
          {provider.requires.map((r) => (
            <li key={r.key} data-testid={`payment-requirement-${r.key}`} data-present={r.present} className="flex items-center gap-1.5 text-xs">
              {r.present
                ? <Check size={13} className="shrink-0 text-[var(--positive-foreground)]" />
                : <X size={13} className="shrink-0 text-danger" />}
              <span className="font-mono text-text-secondary" dir="ltr">{r.key}</span>
              <span className="text-text-muted">— {r.present ? copy.present : copy.missing}</span>
            </li>
          ))}
        </ul>
      </div>

      <div className="flex flex-wrap gap-2">
        <Button
          data-testid={`payment-test-${provider.provider}`}
          size="sm"
          variant="secondary"
          disabled={!provider.available || test.isPending}
          onClick={() => test.mutate()}
        >
          {test.isPending ? copy.testing : copy.test}
        </Button>
        <button type="button" data-testid={`payment-webhook-${provider.provider}`} onClick={() => setPanel(panel === 'webhook' ? null : 'webhook')}
          className="flex items-center gap-1.5 rounded-xl border border-border px-3 py-1.5 text-xs font-semibold text-text-secondary hover:text-text-primary">
          <Webhook size={13} /> {copy.webhook}
        </button>
        <button type="button" data-testid={`payment-rotation-${provider.provider}`} onClick={() => setPanel(panel === 'rotation' ? null : 'rotation')}
          className="flex items-center gap-1.5 rounded-xl border border-border px-3 py-1.5 text-xs font-semibold text-text-secondary hover:text-text-primary">
          <KeyRound size={13} /> {copy.rotation}
        </button>
      </div>

      {/* Testing an unconfigured provider is refused rather than attempted: "we could not reach the
          gateway" and "you have not given us a key" are different problems with different fixes. */}
      {!provider.available && (
        <p className="flex items-center gap-1.5 text-xs text-text-muted">
          <AlertTriangle size={13} className="shrink-0" /> {copy.cannotTest}
        </p>
      )}

      {test.data && (
        <p data-testid={`payment-test-result-${provider.provider}`} data-reachable={test.data.reachable}
          className={`text-xs font-semibold ${test.data.reachable ? 'text-[var(--positive-foreground)]' : 'text-danger'}`}>
          {test.data.reachable ? copy.reachable : copy.unreachable}
          {/* The gateway's own words: a generic failure hides whether the key is wrong, the account
              is closed, or the currency is unsupported — three different fixes. */}
          {test.data.error && <span className="ms-1 font-normal text-text-muted" dir="ltr">{test.data.error}</span>}
        </p>
      )}
      {test.isError && (
        <p data-testid={`payment-test-result-${provider.provider}`} className="text-xs font-semibold text-danger">
          {toApiError(test.error).message}
        </p>
      )}

      {panel === 'webhook' && webhook.data && (
        <div data-testid={`payment-webhook-panel-${provider.provider}`} className="rounded-xl bg-surface-secondary p-3 text-xs">
          <p className="break-all font-mono text-text-primary" dir="ltr">{webhook.data.url}</p>
          <p className="mt-1.5 text-text-secondary">{webhook.data.authentication}</p>
          <p className="mt-1.5 text-text-muted" dir="ltr">{webhook.data.events.join(' · ')}</p>
        </div>
      )}

      {panel === 'rotation' && rotation.data && (
        <div data-testid={`payment-rotation-panel-${provider.provider}`} className="rounded-xl bg-surface-secondary p-3 text-xs">
          <p className="font-mono text-text-primary" dir="ltr">{rotation.data.variables.join(' · ')}</p>
          <ol className="mt-1.5 flex list-decimal flex-col gap-1 ps-4 text-text-secondary">
            {rotation.data.steps.map((s) => <li key={s}>{s}</li>)}
          </ol>
          <p className="mt-1.5 font-semibold text-text-muted">{rotation.data.note}</p>
        </div>
      )}
    </section>
  )
}
