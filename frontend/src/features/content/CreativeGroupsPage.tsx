import { useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, ArrowLeft, Layers, Unlink } from 'lucide-react'
import { formatMetric, metricLabel, metricState } from './metrics'
import { imageLoading } from './format'
import {
  getCreativeGroup,
  listCreativeGroups,
  ungroupCreative,
  type CreativeGroupDetail,
  type CreativeGroupSummary,
  type CreativeMetrics,
} from './api'
import { Button } from '@/components/ui/Button'
import { DateField } from '@/components/ui/DateField'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { marketingPathLabel, objectiveLabel, providerLabel } from '@/features/campaigns/labels'

/**
 * §15.8 and §15.13 — the same asset across platforms, as one thing.
 *
 * ## Why this page exists at all
 *
 * An agency uploads one film to Snapchat, TikTok and Meta and gets three creative ids back. The
 * library shows three rows, each holding a third of the budget, and every judgement made from them
 * is made about a third of the truth. This page reads them as the unit somebody actually produced.
 *
 * ## What it will not say
 *
 * Spend and impressions add across platforms. CPA and ROAS do NOT add across OBJECTIVES — an
 * awareness cut and a sales cut of one film are the same asset and are not the same question. When
 * the members disagree about the objective the backend sends an empty `headline_metrics` and a
 * stated reason, and this page shows the reason and the per-platform table instead of a blended
 * number that answers neither. The rule lives in the response, not here: a UI-side rule is one every
 * other surface has to remember separately.
 *
 * ## One source
 *
 * The roll-up is `CreativeMetrics::aggregate` over the rows the library itself shows, so a group's
 * total cannot drift from the cards it is made of (§15.17). Nothing on this page computes a figure.
 */

const COPY = {
  ar: {
    title: 'مجموعات المحتوى',
    subtitle: 'المحتوى نفسه على أكثر من منصة، مقروءًا كوحدة واحدة.',
    back: 'رجوع إلى المجموعات',
    backToLibrary: 'المكتبة',
    from: 'من',
    to: 'إلى',
    empty: 'لا توجد مجموعات في هذه المساحة بعد.',
    emptyHint: 'اختر محتويين أو أكثر في المكتبة ثم ادمجهما كأصل واحد.',
    platforms: 'المنصات',
    members: 'المحتويات',
    method: 'طريقة التجميع',
    methods: {
      file_hash: 'بصمة الملف',
      thumbnail_fingerprint: 'بصمة الصورة المصغّرة',
      confirmed: 'مطابقة مؤكَّدة',
      manual: 'دمج يدوي',
    } as Record<string, string>,
    confirmed: 'مؤكَّدة بواسطة شخص',
    unconfirmed: 'مطابقة آلية بانتظار التأكيد',
    total: 'إجمالي المجموعة',
    byPlatform: 'الأداء حسب المنصة',
    perPlatformNote: 'سطور المنصات تجمع إلى إجمالي المجموعة؛ الجمع واحد على المستويين.',
    mixed: 'أهداف مختلفة داخل المجموعة',
    open: 'فتح المجموعة',
    openCreative: 'تفاصيل المحتوى',
    split: 'فصل من المجموعة',
    splitting: 'جارٍ الفصل…',
    splitDone: 'تم فصل المحتوى من المجموعة.',
    dissolved: 'لم يتبقَّ في المجموعة سوى محتوى واحد، فحُلَّت المجموعة.',
    audit: 'سجل القرارات',
    auditEmpty: 'لا توجد قرارات مسجَّلة على هذه المجموعة.',
    actions: {
      'creative.group.created': 'دمج',
      'creative.group.split': 'فصل',
    } as Record<string, string>,
    by: 'بواسطة',
    objective: 'الهدف',
    path: 'المسار التسويقي',
    creative: 'المحتوى',
    platform: 'المنصة',
    noPermission: 'لا تملك صلاحية تعديل المجموعات.',
    loadError: 'تعذّر تحميل المجموعات.',
    groupError: 'تعذّر تحميل هذه المجموعة.',
  },
  en: {
    title: 'Creative groups',
    subtitle: 'The same content on more than one platform, read as one unit.',
    back: 'Back to groups',
    backToLibrary: 'Library',
    from: 'From',
    to: 'To',
    empty: 'There are no groups in this workspace yet.',
    emptyHint: 'Select two or more creatives in the library and merge them as one asset.',
    platforms: 'Platforms',
    members: 'Creatives',
    method: 'Grouped by',
    methods: {
      file_hash: 'File hash',
      thumbnail_fingerprint: 'Thumbnail fingerprint',
      confirmed: 'Confirmed match',
      manual: 'Manual merge',
    } as Record<string, string>,
    confirmed: 'Confirmed by a person',
    unconfirmed: 'Automatic match awaiting confirmation',
    total: 'Group total',
    byPlatform: 'Performance by platform',
    perPlatformNote: 'The platform lines add back to the group total — it is one summation at two levels.',
    mixed: 'Mixed objectives in this group',
    open: 'Open group',
    openCreative: 'Creative details',
    split: 'Split from group',
    splitting: 'Splitting…',
    splitDone: 'The creative was split from its group.',
    dissolved: 'Only one creative was left, so the group was dissolved.',
    audit: 'Decision log',
    auditEmpty: 'No decisions are recorded on this group.',
    actions: {
      'creative.group.created': 'Merged',
      'creative.group.split': 'Split',
    } as Record<string, string>,
    by: 'by',
    objective: 'Objective',
    path: 'Marketing path',
    creative: 'Creative',
    platform: 'Platform',
    noPermission: 'You do not have permission to change groups.',
    loadError: 'The groups could not be loaded.',
    groupError: 'This group could not be loaded.',
  },
} as const

const isoDaysAgo = (days: number) => {
  const d = new Date()
  d.setDate(d.getDate() - days)
  return d.toISOString().slice(0, 10)
}

/** The library's own portal path, so the links back land where the reader came from. */
const libraryPathFor = (portal: 'app' | 'agency') => `/${portal}/content`

export function CreativeGroupsPage({ portal }: { portal: 'app' | 'agency' }) {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const t = COPY[ar ? 'ar' : 'en']
  const canLink = useAuth((s) => s.hasPermission('campaigns.link'))

  /*
   * The period and the open group both live in the address.
   *
   * A group is a decision somebody wants to point a colleague at, and «open the groups page then
   * find the one called X» is not a link. Refresh, Back and a pasted URL all reopen the same panel.
   */
  const [params, setParams] = useSearchParams()
  const from = params.get('from') ?? isoDaysAgo(29)
  const to = params.get('to') ?? isoDaysAgo(0)
  const openId = params.get('group')

  const setParam = (key: string, value: string | null) => {
    const next = new URLSearchParams(params)
    if (value === null || value === '') next.delete(key)
    else next.set(key, value)
    setParams(next, { replace: key !== 'group' })
  }

  const window = useMemo(() => ({ from, to }), [from, to])

  const listQuery = useQuery({
    queryKey: ['creative-groups', window],
    queryFn: () => listCreativeGroups({ ...window, per_page: 24 }),
    placeholderData: keepPreviousData,
  })

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-text-primary">{t.title}</h1>
          <p className="mt-1 text-sm text-text-secondary">{t.subtitle}</p>
        </div>
        <div className="flex flex-wrap items-end gap-2">
          <DateField value={from} onChange={(v) => setParam('from', v)} aria-label={t.from} />
          <DateField value={to} onChange={(v) => setParam('to', v)} aria-label={t.to} />
          <Link
            to={libraryPathFor(portal)}
            className="rounded-md border border-border px-3 py-2 text-sm text-text-secondary hover:bg-surface-hover"
          >
            {t.backToLibrary}
          </Link>
        </div>
      </header>

      {openId ? (
        <GroupDetail
          groupId={openId}
          window={window}
          portal={portal}
          canLink={canLink}
          onClose={() => setParam('group', null)}
        />
      ) : listQuery.isPending ? (
        <Skeleton className="h-48" />
      ) : listQuery.isError ? (
        <ErrorState title={t.loadError} error={listQuery.error} ar={ar} />
      ) : (listQuery.data?.groups.length ?? 0) === 0 ? (
        <div className="rounded-lg border border-dashed border-border p-8 text-center">
          <Layers className="mx-auto h-6 w-6 text-text-secondary" aria-hidden />
          <p className="mt-2 text-sm text-text-primary">{t.empty}</p>
          <p className="mt-1 text-xs text-text-secondary">{t.emptyHint}</p>
        </div>
      ) : (
        <ul className="grid gap-3 md:grid-cols-2">
          {listQuery.data?.groups.map((group) => (
            <li key={group.id}>
              <GroupCard group={group} t={t} ar={ar} locale={locale} currency={listQuery.data?.currency ?? null} onOpen={() => setParam('group', group.id)} />
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function GroupCard({
  group,
  t,
  ar,
  locale,
  currency,
  onOpen,
}: {
  group: CreativeGroupSummary
  t: (typeof COPY)['ar'] | (typeof COPY)['en']
  ar: boolean
  locale: 'ar' | 'en'
  /** CREATIVE-MONEY-TRUTH-001 — from the payload; a card never names a currency of its own. */
  currency: string | null
  onOpen: () => void
}) {
  return (
    <article className="flex h-full flex-col gap-3 rounded-lg border border-border bg-surface p-4">
      <div className="flex items-start justify-between gap-2">
        <div>
          <h2 className="text-sm font-semibold text-text-primary">{group.name}</h2>
          <p className="mt-1 text-xs text-text-secondary">
            {t.method}: {t.methods[group.method] ?? group.method}
            {' · '}
            {group.confirmed ? t.confirmed : t.unconfirmed}
          </p>
        </div>
        <span className="shrink-0 rounded-full bg-surface-hover px-2 py-0.5 text-xs text-text-secondary" dir="ltr">
          {group.creative_count}
        </span>
      </div>

      <p className="text-xs text-text-secondary">
        {t.platforms}: {group.providers.map((p) => providerLabel(p, locale)).join(ar ? '، ' : ', ')}
      </p>

      {group.mixed_objectives && <MixedNotice group={group} t={t} ar={ar} />}

      <GroupFigures group={group} locale={locale} currency={currency} />

      <Button variant="secondary" className="mt-auto self-start" onClick={onOpen}>
        {t.open}
      </Button>
    </article>
  )
}

/**
 * The refusal, stated where the number would have been.
 *
 * Not a footnote: the reason a group has no ROAS is the most important thing about it, and a reader
 * who only sees an absence concludes the sync is broken.
 */
function MixedNotice({
  group,
  t,
  ar,
}: {
  group: CreativeGroupSummary
  t: (typeof COPY)['ar'] | (typeof COPY)['en']
  ar: boolean
}) {
  return (
    <p className="flex items-start gap-2 rounded-md border border-warning/40 bg-warning/10 p-2 text-xs text-text-primary">
      <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning" aria-hidden />
      <span>
        <strong className="font-medium">{t.mixed}</strong>
        <br />
        {(ar ? group.mixed_reason_ar : group.mixed_reason_en) ?? ''}
      </span>
    </p>
  )
}

/**
 * The figures a group is entitled to.
 *
 * `headline_metrics` is empty exactly when the members disagree about the objective, and in that
 * case the additive figures are still shown — what is withheld is the JUDGEMENT, not the arithmetic.
 */
function GroupFigures({ group, locale, currency }: { group: CreativeGroupSummary; locale: 'ar' | 'en'; currency: string | null }) {
  const keys = group.headline_metrics.length > 0 ? group.headline_metrics : ['spend', 'impressions', 'clicks']

  return (
    <dl className="grid grid-cols-2 gap-2 sm:grid-cols-3">
      {keys.map((key) => (
        <div key={key} className="rounded-md border border-border p-2">
          <dt className="text-[11px] text-text-secondary">{metricLabel(key, locale)}</dt>
          <dd className="mt-0.5 text-sm font-medium text-text-primary" dir="ltr">
            {formatMetric(metricState(group.metrics, key), key, locale, currency)}
          </dd>
        </div>
      ))}
    </dl>
  )
}

function GroupDetail({
  groupId,
  window,
  portal,
  canLink,
  onClose,
}: {
  groupId: string
  window: { from: string; to: string }
  portal: 'app' | 'agency'
  canLink: boolean
  onClose: () => void
}) {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const t = COPY[ar ? 'ar' : 'en']
  const client = useQueryClient()
  const [notice, setNotice] = useState<string | null>(null)

  const detail = useQuery({
    queryKey: ['creative-group', groupId, window],
    queryFn: () => getCreativeGroup(groupId, window),
    placeholderData: keepPreviousData,
  })

  const split = useMutation({
    mutationFn: (creativeId: string) => ungroupCreative(creativeId),
    onSuccess: (result) => {
      setNotice(result.group_dissolved ? t.dissolved : t.splitDone)
      void client.invalidateQueries({ queryKey: ['creative-groups'] })
      void client.invalidateQueries({ queryKey: ['creative-library'] })
      // A dissolved group has nothing left to show, so the panel closes rather than 404-ing.
      if (result.group_dissolved) onClose()
      else void client.invalidateQueries({ queryKey: ['creative-group', groupId] })
    },
  })

  const backLink = (
    <button type="button" onClick={onClose} className="flex items-center gap-1 text-sm text-primary hover:underline">
      <ArrowLeft className="h-4 w-4 ltr:rotate-0 rtl:rotate-180" aria-hidden />
      {t.back}
    </button>
  )

  if (detail.isPending) {
    return (
      <div className="space-y-3">
        {backLink}
        <Skeleton className="h-64" />
      </div>
    )
  }

  if (detail.isError || !detail.data) {
    return (
      <div className="space-y-3">
        {backLink}
        <ErrorState title={t.groupError} error={detail.error} ar={ar} />
      </div>
    )
  }

  const group: CreativeGroupDetail = detail.data

  return (
    <div className="space-y-5" data-testid="creative-group-detail">
      {backLink}

      <section className="space-y-3 rounded-lg border border-border bg-surface p-4">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div>
            <h2 className="text-lg font-semibold text-text-primary">{group.name}</h2>
            <p className="mt-1 text-xs text-text-secondary">
              {t.method}: {t.methods[group.method] ?? group.method}
              {' · '}
              {group.confirmed ? t.confirmed : t.unconfirmed}
              {' · '}
              <span dir="ltr">
                {group.period.from} → {group.period.to}
              </span>
            </p>
          </div>
        </div>

        {group.mixed_objectives && <MixedNotice group={group} t={t} ar={ar} />}

        <h3 className="text-sm font-medium text-text-primary">{t.total}</h3>
        <GroupFigures group={group} locale={locale} currency={group.currency} />
      </section>

      {notice && (
        <p className="rounded-md border border-border bg-surface-hover p-2 text-xs text-text-primary" role="status">
          {notice}
        </p>
      )}

      <section className="space-y-2 rounded-lg border border-border bg-surface p-4">
        <h3 className="text-sm font-medium text-text-primary">{t.byPlatform}</h3>
        <p className="text-xs text-text-secondary">{t.perPlatformNote}</p>
        <PlatformTable group={group} locale={locale} t={t} />
      </section>

      <section className="space-y-3 rounded-lg border border-border bg-surface p-4">
        <h3 className="text-sm font-medium text-text-primary">{t.members}</h3>
        <ul className="space-y-2">
          {group.members.map((member) => (
            <li
              key={member.id}
              className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border p-2"
            >
              <div className="flex min-w-0 items-center gap-3">
                {member.preview.state === 'available' && member.preview.thumbnail_url ? (
                  <img
                    src={member.preview.thumbnail_url}
                    alt=""
                    loading={imageLoading(member.preview.thumbnail_url)}
                    className="h-12 w-12 shrink-0 rounded object-cover"
                  />
                ) : (
                  <span className="h-12 w-12 shrink-0 rounded bg-surface-hover" aria-hidden />
                )}
                <div className="min-w-0">
                  <Link
                    to={`${libraryPathFor(portal)}/${member.id}?from=${window.from}&to=${window.to}`}
                    className="block truncate text-sm font-medium text-primary hover:underline"
                  >
                    {member.name}
                  </Link>
                  <p className="mt-0.5 truncate text-xs text-text-secondary">
                    {providerLabel(member.provider, locale)}
                    {member.objective && ` · ${objectiveLabel(member.objective, locale)}`}
                    {` · ${marketingPathLabel(member.path, locale)}`}
                  </p>
                </div>
              </div>

              {canLink ? (
                <Button
                  variant="secondary"
                  onClick={() => split.mutate(member.id)}
                  disabled={split.isPending}
                  aria-label={`${t.split}: ${member.name}`}
                >
                  <Unlink className="h-4 w-4" aria-hidden />
                  {split.isPending ? t.splitting : t.split}
                </Button>
              ) : (
                <span className="text-xs text-text-secondary">{t.noPermission}</span>
              )}
            </li>
          ))}
        </ul>
      </section>

      <section className="space-y-2 rounded-lg border border-border bg-surface p-4">
        <h3 className="text-sm font-medium text-text-primary">{t.audit}</h3>
        {group.audit.length === 0 ? (
          <p className="text-xs text-text-secondary">{t.auditEmpty}</p>
        ) : (
          <ol className="space-y-1 text-xs text-text-secondary">
            {group.audit.map((entry) => (
              <li key={entry.id} className="flex flex-wrap items-baseline gap-2">
                <span className="font-medium text-text-primary">{t.actions[entry.action] ?? entry.action}</span>
                {entry.actor && (
                  <span>
                    {t.by} {entry.actor}
                  </span>
                )}
                {entry.at && (
                  <span dir="ltr" className="text-text-secondary">
                    {entry.at.slice(0, 16).replace('T', ' ')}
                  </span>
                )}
                {entry.group_dissolved && <span>· {t.dissolved}</span>}
              </li>
            ))}
          </ol>
        )}
      </section>
    </div>
  )
}

/** Which columns a per-platform table may show — the group's own headline set, or the additive three. */
function PlatformTable({
  group,
  locale,
  t,
}: {
  group: CreativeGroupDetail
  locale: 'ar' | 'en'
  t: (typeof COPY)['ar'] | (typeof COPY)['en']
}) {
  const keys = group.headline_metrics.length > 0 ? group.headline_metrics : ['spend', 'impressions', 'clicks']

  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[32rem] text-start text-sm">
        <thead>
          <tr className="border-b border-border text-xs text-text-secondary">
            {/* ANALYTICS-TABLES-001 — platform is prose and stays start-aligned; every count and metric
                column is centred, heading and figure together, so a figure sits under its own heading in
                both writing directions. */}
            <th scope="col" className="p-2 text-start font-medium">
              {t.platform}
            </th>
            <th scope="col" className="p-2 text-center font-medium">
              {t.members}
            </th>
            {keys.map((key) => (
              <th key={key} scope="col" className="p-2 text-center font-medium">
                {metricLabel(key, locale)}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {group.by_platform.map((line) => (
            <tr key={line.provider} className="border-b border-border/60 last:border-0">
              <th scope="row" className="p-2 text-start font-medium text-text-primary">
                {providerLabel(line.provider, locale)}
              </th>
              <td className="tnum p-2 text-center text-text-secondary" dir="ltr">
                {line.creative_count}
              </td>
              {keys.map((key) => (
                <td key={key} className="tnum p-2 text-center text-text-primary" dir="ltr">
                  {formatMetric(metricState(line.metrics as CreativeMetrics | null, key), key, locale, group.currency)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
