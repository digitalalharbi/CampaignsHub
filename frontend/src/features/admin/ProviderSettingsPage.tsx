import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle, CheckCircle2, Circle, Copy, ExternalLink, Loader2, PauseCircle, PlayCircle, ShieldCheck, Trash2,
} from 'lucide-react'
import {
  fetchIntegrationProviders, forgetIntegrationCredential, saveIntegrationProvider,
  setIntegrationProviderEnabled, testIntegrationProvider,
  type IntegrationProvider, type ProviderSetupState,
} from './api'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * PROVCFG-001 — the platform operator's provider configuration.
 *
 * ## This page is one half of a boundary, and the other half is `/app/integrations`
 *
 *     system provider configuration  →  user OAuth consent  →  external account  →  client  →  project
 *
 * Everything to the left of the first arrow is here. Nothing here is visible to a customer, and
 * nothing on the customer's page can reach it. A tenant never enters a client secret, never sees one,
 * and never registers a redirect URI — they press "connect", authorise at the provider, and choose
 * which of THEIR accounts belongs to which project.
 *
 * ## One primary action per row, and the detail behind it
 *
 * The list answers one question — which providers are ready and which need something — and the only
 * action on a row is «إعداد», which opens the dialog. Testing, rotating, disabling and clearing are
 * all inside it, because they are things you do to a provider you have already opened, not choices to
 * be scanned nine at a time.
 *
 * ## A secret field is write-only, and the page says so rather than pretending
 *
 * The inputs are always empty when the dialog opens. A stored value shows as «مُعرَّف · ••••1234»
 * beside the field. Nothing here can display a value, so an operator who has lost one rotates it at
 * the provider — which is the correct answer regardless of what this page offered.
 */

const COPY = {
  ar: {
    title: 'مزوّدو التكامل',
    subtitle: 'إعداد تطبيقات OAuth الخاصة بالمنصة. هذه المفاتيح للنظام ولا يراها أي عميل. العملاء يربطون حساباتهم بأنفسهم من صفحة التكاملات لديهم.',
    advertising: 'منصات الإعلانات', commerce: 'المتاجر',
    ready: 'جاهز للربط', total: 'المزوّدون', attention: 'يحتاج انتباهًا',
    configure: 'إعداد', close: 'إغلاق', save: 'حفظ', saving: 'جارٍ الحفظ…',
    test: 'اختبار الإعداد', testing: 'جارٍ الاختبار…',
    disable: 'تعطيل المزوّد', enable: 'تفعيل المزوّد', disabled: 'معطّل',
    disableReason: 'سبب التعطيل', disableHint: 'يُسجَّل في سجل التدقيق. التعطيل لا يحذف أي مفتاح ولا أي ربط ولا أي رقم متزامن.',
    clear: 'حذف القيمة',
    environment: 'البيئة', sandbox: 'اختبار (Sandbox)', production: 'إنتاج (Production)',
    stored: 'مُعرَّف', fromEnv: 'من بيئة التشغيل', notSet: 'غير مُعرَّف',
    required: 'مطلوب', optional: 'اختياري', secretHint: 'لا يمكن عرض القيمة بعد الحفظ. اترك الحقل فارغًا لإبقائها كما هي.',
    redirect: 'رابط العودة (Redirect URI)', webhook: 'رابط الـ Webhook',
    copy: 'نسخ', copied: 'تم النسخ',
    prerequisites: 'متطلبات خارج المنصة', tokens: 'الرموز والتجديد', limits: 'الحدود والصفحات',
    docs: 'التوثيق الرسمي', scopes: 'الصلاحيات المطلوبة',
    pkce: 'يستخدم PKCE إلزاميًا', noRefresh: 'لا يوجد تجديد تلقائي',
    webhookSupported: 'يدعم استقبال الأحداث', webhookPolling: 'لا يدعم الأحداث — نعتمد المزامنة الدورية',
    webhookUnconfirmed: 'المزامنة الدورية هي المرجع حتى تأكيد آلية الأحداث لدى المزوّد',
    lastTested: 'آخر اختبار', neverTested: 'لم يُختبر بعد',
    missing: 'ينقص',
    states: {
      not_configured: 'غير مهيأ',
      awaiting_credentials: 'بانتظار بيانات الاعتماد',
      ready_to_connect: 'جاهز للربط',
      configuration_error: 'خطأ إعداد',
      production_ready: 'جاهز للإنتاج',
    } as Record<ProviderSetupState, string>,
  },
  en: {
    title: 'Integration providers',
    subtitle: "The platform's own OAuth apps. These keys belong to the system and no customer ever sees them — customers connect their own accounts from their integrations page.",
    advertising: 'Advertising platforms', commerce: 'Stores',
    ready: 'Ready to connect', total: 'Providers', attention: 'Needs attention',
    configure: 'Configure', close: 'Close', save: 'Save', saving: 'Saving…',
    test: 'Test configuration', testing: 'Testing…',
    disable: 'Disable provider', enable: 'Enable provider', disabled: 'Disabled',
    disableReason: 'Why', disableHint: 'Recorded in the audit trail. Disabling deletes no key, no connection and no synced figure.',
    clear: 'Clear value',
    environment: 'Environment', sandbox: 'Sandbox', production: 'Production',
    stored: 'Set', fromEnv: 'From the environment', notSet: 'Not set',
    required: 'Required', optional: 'Optional', secretHint: 'The value cannot be shown again once saved. Leave empty to keep it.',
    redirect: 'Redirect URI', webhook: 'Webhook URL',
    copy: 'Copy', copied: 'Copied',
    prerequisites: 'Required outside this product', tokens: 'Tokens and refresh', limits: 'Limits and pagination',
    docs: 'Official documentation', scopes: 'Scopes requested',
    pkce: 'PKCE is mandatory', noRefresh: 'No automatic refresh',
    webhookSupported: 'Receives events', webhookPolling: 'No events — the scheduled sync is the source',
    webhookUnconfirmed: 'The scheduled sync stays authoritative until the delivery scheme is confirmed',
    lastTested: 'Last tested', neverTested: 'Never tested',
    missing: 'Missing',
    states: {
      not_configured: 'Not configured',
      awaiting_credentials: 'Awaiting credentials',
      ready_to_connect: 'Ready to connect',
      configuration_error: 'Configuration error',
      production_ready: 'Production ready',
    } as Record<ProviderSetupState, string>,
  },
} as const

type Copy = typeof COPY['en'] | typeof COPY['ar']

/**
 * One tone per state. `ready_to_connect` is deliberately NOT green: it means a complete form and no
 * proof, and a green badge there is how a configuration nobody has tested gets treated as finished.
 */
const STATE_TONE: Record<ProviderSetupState, string> = {
  not_configured: 'bg-surface-secondary text-text-muted',
  awaiting_credentials: 'bg-[var(--warning-background)] text-[var(--warning-foreground)]',
  ready_to_connect: 'bg-brand-primary-soft text-brand-700',
  configuration_error: 'bg-[var(--danger-background)] text-danger',
  production_ready: 'bg-[var(--positive-background)] text-[var(--positive-foreground)]',
}

export function ProviderSettingsPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const c = COPY[ar ? 'ar' : 'en']
  const [open, setOpen] = useState<string | null>(null)

  const query = useQuery({ queryKey: ['admin', 'integration-providers'], queryFn: fetchIntegrationProviders })

  const groups = useMemo(() => {
    const providers = query.data?.providers ?? []
    return [
      { kind: 'advertising' as const, label: c.advertising, items: providers.filter((p) => p.kind === 'advertising') },
      { kind: 'commerce' as const, label: c.commerce, items: providers.filter((p) => p.kind === 'commerce') },
    ]
  }, [query.data, c])

  if (query.isPending) {
    return <div className="flex justify-center p-10"><Loader2 className="animate-spin text-brand-600" /></div>
  }

  if (query.isError || !query.data) {
    return <p className="text-sm text-danger">{toApiError(query.error).message}</p>
  }

  const active = query.data.providers.find((p) => p.key === open) ?? null

  return (
    <div data-testid="admin-provider-settings" className="flex flex-col gap-5">
      <header>
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">{c.title}</h1>
        <p className="mt-1 max-w-3xl text-sm text-text-secondary">{c.subtitle}</p>
      </header>

      <dl className="grid grid-cols-3 gap-3">
        <Stat label={c.total} value={query.data.summary.total} />
        <Stat label={c.ready} value={query.data.summary.connectable} />
        <Stat label={c.attention} value={query.data.summary.needs_attention} tone={query.data.summary.needs_attention > 0} />
      </dl>

      {groups.map((group) => (
        <section key={group.kind} className="flex flex-col gap-2">
          <h2 className="text-xs font-bold uppercase tracking-wide text-text-muted">{group.label}</h2>
          <ul className="grid gap-2.5 lg:grid-cols-2">
            {group.items.map((p) => (
              <li key={p.key}>
                <ProviderRow provider={p} ar={ar} copy={c} onConfigure={() => setOpen(p.key)} />
              </li>
            ))}
          </ul>
        </section>
      ))}

      {active && (
        <ProviderDialog provider={active} ar={ar} copy={c} onClose={() => setOpen(null)} />
      )}
    </div>
  )
}

function Stat({ label, value, tone = false }: { label: string; value: number; tone?: boolean }) {
  return (
    <div className="rounded-2xl border border-border bg-surface p-3">
      <dt className="text-xs font-semibold text-text-muted">{label}</dt>
      <dd className={`mt-0.5 font-heading text-2xl font-extrabold ${tone ? 'text-danger' : 'text-text-primary'}`}>{value}</dd>
    </div>
  )
}

function ProviderRow({ provider, ar, copy, onConfigure }: {
  provider: IntegrationProvider
  ar: boolean
  copy: Copy
  onConfigure: () => void
}) {
  return (
    <div
      data-testid={`provider-row-${provider.key}`}
      data-state={provider.state}
      data-enabled={provider.enabled}
      className="flex h-full flex-col gap-2 rounded-2xl border border-border bg-surface p-4"
    >
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-bold text-text-primary">{ar ? provider.label_ar : provider.label}</span>
        {!provider.enabled && (
          <span className="rounded-lg bg-surface-secondary px-2 py-0.5 text-[11px] font-semibold text-text-muted">
            {copy.disabled}
          </span>
        )}
        <span
          data-testid={`provider-state-${provider.key}`}
          className={`ms-auto rounded-lg px-2.5 py-1 text-xs font-semibold ${STATE_TONE[provider.state]}`}
        >
          {copy.states[provider.state]}
        </span>
      </div>

      {/* Exactly which piece is absent, rather than "not configured" — the difference between a
          complaint and an instruction. */}
      {provider.missing.length > 0 && (
        <p className="text-xs text-text-secondary">
          {copy.missing}:{' '}
          <span className="font-mono" dir="ltr">{provider.missing.join(' · ')}</span>
        </p>
      )}

      {provider.state === 'configuration_error' && provider.last_test_message && (
        <p className="flex items-start gap-1.5 text-xs text-danger">
          <AlertTriangle size={13} className="mt-0.5 shrink-0" />
          <span dir="ltr" className="break-words">{provider.last_test_message}</span>
        </p>
      )}

      <div className="mt-auto pt-1">
        <Button data-testid={`provider-configure-${provider.key}`} size="sm" variant="secondary" onClick={onConfigure}>
          {copy.configure}
        </Button>
      </div>
    </div>
  )
}

function ProviderDialog({ provider, ar, copy, onClose }: {
  provider: IntegrationProvider
  ar: boolean
  copy: Copy
  onClose: () => void
}) {
  const client = useQueryClient()
  const [draft, setDraft] = useState<Record<string, string>>({})
  const [environment, setEnvironment] = useState(provider.environment)
  const [reason, setReason] = useState('')
  const [copiedKey, setCopiedKey] = useState<string | null>(null)

  const refresh = () => client.invalidateQueries({ queryKey: ['admin', 'integration-providers'] })

  const save = useMutation({
    mutationFn: () => saveIntegrationProvider(provider.key, { ...draft, environment }),
    onSuccess: () => { setDraft({}); void refresh() },
  })
  const test = useMutation({ mutationFn: () => testIntegrationProvider(provider.key), onSuccess: refresh })
  const toggle = useMutation({
    mutationFn: () => setIntegrationProviderEnabled(provider.key, !provider.enabled, reason || undefined),
    onSuccess: () => { setReason(''); void refresh() },
  })
  const forget = useMutation({
    mutationFn: (key: string) => forgetIntegrationCredential(provider.key, key),
    onSuccess: refresh,
  })

  const copyToClipboard = (key: string, value: string) => {
    void navigator.clipboard?.writeText(value)
    setCopiedKey(key)
  }

  const prerequisites = ar ? provider.prerequisites_ar : provider.prerequisites

  return (
    <Modal open onClose={onClose} size="lg" title={ar ? provider.label_ar : provider.label}>
      <div data-testid={`provider-dialog-${provider.key}`} className="flex flex-col gap-4">
        {/*
          The prerequisites come FIRST, above the form. They are the reason a perfectly correct key
          still fails — an unapproved app, an ungranted developer token, an unverified business — and
          an operator who reads them after filling in four fields has already wasted the afternoon.
        */}
        <section className="rounded-xl border border-border-strong bg-surface-secondary p-3">
          <p className="text-xs font-bold uppercase tracking-wide text-text-muted">{copy.prerequisites}</p>
          <ul className="mt-1.5 flex list-disc flex-col gap-1 ps-4 text-xs text-text-secondary">
            {prerequisites.map((p) => <li key={p}>{p}</li>)}
          </ul>
          <a
            href={provider.docs_url}
            target="_blank"
            rel="noreferrer noopener"
            className="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-brand-700 hover:underline"
          >
            {copy.docs} <ExternalLink size={12} />
          </a>
        </section>

        {/* Copied into the provider's console, which is the only place they have to match. */}
        <section className="flex flex-col gap-2">
          <CopyRow
            label={copy.redirect} value={provider.redirect_uri}
            copied={copiedKey === 'redirect'} copyLabel={copy.copy} copiedLabel={copy.copied}
            onCopy={() => copyToClipboard('redirect', provider.redirect_uri)}
          />
          {provider.webhook_url && (
            <CopyRow
              label={copy.webhook} value={provider.webhook_url}
              copied={copiedKey === 'webhook'} copyLabel={copy.copy} copiedLabel={copy.copied}
              onCopy={() => copyToClipboard('webhook', provider.webhook_url ?? '')}
            />
          )}
          <p data-testid={`provider-webhooks-${provider.key}`} className="text-xs text-text-muted">
            {provider.webhooks === 'supported'
              ? `${copy.webhookSupported}${provider.webhook_signature_header ? ` · ${provider.webhook_signature_header}` : ''}`
              : provider.webhooks === 'polling_only' ? copy.webhookPolling : copy.webhookUnconfirmed}
          </p>
        </section>

        {/* The form. Every input starts empty, including for a value that is already stored. */}
        <section className="flex flex-col gap-3">
          {provider.fields.map((field) => {
            const state = provider.values.find((v) => v.key === field.key)
            return (
              <div key={field.key} className="flex flex-col gap-1">
                <label className="flex flex-wrap items-center gap-2 text-xs font-semibold text-text-primary" htmlFor={`f-${field.key}`}>
                  {ar ? field.label_ar : field.label}
                  <span className="text-[11px] font-normal text-text-muted">
                    {field.required ? copy.required : copy.optional}
                  </span>
                  <span
                    data-testid={`provider-field-state-${provider.key}-${field.key}`}
                    data-present={state?.present ?? false}
                    className="ms-auto text-[11px] font-normal text-text-muted"
                  >
                    {state?.present
                      ? `${state.source === 'environment' ? copy.fromEnv : copy.stored} · ••••${state.hint ?? ''}`
                      : copy.notSet}
                  </span>
                </label>
                <div className="flex items-center gap-1.5">
                  <input
                    id={`f-${field.key}`}
                    data-testid={`provider-input-${provider.key}-${field.key}`}
                    type={field.secret ? 'password' : 'text'}
                    autoComplete="off"
                    dir="ltr"
                    value={draft[field.key] ?? ''}
                    onChange={(e) => setDraft((d) => ({ ...d, [field.key]: e.target.value }))}
                    className="w-full rounded-xl border border-border bg-surface px-3 py-2 font-mono text-sm text-text-primary"
                  />
                  {state?.source === 'stored' && (
                    <button
                      type="button"
                      aria-label={copy.clear}
                      data-testid={`provider-clear-${provider.key}-${field.key}`}
                      onClick={() => forget.mutate(field.key)}
                      className="shrink-0 rounded-xl border border-border p-2 text-text-muted hover:text-danger"
                    >
                      <Trash2 size={14} />
                    </button>
                  )}
                </div>
                <p className="text-[11px] text-text-muted">{ar ? field.where_ar : field.where}</p>
              </div>
            )
          })}
          <p className="text-[11px] text-text-muted">{copy.secretHint}</p>
        </section>

        <section className="flex flex-wrap items-center gap-2">
          <span className="text-xs font-semibold text-text-primary">{copy.environment}</span>
          {(['sandbox', 'production'] as const).map((env) => (
            <button
              key={env}
              type="button"
              data-testid={`provider-env-${provider.key}-${env}`}
              aria-pressed={environment === env}
              onClick={() => setEnvironment(env)}
              className={`rounded-xl border px-3 py-1.5 text-xs font-semibold ${
                environment === env ? 'border-brand-600 bg-brand-primary-soft text-brand-700' : 'border-border text-text-secondary'
              }`}
            >
              {env === 'sandbox' ? copy.sandbox : copy.production}
            </button>
          ))}
        </section>

        {/* What the provider's own quirks mean for this configuration. */}
        <section className="rounded-xl bg-surface-secondary p-3 text-xs text-text-secondary">
          <p className="font-bold text-text-primary">{copy.tokens}</p>
          <p className="mt-1">{ar ? provider.token_note_ar : provider.token_note}</p>
          <p className="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-text-muted">
            {provider.uses_pkce && <span>{copy.pkce}</span>}
            {!provider.supports_refresh && <span>{copy.noRefresh}</span>}
            {provider.effective_scopes.length > 0 && (
              <span dir="ltr">{copy.scopes}: {provider.effective_scopes.join(' ')}</span>
            )}
          </p>
          <p className="mt-1.5 font-bold text-text-primary">{copy.limits}</p>
          <p className="mt-1" dir="ltr">{provider.rate_limit_note}</p>
          <p dir="ltr">{provider.pagination_note}</p>
        </section>

        {/* The verdict, with the provider's own words. A pass says what it proves and no more. */}
        <section data-testid={`provider-test-${provider.key}`} data-status={provider.last_test_status ?? 'none'}>
          <p className="flex items-center gap-1.5 text-xs font-semibold">
            {provider.last_test_status === 'passed'
              ? <CheckCircle2 size={14} className="text-[var(--positive-foreground)]" />
              : provider.last_test_status === 'failed'
                ? <AlertTriangle size={14} className="text-danger" />
                : <Circle size={14} className="text-text-muted" />}
            <span className="text-text-primary">
              {provider.last_tested_at ? `${copy.lastTested}: ${new Date(provider.last_tested_at).toLocaleString(ar ? 'ar-SA-u-nu-latn' : 'en-GB')}` : copy.neverTested}
            </span>
          </p>
          {provider.last_test_message && (
            <p className="mt-1 text-xs text-text-secondary" dir="ltr">{provider.last_test_message}</p>
          )}
        </section>

        {(save.isError || test.isError || toggle.isError || forget.isError) && (
          <p data-testid={`provider-error-${provider.key}`} className="text-xs font-semibold text-danger">
            {toApiError(save.error ?? test.error ?? toggle.error ?? forget.error).message}
          </p>
        )}

        <footer className="flex flex-wrap items-center gap-2 border-t border-border pt-3">
          <Button
            data-testid={`provider-save-${provider.key}`}
            size="sm"
            disabled={save.isPending}
            onClick={() => save.mutate()}
          >
            {save.isPending ? copy.saving : copy.save}
          </Button>
          <Button
            data-testid={`provider-test-run-${provider.key}`}
            size="sm"
            variant="secondary"
            disabled={test.isPending || provider.missing.length > 0}
            onClick={() => test.mutate()}
          >
            <ShieldCheck size={14} /> {test.isPending ? copy.testing : copy.test}
          </Button>

          <div className="ms-auto flex flex-wrap items-center gap-2">
            {provider.enabled && (
              <input
                data-testid={`provider-disable-reason-${provider.key}`}
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder={copy.disableReason}
                className="w-40 rounded-xl border border-border bg-surface px-2.5 py-1.5 text-xs text-text-primary"
              />
            )}
            <button
              type="button"
              data-testid={`provider-toggle-${provider.key}`}
              disabled={toggle.isPending || (provider.enabled && reason.trim().length < 3)}
              onClick={() => toggle.mutate()}
              className="flex items-center gap-1.5 rounded-xl border border-border px-3 py-1.5 text-xs font-semibold text-text-secondary hover:text-text-primary disabled:opacity-50"
            >
              {provider.enabled ? <PauseCircle size={14} /> : <PlayCircle size={14} />}
              {provider.enabled ? copy.disable : copy.enable}
            </button>
          </div>
        </footer>
        {provider.enabled && <p className="text-[11px] text-text-muted">{copy.disableHint}</p>}
      </div>
    </Modal>
  )
}

function CopyRow({ label, value, copied, copyLabel, copiedLabel, onCopy }: {
  label: string
  value: string
  copied: boolean
  copyLabel: string
  copiedLabel: string
  onCopy: () => void
}) {
  return (
    <div className="flex items-center gap-2 rounded-xl border border-border bg-surface-secondary px-3 py-2">
      <div className="min-w-0 flex-1">
        <p className="text-[11px] font-semibold text-text-muted">{label}</p>
        <p className="truncate font-mono text-xs text-text-primary" dir="ltr">{value}</p>
      </div>
      <button
        type="button"
        onClick={onCopy}
        className="flex shrink-0 items-center gap-1 rounded-lg border border-border px-2 py-1 text-[11px] font-semibold text-text-secondary hover:text-text-primary"
      >
        <Copy size={12} /> {copied ? copiedLabel : copyLabel}
      </button>
    </div>
  )
}
