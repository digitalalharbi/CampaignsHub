import { useMemo, useState } from 'react'
import { providerLabel } from '@/features/campaigns/labels'
import { canonicalPlatform } from '@/lib/platforms'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Check, Copy, Link2 } from 'lucide-react'
import { createLiveLink, liveBuilderOptions } from './api'
import { groupByLifecycle } from './reportScopeLifecycle'
import { Button } from '@/components/ui/Button'
import { DateField } from '@/components/ui/DateField'
import { Field } from '@/components/ui/Field'
import { Modal } from '@/components/ui/Modal'
import { Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

/**
 * LIVEREP-002 — make a client link by choosing, not by generating a document first.
 *
 * ## The flow this replaces
 *
 * Sharing used to start from a finished report: generate one, then decide who may see it. That is the
 * right order for a signed-off monthly PDF and the wrong one for the thing this product is for — «show
 * this client how their campaigns are doing». An operator answering that has a client, a project, some
 * campaigns and a date range in mind; they do not have a document. Making them produce one first is a
 * step that exists only because of how the storage happened to be arranged.
 *
 * ## Why the choices are in this order
 *
 * Campaigns → platforms → period → metrics. Each narrows the last, and each is optional with a
 * defensible default («everything this project has»), so an operator in a hurry can open this and press
 * the button. The one thing that is NOT optional is a name, because a list of links called «Untitled»
 * is unusable a month later when somebody asks which one the client has.
 *
 * ## The link is shown once
 *
 * Only the token's hash is stored, so it genuinely cannot be shown again — the copy button is the only
 * chance. That is said on screen rather than left for the operator to discover.
 */

const isoDaysAgo = (days: number) => {
  const d = new Date()
  d.setDate(d.getDate() - days + 1)
  return d.toISOString().slice(0, 10)
}

export function LiveLinkBuilder({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const ar = useUi((s) => s.locale) === 'ar'

  const [name, setName] = useState('')
  const [campaigns, setCampaigns] = useState<string[]>([])
  const [providers, setProviders] = useState<string[]>([])
  const [metrics, setMetrics] = useState<string[]>([])
  const [from, setFrom] = useState(isoDaysAgo(30))
  const [to, setTo] = useState(new Date().toISOString().slice(0, 10))

  /*
   * REPORT-SCOPE-SELECTION-001 — the options are asked for THIS report's window.
   *
   * The period is part of the key as well as the request: which campaigns ran is a different answer
   * for June than for July, and serving June's answer from cache for a July report is how a campaign
   * that ran all July ends up filed under «did not run».
   */
  const options = useQuery({
    queryKey: ['live-builder', projectId, from, to],
    queryFn: () => liveBuilderOptions(projectId, { from, to }),
  })

  const lifecycle = useMemo(
    () => groupByLifecycle(options.data?.campaigns ?? [], { periodKnown: Boolean(from && to) }),
    [options.data, from, to],
  )
  /*
   * REPORT-CREATION-UX-001 — WHICH report this link is.
   *
   * The endpoint has been able to store a form since REPORT-PRODUCT-MODEL-001, and this builder
   * never sent one, so every link an operator created came out as whatever the default happened to
   * be. «The dashboard» and «the dashboard with every campaign and platform beneath it» are two
   * different documents to send a client, and which one they got was decided by nobody.
   *
   * Defaults to the summary because that is the smaller promise: a link that shows less than the
   * operator meant is a question they get asked, and one that shows more is a disclosure they
   * cannot take back.
   */
  const [form, setForm] = useState<'executive_summary' | 'detailed'>('executive_summary')
  const [password, setPassword] = useState('')
  const [expiresAt, setExpiresAt] = useState('')
  const [hideSpend, setHideSpend] = useState(false)
  const [hideRevenue, setHideRevenue] = useState(false)
  const [allowDownload, setAllowDownload] = useState(false)
  const [created, setCreated] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)

  const create = useMutation({
    mutationFn: () =>
      createLiveLink(projectId, {
        name: name.trim(),
        campaign_ids: campaigns,
        providers,
        metrics,
        from,
        to,
        form,
        hide_spend: hideSpend,
        hide_revenue: hideRevenue,
        allow_download: allowDownload,
        ...(password ? { password } : {}),
        ...(expiresAt ? { expires_at: expiresAt } : {}),
      }),
    /*
     * SHARE-LINK-DOUBLE-ORIGIN-001 — the copied link had its host twice.
     *
     * `ShareService::urlFor()` returns the ABSOLUTE link — `https://campaignshub.io/r/…` — while
     * this prepended `window.location.origin` to it, producing
     * `https://campaignshub.iohttps://campaignshub.io/r/…`. Every link this builder produced was
     * broken before it left the operator's screen.
     *
     * `ReportsPage` was fixed for exactly this and says why in its own comment: the server states
     * the canonical host, because assembling it from the tab means an operator reviewing on staging
     * or localhost sends a client a host only they can reach. This builder is newer and repeated the
     * mistake the older one had already corrected.
     */
    onSuccess: (r) => setCreated(r.url),
  })

  /*
   * A FUNCTIONAL update, not a read of the rendered value.
   *
   * `set(list.includes(v) ? … : [...list, v])` closes over whatever `list` was at render time. Click
   * two chips before React re-renders — which is ordinary for a fast user and guaranteed for a test
   * driving the page — and both handlers read the same empty array, so the second overwrites the
   * first and the selection silently loses everything but the last click. Reading `prev` inside the
   * updater makes each toggle see the result of the one before it.
   */
  const toggle = (set: (fn: (prev: string[]) => string[]) => void, value: string) =>
    set((prev) => (prev.includes(value) ? prev.filter((v) => v !== value) : [...prev, value]))

  const copy = () => {
    if (!created) return
    navigator.clipboard.writeText(created).then(() => {
      setCopied(true)
      setTimeout(() => setCopied(false), 1500)
    })
  }

  const chip = (active: boolean) =>
    `rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition-colors ${
      active ? 'border-brand-500 bg-[var(--brand-background)] text-brand-700' : 'border-border text-text-secondary hover:bg-surface-hover'
    }`

  const summary = useMemo(() => {
    const parts: string[] = []
    parts.push(campaigns.length === 0 ? (ar ? 'كل الحملات' : 'All campaigns') : `${campaigns.length} ${ar ? 'حملة' : 'campaigns'}`)
    parts.push(providers.length === 0
      ? (ar ? 'كل المنصات' : 'All platforms')
      : providers.map((p) => providerLabel(canonicalPlatform(p), ar ? 'ar' : 'en')).join(ar ? '، ' : ', '))
    parts.push(metrics.length === 0 ? (ar ? 'كل المؤشرات' : 'All metrics') : `${metrics.length} ${ar ? 'مؤشر' : 'metrics'}`)
    /*
     * The CONSEQUENCE of each choice, in words, before it is saved — REPORT-CREATION-UX-001.
     *
     * The summary listed what was selected. What an operator is actually deciding is what a client
     * will be able to see, and «detailed» versus «summary» changes that more than any chip above it
     * does. So the sentence names the document, and then names what is withheld — because «hide
     * spend» ticked and forgotten is how a client is sent a report with a hole in it.
     */
    parts.push(form === 'detailed'
      ? (ar ? 'تقرير تفصيلي' : 'Detailed report')
      : (ar ? 'ملخّص تنفيذي' : 'Executive summary'))

    const withheld: string[] = []
    if (hideSpend) withheld.push(ar ? 'الإنفاق' : 'spend')
    if (hideRevenue) withheld.push(ar ? 'الإيراد' : 'revenue')
    if (withheld.length > 0) {
      parts.push(ar ? `بدون ${withheld.join(' و')}` : `without ${withheld.join(' and ')}`)
    }
    if (allowDownload) parts.push(ar ? 'مع إمكانية التنزيل' : 'downloadable')

    return parts.join(' · ')
  }, [campaigns, providers, metrics, form, hideSpend, hideRevenue, allowDownload, ar])

  return (
    <Modal open onClose={onClose} title={ar ? 'رابط تقرير لحظي للعميل' : 'Live client report link'} size="lg">
      {created ? (
        <div className="rounded-2xl border border-brand-200 bg-[var(--brand-background)] p-4" data-testid="live-link-created">
          <div className="mb-2 flex items-center gap-2 text-sm font-bold text-brand-700">
            <Link2 size={16} /> {ar ? 'تم إنشاء الرابط — انسخه الآن، لن يُعرض مرة أخرى' : 'Link created — copy it now, it is shown only once'}
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <input readOnly value={created} data-testid="live-link-url" className="tnum min-w-0 flex-1 rounded-lg border border-border bg-surface px-3 py-2 text-xs" dir="ltr" />
            <Button size="sm" onClick={copy}>
              {copied ? <Check size={15} /> : <Copy size={15} />} {copied ? (ar ? 'نُسخ' : 'Copied') : (ar ? 'نسخ' : 'Copy')}
            </Button>
          </div>
          <p className="mt-2 text-xs text-text-secondary">
            {ar
              ? 'الرابط يعرض أحدث الأرقام في كل مرة يُفتح فيها، ولا يستطيع الوصول إلى أي مشروع أو عميل آخر.'
              : 'The link shows current figures every time it is opened, and cannot reach any other project or client.'}
          </p>
        </div>
      ) : options.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : options.isError ? (
        <p className="py-8 text-center text-sm text-danger">
          {ar ? 'تعذّر تحميل خيارات المشروع.' : 'Could not load this project’s options.'}
        </p>
      ) : (
        <div className="grid gap-4">
          <Field label={ar ? 'اسم التقرير' : 'Report name'} required>
            <input
              value={name}
              data-testid="live-link-name"
              onChange={(e) => setName(e.target.value)}
              placeholder={ar ? 'أداء الحملات — أغسطس' : 'Campaign performance — August'}
              className="w-full rounded-xl border border-border bg-surface px-3 py-2.5 text-base"
            />
          </Field>

          <div className="grid gap-2">
            <span className="text-xs font-bold text-text-muted">{ar ? 'الحملات' : 'Campaigns'}</span>
            {options.data!.campaigns.length === 0 ? (
              <p className="text-xs text-text-muted">{ar ? 'لا توجد حملات في هذا المشروع بعد.' : 'No campaigns in this project yet.'}</p>
            ) : (
              <div className="grid max-h-40 gap-1 overflow-y-auto" data-testid="live-builder-campaigns">
                {/*
                  REPORT-SCOPE-SELECTION-001 — grouped by what ran IN THIS REPORT'S WINDOW.

                  Not by today's status: a campaign that finished last week ran through the whole of
                  the period being reported on, and leaving it out would silently remove its spend
                  from the client's report. Nothing is hidden — the headings decide order and
                  emphasis, never membership.
                */}
                {lifecycle.periodKnown && lifecycle.ran.length > 0 && (
                  <p className="pt-1 text-[11px] font-bold text-text-muted" data-testid="lifecycle-ran">
                    {ar ? `عملت خلال هذه الفترة (${lifecycle.ran.length})` : `Ran in this period (${lifecycle.ran.length})`}
                  </p>
                )}
                {lifecycle.ran.map((c) => (
                  <label key={c.id} className="flex items-center gap-2 text-xs">
                    <input type="checkbox" checked={campaigns.includes(c.id)} onChange={() => toggle(setCampaigns, c.id)} className="h-3.5 w-3.5 accent-brand-600" />
                    <span className="truncate">{c.name}</span>
                  </label>
                ))}

                {lifecycle.periodKnown && lifecycle.didNotRun.length > 0 && (
                  <p className="pt-2 text-[11px] font-bold text-text-muted" data-testid="lifecycle-did-not-run">
                    {ar
                      ? `لم تعمل خلال هذه الفترة (${lifecycle.didNotRun.length})`
                      : `Did not run in this period (${lifecycle.didNotRun.length})`}
                  </p>
                )}
                {lifecycle.didNotRun.map((c) => (
                  <label key={c.id} className="flex items-center gap-2 text-xs">
                    <input type="checkbox" checked={campaigns.includes(c.id)} onChange={() => toggle(setCampaigns, c.id)} className="h-3.5 w-3.5 accent-brand-600" />
                    <span className="truncate">{c.name}</span>
                  </label>
                ))}
              </div>
            )}
          </div>

          <div className="grid gap-2">
            <span className="text-xs font-bold text-text-muted">{ar ? 'المنصات' : 'Platforms'}</span>
            {options.data!.providers.length === 0 ? (
              <p className="text-xs text-text-muted">
                {ar ? 'لا توجد بيانات من أي منصة بعد.' : 'No platform data yet.'}
              </p>
            ) : (
              <div className="flex flex-wrap gap-1.5">
                {/*
                  LIVELINK-PROVIDER-LABEL-001 — `capitalize` was disguising the raw key.
                  
                  `{p}` rendered the provider as stored and CSS title-cased it, so `meta` read as
                  «Meta» and passed for a brand name. `google_ads` would have read «Google_ads», and
                  in Arabic every chip was Latin on a page that is otherwise not. The class is gone
                  with the raw value: a real label needs no cosmetic help.
                */}
                {options.data!.providers.map((p) => (
                  <button key={p} type="button" onClick={() => toggle(setProviders, p)} className={chip(providers.includes(p))}>{providerLabel(canonicalPlatform(p), ar ? 'ar' : 'en')}</button>
                ))}
              </div>
            )}
          </div>

          <div className="grid gap-2 sm:grid-cols-2">
            <Field label={ar ? 'من' : 'From'}><DateField value={from} onChange={setFrom} /></Field>
            <Field label={ar ? 'إلى' : 'To'}><DateField value={to} onChange={setTo} /></Field>
          </div>

          <div className="grid gap-2">
            <span className="text-xs font-bold text-text-muted">{ar ? 'المؤشرات المعروضة للعميل' : 'Metrics the client sees'}</span>
            <div className="flex flex-wrap gap-1.5">
              {options.data!.metrics.map((m) => (
                <button key={m.key} type="button" data-testid={`metric-${m.key}`} onClick={() => toggle(setMetrics, m.key)} className={chip(metrics.includes(m.key))}>
                  {ar ? m.ar : m.en}
                </button>
              ))}
            </div>
          </div>

          <div className="grid gap-2 sm:grid-cols-2">
            <Field label={ar ? 'كلمة مرور (اختياري)' : 'Password (optional)'}>
              <input type="text" value={password} onChange={(e) => setPassword(e.target.value)} className="w-full rounded-xl border border-border bg-surface px-3 py-2.5 text-base" />
            </Field>
            <Field label={ar ? 'تاريخ الانتهاء (اختياري)' : 'Expiry (optional)'}>
              <DateField value={expiresAt} onChange={setExpiresAt} />
            </Field>
          </div>

          {/*
            The document itself, above the withholding switches: what the link IS comes before what
            it leaves out.
          */}
          <div className="grid gap-2">
            <span className="text-xs font-bold text-text-muted">{ar ? 'شكل التقرير' : 'What the link shows'}</span>
            <div className="flex flex-wrap gap-1.5">
              {([
                ['executive_summary', ar ? 'ملخّص تنفيذي' : 'Executive summary', ar ? 'اللوحة وحدها' : 'the dashboard alone'],
                ['detailed', ar ? 'تفصيلي' : 'Detailed', ar ? 'اللوحة ومعها كل حملة ومنصة' : 'the dashboard, and every campaign and platform'],
              ] as const).map(([key, label, note]) => (
                <button
                  key={key}
                  type="button"
                  data-testid={`live-link-form-${key}`}
                  onClick={() => setForm(key)}
                  title={note}
                  className={chip(form === key)}
                >
                  {label}
                </button>
              ))}
            </div>
          </div>

          <label className="flex cursor-pointer items-center justify-between rounded-xl border border-border px-3 py-2 text-sm">
            <span>{ar ? 'إخفاء الإنفاق عن العميل' : 'Hide spend from the client'}</span>
            <input type="checkbox" data-testid="live-link-hide-spend" checked={hideSpend} onChange={(e) => setHideSpend(e.target.checked)} className="h-4 w-4 accent-brand-600" />
          </label>

          <label className="flex cursor-pointer items-center justify-between rounded-xl border border-border px-3 py-2 text-sm">
            <span>{ar ? 'إخفاء الإيراد عن العميل' : 'Hide revenue from the client'}</span>
            <input type="checkbox" data-testid="live-link-hide-revenue" checked={hideRevenue} onChange={(e) => setHideRevenue(e.target.checked)} className="h-4 w-4 accent-brand-600" />
          </label>

          {/*
            The endpoint has accepted this since it shipped and no control ever sent it, so every
            link was created non-downloadable — a default nobody chose, in the one setting whose
            wrong value a client notices immediately.
          */}
          <label className="flex cursor-pointer items-center justify-between rounded-xl border border-border px-3 py-2 text-sm">
            <span>{ar ? 'السماح للعميل بتنزيل التقرير' : 'Let the client download the report'}</span>
            <input type="checkbox" data-testid="live-link-allow-download" checked={allowDownload} onChange={(e) => setAllowDownload(e.target.checked)} className="h-4 w-4 accent-brand-600" />
          </label>

          {/* What the link will show, in words, before it is created — see ViewCustomiser for the same idea. */}
          <p data-testid="live-link-summary" className="text-xs text-text-secondary">{summary}</p>

          {create.isError && (
            <p className="text-xs text-danger">
              {ar ? 'تعذّر إنشاء الرابط. تأكد من وجود حملات في هذا المشروع.' : 'Could not create the link. Check this project has campaigns.'}
            </p>
          )}

          <Button data-testid="live-link-create" loading={create.isPending} disabled={name.trim() === ''} onClick={() => create.mutate()}>
            <Link2 size={16} /> {ar ? 'إنشاء الرابط' : 'Create the link'}
          </Button>
        </div>
      )}
    </Modal>
  )
}
