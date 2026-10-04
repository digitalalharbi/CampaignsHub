import { useMemo, useState } from 'react'
import { providerLabel } from '@/features/campaigns/labels'
import { canonicalPlatform, sortPlatforms } from '@/lib/platforms'
import { PlatformMark } from '@/components/brand/PlatformMark'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Copy, Link2, Upload } from 'lucide-react'
import { createLiveLink, liveBuilderOptions, reportSectionRegistry, type LiveBuilderOptions } from './api'
import { groupByLifecycle } from './reportScopeLifecycle'
import { toApiError } from '@/lib/api/client'
import { Button } from '@/components/ui/Button'
import { DateField } from '@/components/ui/DateField'
import { Field } from '@/components/ui/Field'
import { Modal } from '@/components/ui/Modal'
import { Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'
import { ReportIdentity } from './ReportIdentity'
import { headerIdentity } from './sharedBranding'
import { projectReportIdentity } from './api'
import { uploadBrandingAsset } from '@/features/branding/api'

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

/**
 * The sections an EXECUTIVE SUMMARY does not contain — the mirror of `ReportComposition`.
 *
 * Owner defect row 96 made the funnel, the store reconciliation and the per-platform creative
 * rankings the detailed product's, from the Owner's own handoff §10 split. A summary therefore
 * cannot honour those toggles, and the Owner's rule about settings is explicit: a control that
 * changes nothing must be fixed, removed or disabled. Leaving them tickable would be the third
 * thing this closure is meant to remove — a placebo — and it would be a particularly bad one,
 * because the operator would be ticking a section into a client's document that never arrives.
 *
 * Disabled with the reason stated rather than hidden: an operator who cannot find «Funnel & store»
 * on the summary form would reasonably conclude the control was lost, where a disabled row with a
 * sentence tells them what to change to get it.
 */
const SUMMARY_WITHHOLDS: readonly string[] = ['funnel']

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

  /*
   * Which platform the picker is narrowed to, and which platforms there are to narrow to.
   *
   * Derived from the campaigns themselves rather than from the project's platform list: a platform
   * with no campaign in this window is not a filter anybody can use, and offering it would be a
   * control that always empties the list.
   */
  const [campaignPlatform, setCampaignPlatform] = useState<string | null>(null)

  const campaignPlatforms = useMemo(
    () => sortPlatforms([...new Set((options.data?.campaigns ?? []).flatMap((c) => c.platforms ?? []))]),
    [options.data],
  )

  /*
   * Which AD ACCOUNT the picker is narrowed to, and which accounts there are to narrow to.
   *
   * The platform pills answered half of «تظهر جميع الحملات في الحسابات الاعلانية جميعها». The other
   * half is that two ad accounts on the same platform collapse into one pill, and an agency running
   * two Meta accounts for one client gets back exactly the flat list it started with. The account is
   * the thing the operator actually holds in mind, so it is a filter of its own.
   *
   * Taken from `ad_accounts` rather than collected off the campaigns: the server builds both from the
   * same rows, so the control can never offer an account that narrows the list to nothing.
   */
  const [campaignAccount, setCampaignAccount] = useState<string | null>(null)
  const adAccounts = options.data?.ad_accounts ?? []

  /*
   * And a search, because narrowing is not finding.
   *
   * A project with two hundred campaigns inside one account is still a 200-line scroll after every
   * filter has been applied, and the operator building a report usually knows the name. Matched
   * against the campaign name AND its account name, so typing the account reaches it too.
   */
  const [campaignQuery, setCampaignQuery] = useState('')

  const visibleCampaigns = useMemo(() => {
    const needle = campaignQuery.trim().toLocaleLowerCase()

    return (options.data?.campaigns ?? []).filter((c) => {
      if (campaignPlatform !== null && !(c.platforms ?? []).includes(campaignPlatform)) return false
      if (campaignAccount !== null && !(c.accounts ?? []).some((a) => a.id === campaignAccount)) return false
      if (needle === '') return true

      const haystack = [c.name, ...(c.accounts ?? []).map((a) => a.name)].join(' ').toLocaleLowerCase()

      return haystack.includes(needle)
    })
  }, [options.data, campaignPlatform, campaignAccount, campaignQuery])

  const lifecycle = useMemo(
    () => groupByLifecycle(visibleCampaigns, { periodKnown: Boolean(from && to) }),
    [visibleCampaigns, from, to],
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
  /*
    The sections this link HIDES — coordinator decision: the report-section registry's own list,
    off-only. A section a client report hides by default is shown as «hidden by the report» rather
    than as a switch, because a link can narrow a report and never widen one.
  */
  const registry = useQuery({ queryKey: ['report-section-registry', projectId], queryFn: () => reportSectionRegistry(projectId), retry: false })
  const [linkOff, setLinkOff] = useState<string[]>([])
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
        section_overrides: linkOff,
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
            <input readOnly value={created} data-testid="live-link-url" className="text-start tnum min-w-0 flex-1 rounded-lg border border-border bg-surface px-3 py-2 text-xs" dir="ltr" />
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
          {/*
            REPORT-IDENTITY-001 — who prepares this report, and who it is for, before it is sent.

            Both facts were already resolved and already drawn on the report itself. The one screen
            where somebody DECIDES to send it showed neither, so an operator could only find out
            whose marks a client would see by making the link and opening it.
          */}
          <BuilderIdentity projectId={projectId} ar={ar} />

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
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="text-xs font-bold text-text-muted">{ar ? 'الحملات' : 'Campaigns'}</span>

              {/*
                REPORT-SCOPE-SELECTION-001 — narrow the picker by platform.

                The list was every campaign in the project as one flat column of names. A project
                with four bound ad accounts therefore offered four accounts' campaigns with nothing
                to tell them apart, which is the owner's «غير منطقي»: choosing from it is guesswork.

                It NARROWS what is shown and never what is selected — a campaign already ticked stays
                ticked when the filter moves, because a filter that silently unselected work would be
                the worse version of the same problem.
              */}
              {campaignPlatforms.length > 1 && (
                <div className="flex flex-wrap items-center gap-1" data-testid="live-builder-campaign-platform">
                  <button
                    type="button"
                    aria-pressed={campaignPlatform === null}
                    onClick={() => setCampaignPlatform(null)}
                    className={`rounded-full border px-2 py-0.5 text-[11px] font-bold ${campaignPlatform === null ? 'border-brand-500 bg-brand-primary-soft text-brand-700' : 'border-border text-text-secondary hover:bg-surface-hover'}`}
                  >
                    {ar ? 'الكل' : 'All'}
                  </button>
                  {campaignPlatforms.map((key) => (
                    <button
                      key={key}
                      type="button"
                      aria-pressed={campaignPlatform === key}
                      aria-label={providerLabel(key, ar ? 'ar' : 'en')}
                      title={providerLabel(key, ar ? 'ar' : 'en')}
                      data-testid={`live-builder-campaign-platform-${key}`}
                      onClick={() => setCampaignPlatform(campaignPlatform === key ? null : key)}
                      className={`flex h-6 w-6 items-center justify-center rounded-full border ${campaignPlatform === key ? 'border-transparent bg-brand-600 text-white' : 'border-border text-text-secondary hover:bg-surface-hover'}`}
                    >
                      <PlatformMark platform={key} size={13} />
                    </button>
                  ))}
                </div>
              )}
            </div>

            {/*
              The account filter and the search sit on their own row, below the platform pills.

              Two controls rather than one combined box: the account is a CHOICE out of a known set,
              and the name is something typed. Folding them together would make the operator spell an
              account name to narrow by it, which is the slow way to do the one thing they know
              exactly.
            */}
            {(adAccounts.length > 1 || options.data!.campaigns.length > 8) && (
              <div className="flex flex-wrap items-center gap-2">
                {adAccounts.length > 1 && (
                  <select
                    aria-label={ar ? 'الحساب الإعلاني' : 'Ad account'}
                    data-testid="live-builder-campaign-account"
                    value={campaignAccount ?? ''}
                    onChange={(e) => setCampaignAccount(e.target.value === '' ? null : e.target.value)}
                    className="min-w-0 max-w-[55%] flex-1 truncate rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-text-primary"
                  >
                    <option value="">{ar ? 'كل الحسابات الإعلانية' : 'All ad accounts'}</option>
                    {adAccounts.map((a) => (
                      <option key={a.id} value={a.id}>
                        {a.name} — {providerLabel(a.provider, ar ? 'ar' : 'en')}
                      </option>
                    ))}
                  </select>
                )}

                {options.data!.campaigns.length > 8 && (
                  <input
                    type="search"
                    aria-label={ar ? 'ابحث عن حملة' : 'Search campaigns'}
                    data-testid="live-builder-campaign-search"
                    value={campaignQuery}
                    onChange={(e) => setCampaignQuery(e.target.value)}
                    placeholder={ar ? 'ابحث باسم الحملة أو الحساب' : 'Search by campaign or account'}
                    className="min-w-0 flex-1 rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-text-primary"
                  />
                )}
              </div>
            )}

            {options.data!.campaigns.length === 0 ? (
              <p className="text-xs text-text-muted">{ar ? 'لا توجد حملات في هذا المشروع بعد.' : 'No campaigns in this project yet.'}</p>
            ) : visibleCampaigns.length === 0 ? (
              /*
                A filter that empties the list says so, and says how to undo it.

                An empty box under three controls reads as «this project has no campaigns», which is
                the one thing it does not mean — the campaigns are all still there and still
                selectable the moment the filter moves.
              */
              <div className="grid gap-1" data-testid="live-builder-campaigns-empty">
                <p className="text-xs text-text-muted">
                  {ar
                    ? 'لا حملة تطابق هذا التصفية. الحملات المحددة ما زالت محددة.'
                    : 'No campaign matches this filter. Anything already selected stays selected.'}
                </p>
                <button
                  type="button"
                  onClick={() => {
                    setCampaignPlatform(null)
                    setCampaignAccount(null)
                    setCampaignQuery('')
                  }}
                  className="justify-self-start rounded-lg border border-border px-2 py-1 text-[11px] font-bold text-text-secondary hover:bg-surface-hover"
                >
                  {ar ? 'إظهار كل الحملات' : 'Show all campaigns'}
                </button>
              </div>
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
                  <CampaignOption
                    key={c.id}
                    campaign={c}
                    ar={ar}
                    checked={campaigns.includes(c.id)}
                    onToggle={() => toggle(setCampaigns, c.id)}
                  />
                ))}

                {lifecycle.periodKnown && lifecycle.didNotRun.length > 0 && (
                  <p className="pt-2 text-[11px] font-bold text-text-muted" data-testid="lifecycle-did-not-run">
                    {ar
                      ? `لم تعمل خلال هذه الفترة (${lifecycle.didNotRun.length})`
                      : `Did not run in this period (${lifecycle.didNotRun.length})`}
                  </p>
                )}
                {lifecycle.didNotRun.map((c) => (
                  <CampaignOption
                    key={c.id}
                    campaign={c}
                    ar={ar}
                    checked={campaigns.includes(c.id)}
                    onToggle={() => toggle(setCampaigns, c.id)}
                  />
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
                /*
                 * What the CLIENT will be able to open (`live/modes.ts`). The detailed note used to say
                 * «the dashboard, and every campaign and platform» — a promise of the campaign roster the
                 * client report rule removed, made to the operator deciding what to send.
                 */
                ['executive_summary', ar ? 'ملخّص تنفيذي' : 'Executive summary', ar ? 'ملخص قصير: المؤشرات الرئيسية وأهم الرسوم' : 'A short summary: headline KPIs and the key charts'],
                ['detailed', ar ? 'تفصيلي' : 'Detailed', ar ? 'لوحة كاملة: ملخص، أداء، كل منصة، والمحتوى' : 'The full dashboard: summary, performance, each platform and content'],
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
            <p data-testid="live-link-form-note" className="text-xs text-text-secondary">
              {form === 'detailed'
                ? (ar ? 'لوحة كاملة: ملخص، أداء، كل منصة، والمحتوى' : 'The full dashboard: summary, performance, each platform and content')
                : (ar ? 'ملخص قصير: المؤشرات الرئيسية وأهم الرسوم' : 'A short summary: headline KPIs and the key charts')}
            </p>
          </div>

          {/*
            Which sections the client's page carries.
            
            Unticking one removes the block from the PAYLOAD, not from the layout — `ShareSections`
            makes the point that a section hidden in the UI while its data still travels is «not a
            permission, it is a CSS rule». So these are real choices about what the document
            contains, and the summary line below says so before the link is created.
          */}
          <div className="rounded-xl border border-border p-3">
            <p className="mb-2 text-xs font-bold text-text-muted">{ar ? 'أقسام التقرير' : 'Report sections'}</p>
            <div className="grid gap-1.5 sm:grid-cols-2">
              {(registry.data?.sections ?? []).map((choice) => {
                /* A summary does not contain this section, so the toggle cannot honour it. */
                const withheld = form === 'executive_summary' && SUMMARY_WITHHOLDS.includes(choice.key)
                const byReport = !choice.default_client

                return (
                  <label
                    key={choice.key}
                    data-testid={`live-link-section-row-${choice.key}`}
                    data-withheld={withheld ? 'by-form' : byReport ? 'by-report' : undefined}
                    className={`flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-sm ${withheld || byReport ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:bg-surface-hover'}`}
                  >
                    <span>
                      {ar ? choice.title_ar : choice.title_en}
                      {withheld && (
                        <span className="block text-[11px] text-text-muted">
                          {ar ? 'في التقرير التفصيلي فقط' : 'In the detailed report only'}
                        </span>
                      )}
                    </span>
                    {byReport ? (
                      <span className="text-[11px] text-text-muted">{ar ? 'مخفي في التقرير' : 'Hidden by the report'}</span>
                    ) : (
                      <input
                        type="checkbox"
                        data-testid={`live-link-section-${choice.key}`}
                        disabled={withheld}
                        checked={withheld ? false : !linkOff.includes(choice.key)}
                        onChange={(e) => setLinkOff((prev) => (e.target.checked ? prev.filter((k) => k !== choice.key) : [...prev, choice.key]))}
                        className="h-4 w-4 accent-brand-600"
                      />
                    )}
                  </label>
                )
              })}
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


/**
 * One campaign in the picker: what it is called, where it ran, and WHOSE ACCOUNT paid for it.
 *
 * One component rather than the two identical copies this list used to carry under its two lifecycle
 * headings. They had already drifted apart once, and the account line is exactly the kind of detail
 * that would have been added to one of them.
 *
 * The account is written out, not reduced to an icon. The platform already has an icon and the two
 * are different questions — «Meta» does not tell an agency which of its two Meta accounts this is,
 * and that was the owner's objection. When several accounts feed one unified campaign all of them
 * are named, because naming one would misstate where the spend came from.
 */
function CampaignOption({ campaign, ar, checked, onToggle }: {
  campaign: LiveBuilderOptions['campaigns'][number]
  ar: boolean
  checked: boolean
  onToggle: () => void
}) {
  const accounts = campaign.accounts ?? []

  return (
    <label className="flex items-center gap-2 text-xs">
      <input
        type="checkbox"
        checked={checked}
        onChange={onToggle}
        className="h-3.5 w-3.5 shrink-0 accent-brand-600"
      />
      <span className="min-w-0 flex-1 truncate">
        {campaign.name}
        {accounts.length > 0 && (
          <span className="text-text-muted" data-testid="live-builder-campaign-account-name">
            {' · '}
            {accounts.map((a) => a.name).join(' + ')}
          </span>
        )}
      </span>
      {/* Where it ran, beside what it is called — two campaigns can share a name. */}
      <span className="flex shrink-0 items-center gap-1">
        {(campaign.platforms ?? []).map((key) => (
          <span key={key} title={providerLabel(key, ar ? 'ar' : 'en')} className="text-text-muted">
            <PlatformMark platform={key} size={12} />
          </span>
        ))}
      </span>
    </label>
  )
}

/**
 * The identity block at the top of the builder.
 *
 * Its own component because it has its own request, and the builder must not wait on it: a branding
 * lookup that is slow, or fails, may not stop somebody creating a link. While it is loading there is
 * a reserved line rather than a jump, and on failure there is nothing — the report still resolves
 * its own identity when it is opened, and an error here would be a warning about a problem that is
 * not one.
 */
function BuilderIdentity({ projectId, ar }: { projectId: string; ar: boolean }) {
  const queryClient = useQueryClient()

  const identity = useQuery({
    queryKey: ['report-identity', projectId],
    queryFn: () => projectReportIdentity(projectId),
    retry: false,
  })

  if (identity.isError) return null

  const upload = identity.data?.upload ?? null

  return (
    <section className="rounded-2xl border border-border bg-surface-secondary p-3" data-testid="builder-identity">
      <h3 className="mb-2 text-xs font-bold text-text-muted">{ar ? 'هوية التقرير' : 'Report identity'}</h3>
      {identity.data === undefined ? (
        <Skeleton className="h-10 w-full" />
      ) : (
        <>
          <ReportIdentity identity={headerIdentity(identity.data, ar ? 'ar' : 'en')} ar={ar} testid="builder-report-identity" />

          {/*
            REPORT-IDENTITY-001 — the marks can be set HERE, and they are not set per report.

            Discovering on this screen that a client has no mark used to mean leaving for the
            Branding Center and coming back. These write the same (scope, kind, theme) slot the
            Branding Center writes, so a mark uploaded here is configured ONCE and reused by every
            report — not attached to the link being built.

            Offered only to somebody who may manage branding: the server omits the targets otherwise,
            and an upload control that answers 403 is worse than no control.
          */}
          {upload !== null && (
            <div className="mt-3 flex flex-wrap gap-2 border-t border-border pt-3">
              <MarkUpload
                label={ar ? 'رفع شعار الشركة' : 'Upload company logo'}
                testid="builder-upload-company"
                scope={upload.company.scope}
                scopeId={upload.company.scope_id}
                ar={ar}
                onDone={() => queryClient.invalidateQueries({ queryKey: ['report-identity', projectId] })}
              />
              {upload.client !== null && (
                <MarkUpload
                  label={ar ? 'رفع شعار العميل' : 'Upload client logo'}
                  testid="builder-upload-client"
                  scope={upload.client.scope}
                  scopeId={upload.client.scope_id}
                  ar={ar}
                  onDone={() => queryClient.invalidateQueries({ queryKey: ['report-identity', projectId] })}
                />
              )}
            </div>
          )}
        </>
      )}
    </section>
  )
}

/**
 * One mark, chosen from the operator's own machine.
 *
 * A plain `<input type="file">` behind a label rather than a button that opens a dialog: the file
 * picker is the platform's, it is keyboard-reachable, and a label IS the control for the input it
 * names. The accepted types are the four a brand kit ships, and the server refuses anything else by
 * reading the file rather than its name.
 */
function MarkUpload({ label, testid, scope, scopeId, ar, onDone }: {
  label: string
  testid: string
  scope: string
  scopeId: string | null
  ar: boolean
  onDone: () => void
}) {
  const [error, setError] = useState<string | null>(null)

  const send = useMutation({
    mutationFn: (file: File) => uploadBrandingAsset({
      scope: scope as never,
      scopeId,
      kind: 'report_logo',
      theme: 'any',
      file,
    }),
    onSuccess: () => { setError(null); onDone() },
    onError: (e) => setError(toApiError(e).message),
  })

  return (
    <span className="flex flex-col gap-1">
      <label
        className={`inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold text-text-primary hover:bg-surface-hover ${send.isPending ? 'opacity-60' : ''}`}
        data-testid={testid}
      >
        <Upload size={14} aria-hidden />
        {send.isPending ? (ar ? 'جارٍ الرفع…' : 'Uploading…') : label}
        <input
          type="file"
          className="sr-only"
          accept="image/svg+xml,image/png,image/jpeg,image/webp"
          disabled={send.isPending}
          onChange={(e) => {
            const file = e.target.files?.[0]
            // Cleared so choosing the SAME file twice still fires a change — a re-upload after a
            // refusal is the most likely second attempt.
            e.target.value = ''
            if (file) send.mutate(file)
          }}
        />
      </label>
      {/*
        The server's own refusal, shown where the choice was made. «2 MB» and «SVG, PNG, JPG or
        WebP» are its words, not a second copy of the rule that could drift from it.
      */}
      {error !== null && <span className="max-w-[16rem] text-[11px] text-danger" data-testid={`${testid}-error`}>{error}</span>}
    </span>
  )
}
