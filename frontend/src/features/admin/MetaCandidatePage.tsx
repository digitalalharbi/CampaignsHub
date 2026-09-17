import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { CheckCircle2, Circle, Loader2, PlayCircle, Trash2, XCircle } from 'lucide-react'
import {
  fetchMetaCandidate, forgetMetaCandidateCredential, saveMetaCandidate, startMetaCandidateTest,
  type MetaCandidateRun, type MetaCandidateStep, type MetaCandidateStepKey,
} from './api'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * META-CANDIDATE-001 — the ONE isolated Candidate Meta app.
 *
 * A second Meta app (Facebook Login for Business) is proven here end to end before it may replace the
 * Live app: OAuth start → consent → token exchange → ad-account discovery → one real ads_read request.
 * Each step is shown as the server recorded it, with Meta's own code and fbtrace_id on a failure.
 *
 * Nothing on this page reads or writes the Live Meta app, a customer's connection, or their accounts.
 * The credential inputs are write-only, exactly like the provider console.
 */

const COPY = {
  ar: {
    title: 'تطبيق ميتا المرشّح',
    subtitle: 'تطبيق ميتا ثانٍ ومعزول لاختبار Facebook Login for Business قبل اعتماده. لا يمس تطبيق ميتا الحالي ولا ربط أي عميل ولا بياناته.',
    credentials: 'بيانات التطبيق المرشّح', save: 'حفظ', saving: 'جارٍ الحفظ…',
    client_id: 'معرّف التطبيق (App ID)', client_secret: 'سر التطبيق (App Secret)', config_id: 'معرّف الإعداد (Configuration ID)',
    scopes: 'الصلاحيات المطلوبة', scopesHint: 'مفصولة بفواصل. فارغ = ads_read فقط.',
    stored: 'مُعرَّف', environment: 'من البيئة', notSet: 'غير مُعرَّف', clear: 'مسح',
    writeOnly: 'الحقول للكتابة فقط؛ القيمة المحفوظة لا تُعرض أبدًا.',
    redirect: 'رابط العودة (يُضاف في Valid OAuth Redirect URIs)',
    missing: 'ينقص',
    run: 'تشغيل اختبار الربط', running: 'جارٍ التحويل إلى ميتا…',
    checklist: 'آخر اختبار', none: 'لم يُشغَّل أي اختبار بعد.',
    succeeded: 'نجح الاختبار كاملًا', failed: 'فشل الاختبار', started: 'بانتظار العودة من ميتا',
    accounts: 'حسابات مكتشفة', scopesGranted: 'الصلاحيات الممنوحة',
    steps: {
      oauth_start: 'بدء OAuth', consent: 'موافقة ميتا', token_exchange: 'استبدال الرمز',
      account_discovery: 'اكتشاف الحسابات الإعلانية (me/adaccounts)', ads_read: 'طلب ads_read حقيقي (آخر 7 أيام)',
    } as Record<MetaCandidateStepKey, string>,
    outcome: { succeeded: 'عاد الاختبار من ميتا بنجاح.', failed: 'عاد الاختبار من ميتا بفشل — التفاصيل أدناه.', invalid_state: 'رابط التفويض منتهٍ أو استُخدم من قبل.' } as Record<string, string>,
  },
  en: {
    title: 'Candidate Meta app',
    subtitle: 'A second, isolated Meta app to prove Facebook Login for Business before it is adopted. It does not touch the Live Meta app, any customer connection or their data.',
    credentials: 'Candidate app credentials', save: 'Save', saving: 'Saving…',
    client_id: 'App ID', client_secret: 'App Secret', config_id: 'Configuration ID',
    scopes: 'Scopes requested', scopesHint: 'Comma-separated. Empty = ads_read only.',
    stored: 'Set', environment: 'From environment', notSet: 'Not set', clear: 'Clear',
    writeOnly: 'Inputs are write-only; a stored value is never shown.',
    redirect: 'Redirect URI (add under Valid OAuth Redirect URIs)',
    missing: 'Missing',
    run: 'Run connection test', running: 'Sending you to Meta…',
    checklist: 'Latest test', none: 'No test has run yet.',
    succeeded: 'Test passed end to end', failed: 'Test failed', started: 'Waiting for the return from Meta',
    accounts: 'Accounts discovered', scopesGranted: 'Scopes granted',
    steps: {
      oauth_start: 'OAuth start', consent: 'Meta consent', token_exchange: 'Token exchange',
      account_discovery: 'Ad-account discovery (me/adaccounts)', ads_read: 'One real ads_read request (last 7 days)',
    } as Record<MetaCandidateStepKey, string>,
    outcome: { succeeded: 'The test came back from Meta and passed.', failed: 'The test came back from Meta and failed — details below.', invalid_state: 'The authorisation link expired or was already used.' } as Record<string, string>,
  },
}

type Copy = typeof COPY.en

export function MetaCandidatePage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const c: Copy = COPY[ar ? 'ar' : 'en']
  const [params] = useSearchParams()
  const outcome = params.get('outcome')
  const qc = useQueryClient()
  const query = useQuery({ queryKey: ['admin', 'meta-candidate'], queryFn: fetchMetaCandidate })

  const [form, setForm] = useState({ client_id: '', client_secret: '', config_id: '', scopes: '' })

  const save = useMutation({
    mutationFn: () => saveMetaCandidate({
      client_id: form.client_id || undefined,
      client_secret: form.client_secret || undefined,
      config_id: form.config_id || undefined,
      ...(form.scopes.trim() === '' ? {} : { scopes: form.scopes.split(',').map((s) => s.trim()).filter(Boolean) }),
    }),
    onSuccess: (data) => {
      setForm({ client_id: '', client_secret: '', config_id: '', scopes: '' })
      qc.setQueryData(['admin', 'meta-candidate'], data)
    },
  })

  const forget = useMutation({
    mutationFn: (key: string) => forgetMetaCandidateCredential(key),
    onSuccess: (data) => qc.setQueryData(['admin', 'meta-candidate'], data),
  })

  const start = useMutation({
    mutationFn: startMetaCandidateTest,
    onSuccess: (data) => { window.location.assign(data.authorization_url) },
  })

  if (query.isPending) {
    return <div className="flex justify-center p-10"><Loader2 className="animate-spin text-brand-600" /></div>
  }

  if (query.isError || !query.data) {
    return <p className="text-sm text-danger">{toApiError(query.error).message}</p>
  }

  const { credentials, latest_run: run } = query.data

  return (
    <div data-testid="admin-meta-candidate" className="flex flex-col gap-5">
      <header>
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">{c.title}</h1>
        <p className="mt-1 max-w-3xl text-sm text-text-secondary">{c.subtitle}</p>
      </header>

      {outcome && c.outcome[outcome] && (
        <p data-testid="meta-candidate-outcome" className={`rounded-xl border px-4 py-3 text-sm ${outcome === 'succeeded' ? 'border-border bg-brand-primary-soft text-brand-700' : 'border-border bg-[var(--danger-background)] text-danger'}`}>
          {c.outcome[outcome]}
        </p>
      )}

      <section className="rounded-2xl border border-border bg-surface p-5">
        <h2 className="font-heading text-[15px] font-bold text-text-primary">{c.credentials}</h2>
        <p className="mt-1 text-xs text-text-muted">{c.writeOnly}</p>

        <div className="mt-4 grid gap-3 md:grid-cols-3">
          {credentials.values.map((v) => (
            <label key={v.key} className="flex flex-col gap-1 text-sm">
              <span className="font-semibold text-text-primary">{c[v.key]}</span>
              <input
                data-testid={`meta-candidate-input-${v.key}`}
                type={v.secret ? 'password' : 'text'}
                autoComplete="off"
                dir="ltr"
                className="rounded-lg border border-border bg-surface px-3 py-2 text-sm"
                value={form[v.key]}
                onChange={(e) => setForm({ ...form, [v.key]: e.target.value })}
              />
              <span className="flex items-center gap-2 text-xs text-text-muted" dir="ltr">
                {v.present ? `${v.source === 'environment' ? c.environment : c.stored} · ••••${v.hint ?? ''}` : c.notSet}
                {v.source === 'stored' && (
                  <button type="button" className="inline-flex items-center gap-1 text-danger" onClick={() => forget.mutate(v.key)}>
                    <Trash2 size={12} aria-hidden /> {c.clear}
                  </button>
                )}
              </span>
            </label>
          ))}
        </div>

        <label className="mt-3 flex flex-col gap-1 text-sm">
          <span className="font-semibold text-text-primary">{c.scopes}</span>
          <input
            data-testid="meta-candidate-input-scopes"
            dir="ltr"
            className="rounded-lg border border-border bg-surface px-3 py-2 text-sm"
            placeholder={credentials.effective_scopes.join(', ')}
            value={form.scopes}
            onChange={(e) => setForm({ ...form, scopes: e.target.value })}
          />
          <span className="text-xs text-text-muted">{c.scopesHint}</span>
        </label>

        {/* The scopes the Candidate profile will actually request — read from the server, never assumed. */}
        <p data-testid="meta-candidate-effective-scopes" className="mt-3 text-xs text-text-secondary" dir="ltr">
          {c.scopes}: {credentials.effective_scopes.join(' ')}
        </p>
        <p className="mt-1 break-all text-xs text-text-secondary">
          {c.redirect}: <span dir="ltr" className="font-mono">{credentials.redirect_uri}</span>
        </p>
        {credentials.missing.length > 0 && (
          <p className="mt-1 text-xs text-danger" dir="ltr">{c.missing}: {credentials.missing.join(', ')}</p>
        )}
        {save.isError && <p className="mt-2 text-xs text-danger">{toApiError(save.error).message}</p>}

        <div className="mt-4 flex flex-wrap gap-2">
          <Button data-testid="meta-candidate-save" variant="secondary" loading={save.isPending} onClick={() => save.mutate()}>
            {save.isPending ? c.saving : c.save}
          </Button>
          <Button
            data-testid="meta-candidate-run"
            disabled={!credentials.configured}
            loading={start.isPending}
            onClick={() => start.mutate()}
          >
            <PlayCircle size={16} aria-hidden /> {start.isPending ? c.running : c.run}
          </Button>
        </div>
        {start.isError && <p className="mt-2 text-xs text-danger">{toApiError(start.error).message}</p>}
      </section>

      <RunChecklist run={run} copy={c} />
    </div>
  )
}

function RunChecklist({ run, copy: c }: { run: MetaCandidateRun | null; copy: Copy }) {
  if (!run) {
    return <p data-testid="meta-candidate-no-run" className="text-sm text-text-muted">{c.none}</p>
  }

  return (
    <section data-testid="meta-candidate-checklist" className="rounded-2xl border border-border bg-surface p-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-heading text-[15px] font-bold text-text-primary">{c.checklist}</h2>
        <span data-testid="meta-candidate-status" className="text-sm font-semibold text-text-primary">{c[run.status]}</span>
      </div>
      <p className="mt-1 text-xs text-text-muted" dir="ltr">
        App ID ••••{run.app_id_hint ?? ''} · {run.started_at ?? ''}
      </p>

      <ol className="mt-4 grid gap-2">
        {run.steps.map((step) => <StepRow key={step.key} step={step} copy={c} />)}
      </ol>

      <dl className="mt-4 grid gap-1 text-xs text-text-secondary">
        <div className="flex gap-2"><dt>{c.accounts}:</dt><dd className="tnum" dir="ltr">{run.discovered_accounts.length}</dd></div>
        {run.granted_scopes.length > 0 && (
          <div className="flex gap-2"><dt>{c.scopesGranted}:</dt><dd dir="ltr">{run.granted_scopes.join(' ')}</dd></div>
        )}
      </dl>
    </section>
  )
}

function StepRow({ step, copy: c }: { step: MetaCandidateStep; copy: Copy }) {
  const icon = step.status === 'ok'
    ? <CheckCircle2 size={16} className="text-brand-600" aria-hidden />
    : step.status === 'failed'
      ? <XCircle size={16} className="text-danger" aria-hidden />
      : <Circle size={16} className="text-text-muted" aria-hidden />

  const e = step.error

  return (
    <li data-testid={`meta-candidate-step-${step.key}`} data-status={step.status} className="rounded-lg bg-surface-secondary px-3 py-2 text-[13px]">
      <div className="flex items-center gap-2">
        {icon}
        <span className="font-semibold text-text-primary">{c.steps[step.key]}</span>
        <span className="ms-auto font-mono text-[11px] text-text-muted" dir="ltr">{step.status}</span>
      </div>
      {e && (
        <p className="mt-1 break-words font-mono text-[11px] text-danger" dir="ltr">
          {[
            e.http_status != null ? `HTTP ${e.http_status}` : null,
            e.code != null ? `code ${e.code}` : null,
            e.subcode != null ? `subcode ${e.subcode}` : null,
            e.type ?? null,
            e.fbtrace_id ? `fbtrace_id ${e.fbtrace_id}` : null,
          ].filter(Boolean).join(' · ')}
          {e.message ? ` — ${e.message}` : ''}
        </p>
      )}
    </li>
  )
}
