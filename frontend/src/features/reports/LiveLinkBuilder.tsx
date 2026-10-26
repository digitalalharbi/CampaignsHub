import { CompositionTree, VIEW_KEYS, viewsBody, type ViewKey } from './CompositionTree'
import { ColumnPicker } from './ColumnPicker'
import { CANONICAL_OBJECTIVES, DESTINATIONS, DETAIL_LEVELS, formOf, type DetailLevelKey, levelSections } from './reportSetupCatalog'
import { useMemo, useState } from 'react'
import { providerLabel } from '@/features/campaigns/labels'
import { canonicalPlatform, sortPlatforms } from '@/lib/platforms'
import { PlatformMark } from '@/components/brand/PlatformMark'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Check, Copy, Link2 } from 'lucide-react'
import { createLiveLink, liveBuilderAds, liveBuilderOptions, reportSectionRegistry, type LiveBuilderOptions, type LiveBuilderAd, type AdAccountRef } from './api'
import { groupByLifecycle } from './reportScopeLifecycle'
import { Button } from '@/components/ui/Button'
import { DateField } from '@/components/ui/DateField'
import { Field } from '@/components/ui/Field'
import { Modal } from '@/components/ui/Modal'
import { Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'
import { ReportIdentityCards, type IdentityNames } from './ReportIdentityCards'
import { ShareCreativeControls, creativeSharingBody, emptyCreativeSharing, type CreativeSharing } from './ShareCreativeControls'

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
  const [description, setDescription] = useState('')
  /*
   * REPORT-SCOPE-HIERARCHY-001 — the hierarchy is chosen top-down: ad accounts first, then (optionally)
   * campaigns inside them, then (optionally) ads inside those. Nothing selected at a level means ALL of
   * it, but the first level can never be empty: a link must name the accounts it speaks about, because
   * «no choice = everything in the project» is how another client's account reached a report.
   */
  const [selectedAccounts, setSelectedAccounts] = useState<string[]>([])
  const [accountQuery, setAccountQuery] = useState('')
  const [campaigns, setCampaigns] = useState<string[]>([])
  const [adIds, setAdIds] = useState<string[]>([])
  const [adQuery, setAdQuery] = useState('')
  const [providers, setProviders] = useState<string[]>([])
  const [metrics, setMetrics] = useState<string[]>([])
  // REPORT-PERIOD-001 — the period KIND decides the window; only «custom» takes free dates.
  const [periodKind, setPeriodKind] = useState<'daily' | 'weekly' | 'monthly' | 'custom'>('custom')
  /* REPORT-PERIOD-MODE-001 — fixed: exactly these dates, always. Rolling: the window follows the reader's day. */
  const [periodMode, setPeriodMode] = useState<'fixed' | 'rolling'>('fixed')
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
  const adAccounts = options.data?.ad_accounts ?? []

  /*
   * And a search, because narrowing is not finding.
   *
   * A project with two hundred campaigns inside one account is still a 200-line scroll after every
   * filter has been applied, and the operator building a report usually knows the name. Matched
   * against the campaign name AND its account name, so typing the account reaches it too.
   */
  const [campaignQuery, setCampaignQuery] = useState('')

  // REPORT-SETUP-001 — Objective and Destination narrow the campaign list like the platform and account do.
  const [campaignObjective, setCampaignObjective] = useState<string | null>(null)
  const [campaignDestination, setCampaignDestination] = useState<string | null>(null)

  const visibleCampaigns = useMemo(() => {
    const needle = campaignQuery.trim().toLocaleLowerCase()
    const members = CANONICAL_OBJECTIVES.find((o) => o.id === campaignObjective)?.members ?? null

    return (options.data?.campaigns ?? []).filter((c) => {
      if (campaignPlatform !== null && !(c.platforms ?? []).includes(campaignPlatform)) return false
      if (!(c.accounts ?? []).some((a) => selectedAccounts.includes(a.id))) return false
      if (members !== null && !members.includes(String(c.objective ?? ''))) return false
      if (campaignDestination !== null && c.destination !== campaignDestination) return false
      if (needle === '') return true

      const haystack = [c.name, ...(c.accounts ?? []).map((a) => a.name)].join(' ').toLocaleLowerCase()

      return haystack.includes(needle)
    })
  }, [options.data, campaignPlatform, selectedAccounts, campaignObjective, campaignDestination, campaignQuery])

  const lifecycle = useMemo(
    () => groupByLifecycle(visibleCampaigns, { periodKnown: Boolean(from && to) }),
    [visibleCampaigns, from, to],
  )
  const adsQuery = useQuery({
    queryKey: ['live-builder-ads', projectId, [...campaigns].sort()],
    queryFn: () => liveBuilderAds(projectId, campaigns),
    enabled: campaigns.length > 0 && campaigns.length <= 100,
  })
  const chosenAds = useMemo(() => {
    const known = new Set((adsQuery.data?.ads ?? []).map((a) => a.id))
    return adIds.filter((id) => known.has(id))
  }, [adIds, adsQuery.data])

  const toggleAccount = (id: string) => {
    const next = selectedAccounts.includes(id) ? selectedAccounts.filter((a) => a !== id) : [...selectedAccounts, id]
    setSelectedAccounts(next)
    // A campaign ticked under an account that is no longer selected must not stay in the request.
    setCampaigns((prev) => prev.filter((cid) => (options.data?.campaigns ?? []).find((c) => c.id === cid)?.accounts?.some((a) => next.includes(a.id))))
  }

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
  const [level, setLevel] = useState<DetailLevelKey>('summary')
  const form = formOf(level)
  const [password, setPassword] = useState('')
  const [expiresAt, setExpiresAt] = useState('')
  // CLIENT-REPORT-FRAMING-001 — «لا تتطرق للإنفاق أبدًا»: a new client link hides spend unless the owner chooses to show it.
  const [hideSpend, setHideSpend] = useState(true)
  const [hideRevenue, setHideRevenue] = useState(false)
  const [allowDownload, setAllowDownload] = useState(false)
  /*
    The sections this link HIDES — coordinator decision: the report-section registry's own list,
    off-only. A section a client report hides by default is shown as «hidden by the report» rather
    than as a switch, because a link can narrow a report and never widen one.
  */
  const registry = useQuery({ queryKey: ['report-section-registry', projectId], queryFn: () => reportSectionRegistry(projectId), retry: false })
  /*
   * REPORT-OWNER-CONTROL-001 — the Owner's ON/OFF choice per section, laid over the level's preset.
   * Reset when the level changes: a preset chosen after a switch is a new starting point.
   */
  const [sectionChoice, setSectionChoice] = useState<Record<string, boolean>>({})
  // REPORT-COMPOSITION-CONTROL-001 — the main sections (tabs) of a detailed link; all on until the Owner closes one.
  const [viewOn, setViewOn] = useState<Record<ViewKey, boolean>>(() => Object.fromEntries(VIEW_KEYS.map((v) => [v, true])) as Record<ViewKey, boolean>)
  const sectionOn = (key: string, defaultClient: boolean): boolean =>
    sectionChoice[key] ?? levelSections(level)[key] ?? defaultClient
  // REPORT-TABLES-OWNER-COLUMNS-001 — null means «every column», which is also what is sent.
  const [platformCols, setPlatformCols] = useState<string[] | null>(null)
  const [otherCols, setOtherCols] = useState<Record<'accounts' | 'content' | 'days', string[] | null>>({ accounts: null, content: null, days: null })
  const [created, setCreated] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)
  // REPORT-IDENTITY-NAMES-001 — the printed names; null = the stored names.
  const [names, setNames] = useState<IdentityNames>({ for: null, by: null })

  /*
   * CREATIVE-DISPLAY-001 — the ad section, chosen here and SENT. The builder never carried it, so the controls an operator reached for
   * on the saved-report dialog did nothing on a link made from here. The defaults are the Owner's: the section shown, the ads listed by
   * period activity, refreshed automatically, zero-activity ads left out.
   */
  const [creatives, setCreatives] = useState<CreativeSharing>(() => ({ ...emptyCreativeSharing(), permissions: { creatives: true } }))

  const create = useMutation({
    mutationFn: () =>
      createLiveLink(projectId, {
        ...creativeSharingBody(creatives),
        name: name.trim(),
        ...(description.trim() === '' ? {} : { description: description.trim() }),
        ...(names.for === null || names.for.trim() === '' ? {} : { prepared_for_name: names.for.trim() }),
        ...(names.by === null || names.by.trim() === '' ? {} : { prepared_by_name: names.by.trim() }),
        account_ids: selectedAccounts,
        accounts: accountSelection(selectedAccounts, campaigns, chosenAds, options.data?.campaigns ?? [], adsQuery.data?.ads ?? []),
        providers,
        metrics,
        from,
        to,
        period_kind: periodKind,
        period_mode: periodMode,
        form,
        detail_level: level,
        hide_spend: hideSpend,
        hide_revenue: hideRevenue,
        allow_download: allowDownload,
        sections: Object.fromEntries((registry.data?.sections ?? []).map((c) => [c.key, form === 'executive_summary' && SUMMARY_WITHHOLDS.includes(c.key) ? false : sectionOn(c.key, c.default_client)])),
        ...(() => {
          const columns: Record<string, string[]> = {
            ...(platformCols === null ? {} : { platforms: platformCols }),
            ...Object.fromEntries(Object.entries(otherCols).filter((e): e is [string, string[]] => e[1] !== null)),
          }

          return Object.keys(columns).length === 0 ? {} : { columns }
        })(),
        ...(level === 'detailed' && viewsBody(viewOn) !== null ? { views: viewsBody(viewOn) } : {}),
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
    parts.push(`${selectedAccounts.length} ${ar ? 'حساب إعلاني' : 'ad accounts'}`)
    parts.push(campaigns.length === 0 ? (ar ? 'كل حملات الحسابات المحددة' : 'All campaigns of those accounts') : `${campaigns.length} ${ar ? 'حملة' : 'campaigns'}`)
    if (chosenAds.length > 0) parts.push(`${chosenAds.length} ${ar ? 'إعلان' : 'ads'}`)
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
  }, [selectedAccounts, chosenAds, campaigns, providers, metrics, form, hideSpend, hideRevenue, allowDownload, ar])

  return (
    <Modal open onClose={onClose} title={ar ? 'تقرير رابط' : 'Link Report'} size="lg">
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
          <ReportIdentityCards projectId={projectId} ar={ar} names={names} onNames={setNames} />

          <Field label={ar ? 'اسم التقرير' : 'Report name'} required>
            <input
              value={name}
              data-testid="live-link-name"
              onChange={(e) => setName(e.target.value)}
              placeholder={ar ? 'أداء الحملات — أغسطس' : 'Campaign performance — August'}
              className="w-full rounded-xl border border-border bg-surface px-3 py-2.5 text-base"
            />
          </Field>

          <Field label={ar ? 'وصف (اختياري)' : 'Description (optional)'}>
            <textarea
              value={description}
              maxLength={500}
              rows={2}
              data-testid="live-link-description"
              onChange={(e) => setDescription(e.target.value)}
              placeholder={ar ? 'يظهر في معاينة الرابط إن كتبته' : 'Shown in the link preview only if you write one'}
              className="w-full rounded-xl border border-border bg-surface px-3 py-2.5 text-base"
            />
          </Field>

          <AccountPicker
            ar={ar}
            accounts={adAccounts}
            selected={selectedAccounts}
            query={accountQuery}
            onQuery={setAccountQuery}
            onToggle={toggleAccount}
            onAll={() => setSelectedAccounts(adAccounts.map((a) => a.id))}
            onClear={() => { setSelectedAccounts([]); setCampaigns([]); setAdIds([]) }}
          />

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
            <div className="flex flex-wrap items-center gap-2">
              <select
                aria-label={ar ? 'الهدف' : 'Objective'}
                data-testid="live-builder-campaign-objective"
                value={campaignObjective ?? ''}
                onChange={(e) => setCampaignObjective(e.target.value === '' ? null : e.target.value)}
                className="min-w-0 flex-1 truncate rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-text-primary"
              >
                <option value="">{ar ? 'كل الأهداف' : 'All objectives'}</option>
                {CANONICAL_OBJECTIVES.map((o) => (
                  <option key={o.id} value={o.id}>{ar ? o.ar : o.en}</option>
                ))}
              </select>
              {(options.data?.campaigns ?? []).some((c) => c.destination) && (
                <select
                  aria-label={ar ? 'الوجهة' : 'Destination'}
                  data-testid="live-builder-campaign-destination"
                  value={campaignDestination ?? ''}
                  onChange={(e) => setCampaignDestination(e.target.value === '' ? null : e.target.value)}
                  className="min-w-0 flex-1 truncate rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-text-primary"
                >
                  <option value="">{ar ? 'كل الوجهات' : 'All destinations'}</option>
                  {DESTINATIONS.filter((d) => (options.data?.campaigns ?? []).some((c) => c.destination === d.id)).map((d) => (
                    <option key={d.id} value={d.id}>{ar ? d.ar : d.en}</option>
                  ))}
                </select>
              )}
            </div>

            {options.data!.campaigns.length > 8 && (
              <div className="flex flex-wrap items-center gap-2">
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

            {selectedAccounts.length === 0 ? (
              <p className="text-xs text-text-muted" data-testid="live-builder-pick-accounts">
                {ar ? 'اختر حسابًا إعلانيًا واحدًا على الأقل لتظهر حملاته.' : 'Pick at least one ad account to see its campaigns.'}
              </p>
            ) : options.data!.campaigns.length === 0 ? (
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
                    ? 'لا حملة تطابق هذه التصفية. الحملات المحددة ما زالت محددة.'
                    : 'No campaign matches this filter. Anything already selected stays selected.'}
                </p>
                <button
                  type="button"
                  onClick={() => {
                    setCampaignPlatform(null)
                    setCampaignObjective(null)
                    setCampaignDestination(null)
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

          {campaigns.length > 0 && (
            <AdPicker
              ar={ar}
              ads={adsQuery.data?.ads ?? []}
              loading={adsQuery.isLoading}
              tooMany={campaigns.length > 100}
              campaigns={options.data!.campaigns}
              selected={chosenAds}
              query={adQuery}
              onQuery={setAdQuery}
              onToggle={(id) => toggle(setAdIds, id)}
              onClear={() => setAdIds([])}
            />
          )}

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

          <div className="grid gap-2" data-testid="live-link-period">
            <span className="text-xs font-bold text-text-muted">{ar ? 'نوع الفترة' : 'Period'}</span>
            <div role="group" className="grid grid-cols-4 gap-1">
              {([['daily', 'يومي', 'Daily'], ['weekly', 'أسبوعي', 'Weekly'], ['monthly', 'شهري', 'Monthly'], ['custom', 'مخصص', 'Custom']] as const).map(([key, labelAr, labelEn]) => (
                <button
                  key={key}
                  type="button"
                  data-testid={`live-link-period-${key}`}
                  aria-pressed={periodKind === key}
                  onClick={() => setPeriodKind(key)}
                  className={`rounded-xl border px-2 py-1.5 text-sm font-semibold ${periodKind === key ? 'border-brand-600 bg-brand-600/10 text-brand-600' : 'border-border text-text-secondary'}`}
                >
                  {ar ? labelAr : labelEn}
                </button>
              ))}
            </div>
            <p className="text-[11px] text-text-muted">
              {periodKind === 'daily' && (ar ? 'يوم واحد يُقارَن بالأمس، واتجاه آخر أسبوع.' : 'One day, compared with the previous day; the trend is the trailing week.')}
              {periodKind === 'weekly' && (ar ? 'الأسبوع (الاثنين–الأحد) يُقارَن بالأسبوع السابق.' : 'The Monday–Sunday week, compared with the previous week.')}
              {periodKind === 'monthly' && (ar ? 'الشهر الميلادي يُقارَن بالشهر السابق.' : 'The calendar month, compared with the previous month.')}
              {periodKind === 'custom' && (ar ? 'نطاق تحدده، يُقارَن بفترة بنفس الطول قبله.' : 'A range you choose, compared with the same length before it.')}
            </p>
          </div>
          <div className="grid gap-2" data-testid="live-link-period-mode">
            <span className="text-xs font-bold text-text-muted">{ar ? 'سلوك الفترة' : 'Period behaviour'}</span>
            <div role="group" className="grid grid-cols-2 gap-1">
              {([['fixed', 'فترة ثابتة', 'Fixed dates'], ['rolling', 'فترة متحركة', 'Rolling']] as const).map(([key, labelAr, labelEn]) => (
                <button
                  key={key}
                  type="button"
                  data-testid={`live-link-period-mode-${key}`}
                  aria-pressed={periodMode === key}
                  onClick={() => setPeriodMode(key)}
                  className={`rounded-xl border px-2 py-1.5 text-sm font-semibold ${periodMode === key ? 'border-brand-600 bg-brand-600/10 text-brand-600' : 'border-border text-text-secondary'}`}
                >
                  {ar ? labelAr : labelEn}
                </button>
              ))}
            </div>
            <p className="text-[11px] text-text-muted">
              {periodMode === 'fixed'
                ? (ar ? 'تبقى التواريخ المحفوظة كما هي عند كل فتح للرابط؛ تتحدّث الأرقام داخل هذه الفترة فقط.' : 'The saved dates never move; the figures refresh inside them only.')
                : (ar ? 'تتبع الفترة يوم القارئ، ويمكنه اختيار آخر 7 أو 30 أو 90 يومًا.' : 'The window follows the reader’s day, and they may pick the last 7, 30 or 90 days.')}
            </p>
          </div>
          {periodKind === 'custom' && (
            <div className="grid gap-2 sm:grid-cols-2">
              <Field label={ar ? 'من' : 'From'}><DateField value={from} onChange={setFrom} /></Field>
              <Field label={ar ? 'إلى' : 'To'}><DateField value={to} onChange={setTo} /></Field>
            </div>
          )}

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
            <span className="text-xs font-bold text-text-muted">{ar ? 'مستوى التفصيل' : 'Detail level'}</span>
            <div className="flex flex-wrap gap-1.5">
              {DETAIL_LEVELS.map((o) => (
                <button
                  key={o.key}
                  type="button"
                  data-testid={`live-link-level-${o.key}`}
                  onClick={() => { setLevel(o.key); setSectionChoice({}) }}
                  title={ar ? o.noteAr : o.noteEn}
                  className={chip(level === o.key)}
                >
                  {ar ? o.ar : o.en}
                </button>
              ))}
            </div>
            <p data-testid="live-link-level-note" className="text-xs text-text-secondary">
              {ar ? DETAIL_LEVELS.find((o) => o.key === level)!.noteAr : DETAIL_LEVELS.find((o) => o.key === level)!.noteEn}
            </p>
          </div>

          {/*
            Which sections the client's page carries.
            
            Unticking one removes the block from the PAYLOAD, not from the layout — `ShareSections`
            makes the point that a section hidden in the UI while its data still travels is «not a
            permission, it is a CSS rule». So these are real choices about what the document
            contains, and the summary line below says so before the link is created.
          */}
          {/*
            REPORT-COMPOSITION-CONTROL-001 — two levels: the main sections a detailed link offers as
            tabs, and the components inside each. A summary or standard link is one composition, so
            its components are grouped the same way without main-section switches.
          */}
          <div className="grid gap-2">
            <p className="text-xs font-bold text-text-muted">{ar ? 'أقسام التقرير ومكوّناتها' : 'Report sections and their components'}</p>
            <CompositionTree
              testid="live-link-section"
              rows={registry.data?.sections ?? []}
              ar={ar}
              offersViews={level === 'detailed'}
              viewState={(v) => (viewOn[v] ? 'on' : 'off')}
              onView={(v, on) => setViewOn((prev) => ({ ...prev, [v]: on }))}
              rowState={(row) => (form === 'executive_summary' && SUMMARY_WITHHOLDS.includes(row.key) ? 'by-form' : undefined)}
              control={(row) => {
                const choice = (registry.data?.sections ?? []).find((c) => c.key === row.key)!
                /* A summary does not contain this section, so the toggle cannot honour it. */
                const withheld = form === 'executive_summary' && SUMMARY_WITHHOLDS.includes(choice.key)
                return (
                  <span className="flex shrink-0 items-center gap-2">
                    {withheld && <span className="text-[11px] text-text-muted">{ar ? 'في التقرير التفصيلي فقط' : 'In the detailed report only'}</span>}
                    <input
                      type="checkbox"
                      aria-label={ar ? choice.title_ar : choice.title_en}
                      data-testid={`live-link-section-${choice.key}`}
                      disabled={withheld}
                      checked={withheld ? false : sectionOn(choice.key, choice.default_client)}
                      onChange={(e) => setSectionChoice((prev) => ({ ...prev, [choice.key]: e.target.checked }))}
                      className="h-4 w-4 accent-brand-600"
                    />
                  </span>
                )
              }}
            />
          </div>

          <ColumnPicker value={platformCols} onChange={setPlatformCols} ar={ar} />
          {(['accounts', 'content', 'days'] as const).map((table) => (
            <ColumnPicker key={table} table={table} value={otherCols[table]} onChange={(next) => setOtherCols((c) => ({ ...c, [table]: next }))} ar={ar} />
          ))}

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

          <ShareCreativeControls projectId={projectId} value={creatives} onChange={setCreatives} />

          {/* What the link will show, in words, before it is created — see ViewCustomiser for the same idea. */}
          <p data-testid="live-link-summary" className="text-xs text-text-secondary">{summary}</p>

          {create.isError && (
            <p className="text-xs text-danger">
              {ar ? 'تعذّر إنشاء الرابط. تأكد من وجود حملات في هذا المشروع.' : 'Could not create the link. Check this project has campaigns.'}
            </p>
          )}

          <Button data-testid="live-link-create" loading={create.isPending} disabled={name.trim() === '' || selectedAccounts.length === 0} onClick={() => create.mutate()}>
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
 * The request's per-account choices: only an account the operator narrowed gets an entry, and an
 * account without one means «all of its campaigns / all of its ads». A campaign ticked under two
 * accounts is sent under both.
 */
function accountSelection(
  accountIds: string[],
  campaignIds: string[],
  adIds: string[],
  campaigns: LiveBuilderOptions['campaigns'],
  ads: LiveBuilderAd[],
): Record<string, { campaign_ids?: string[]; ad_ids?: string[] }> {
  const out: Record<string, { campaign_ids?: string[]; ad_ids?: string[] }> = {}
  for (const id of accountIds) {
    const own = campaignIds.filter((cid) => campaigns.find((c) => c.id === cid)?.accounts?.some((a) => a.id === id))
    const ownAds = adIds.filter((aid) => own.includes(ads.find((a) => a.id === aid)?.campaign_id ?? ''))
    const entry: { campaign_ids?: string[]; ad_ids?: string[] } = {}
    if (own.length > 0) entry.campaign_ids = own
    if (ownAds.length > 0) entry.ad_ids = ownAds
    if (Object.keys(entry).length > 0) out[id] = entry
  }
  return out
}

/** Level 1 — the ad accounts. Search, select all, clear, and a count; at least one is required. */
function AccountPicker({ ar, accounts, selected, query, onQuery, onToggle, onAll, onClear }: {
  ar: boolean
  accounts: AdAccountRef[]
  selected: string[]
  query: string
  onQuery: (v: string) => void
  onToggle: (id: string) => void
  onAll: () => void
  onClear: () => void
}) {
  const needle = query.trim().toLocaleLowerCase()
  const shown = accounts.filter((a) => needle === '' || `${a.name} ${a.provider}`.toLocaleLowerCase().includes(needle))

  return (
    <div className="grid gap-2" data-testid="live-builder-accounts">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="text-xs font-bold text-text-muted">{ar ? 'الحسابات الإعلانية' : 'Ad accounts'}</span>
        <span className="flex items-center gap-2 text-[11px] text-text-muted">
          <span data-testid="live-builder-accounts-count">{ar ? `${selected.length} من ${accounts.length} محدد` : `${selected.length} of ${accounts.length} selected`}</span>
          <button type="button" onClick={onAll} className="font-bold text-brand-700 hover:underline">{ar ? 'تحديد الكل' : 'Select all'}</button>
          <button type="button" onClick={onClear} disabled={selected.length === 0} className="font-bold text-brand-700 hover:underline disabled:opacity-40">{ar ? 'مسح' : 'Clear'}</button>
        </span>
      </div>
      {accounts.length > 6 && (
        <input
          type="search"
          aria-label={ar ? 'ابحث عن حساب إعلاني' : 'Search ad accounts'}
          data-testid="live-builder-account-search"
          value={query}
          onChange={(e) => onQuery(e.target.value)}
          placeholder={ar ? 'ابحث باسم الحساب' : 'Search by account name'}
          className="min-w-0 rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-text-primary"
        />
      )}
      {accounts.length === 0 ? (
        <p className="text-xs text-text-muted">{ar ? 'لا حسابات إعلانية مرتبطة بهذا المشروع بعد.' : 'No ad accounts are bound to this project yet.'}</p>
      ) : (
        <div className="grid max-h-36 gap-1 overflow-y-auto">
          {shown.map((a) => (
            <label key={a.id} className="flex items-center gap-2 text-xs">
              <input type="checkbox" checked={selected.includes(a.id)} onChange={() => onToggle(a.id)} data-testid={`live-builder-account-${a.id}`} className="h-3.5 w-3.5 shrink-0 accent-brand-600" />
              <span className="min-w-0 flex-1 truncate">{a.name}</span>
              <span className="shrink-0 text-text-muted"><PlatformMark platform={a.provider} size={12} /></span>
            </label>
          ))}
          {shown.length === 0 && <p className="text-xs text-text-muted">{ar ? 'لا حساب يطابق البحث.' : 'No account matches the search.'}</p>}
        </div>
      )}
      {selected.length === 0 && accounts.length > 0 && (
        <p className="text-[11px] text-danger" data-testid="live-builder-accounts-required">
          {ar ? 'اختر حسابًا واحدًا على الأقل — لا يُشمل أي حساب تلقائيًا.' : 'Choose at least one — no account is included by default.'}
        </p>
      )}
    </div>
  )
}

/** Level 3 — the ads inside the ticked campaigns. Nothing ticked = every ad of those campaigns. */
function AdPicker({ ar, ads, loading, tooMany, campaigns, selected, query, onQuery, onToggle, onClear }: {
  ar: boolean
  ads: LiveBuilderAd[]
  loading: boolean
  tooMany: boolean
  campaigns: LiveBuilderOptions['campaigns']
  selected: string[]
  query: string
  onQuery: (v: string) => void
  onToggle: (id: string) => void
  onClear: () => void
}) {
  const needle = query.trim().toLocaleLowerCase()
  const nameOf = (id: string) => campaigns.find((c) => c.id === id)?.name ?? ''
  const shown = ads.filter((a) => needle === '' || `${a.name} ${nameOf(a.campaign_id)}`.toLocaleLowerCase().includes(needle))

  if (tooMany) return null

  return (
    <div className="grid gap-2" data-testid="live-builder-ads">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="text-xs font-bold text-text-muted">{ar ? 'الإعلانات' : 'Ads'}</span>
        <span className="flex items-center gap-2 text-[11px] text-text-muted">
          <span data-testid="live-builder-ads-count">
            {selected.length === 0 ? (ar ? 'كل إعلانات الحملات المحددة' : 'All ads of the ticked campaigns') : (ar ? `${selected.length} من ${ads.length} محدد` : `${selected.length} of ${ads.length} selected`)}
          </span>
          <button type="button" onClick={onClear} disabled={selected.length === 0} className="font-bold text-brand-700 hover:underline disabled:opacity-40">{ar ? 'مسح' : 'Clear'}</button>
        </span>
      </div>
      {loading ? (
        <Skeleton className="h-10 w-full" />
      ) : ads.length === 0 ? (
        <p className="text-xs text-text-muted">{ar ? 'لا إعلانات مسجلة لهذه الحملات.' : 'No ads are recorded for these campaigns.'}</p>
      ) : (
        <>
          {ads.length > 8 && (
            <input
              type="search"
              aria-label={ar ? 'ابحث عن إعلان' : 'Search ads'}
              data-testid="live-builder-ad-search"
              value={query}
              onChange={(e) => onQuery(e.target.value)}
              placeholder={ar ? 'ابحث باسم الإعلان أو الحملة' : 'Search by ad or campaign'}
              className="min-w-0 rounded-lg border border-border bg-surface px-2 py-1.5 text-xs text-text-primary"
            />
          )}
          <div className="grid max-h-36 gap-1 overflow-y-auto">
            {shown.map((a) => (
              <label key={a.id} className="flex items-center gap-2 text-xs">
                <input type="checkbox" checked={selected.includes(a.id)} onChange={() => onToggle(a.id)} className="h-3.5 w-3.5 shrink-0 accent-brand-600" />
                <span className="min-w-0 flex-1 truncate">{a.name}<span className="text-text-muted">{' · '}{nameOf(a.campaign_id)}</span></span>
              </label>
            ))}
          </div>
        </>
      )}
    </div>
  )
}
