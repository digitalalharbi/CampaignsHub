import { mediaFitClass } from '@/features/content/adPreview'
import { useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { usePortalBase } from '@/app/portalPath'
import { CheckCircle2, Copy, History, Pause, Pencil, Play, Plus, Rocket, XCircle } from 'lucide-react'
import type { UnifiedCampaign } from './types'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { Input } from '@/components/ui/Input'
import { Textarea } from '@/components/ui/Textarea'
import { Select } from '@/components/ui/Select'
import { DateField } from '@/components/ui/DateField'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { money } from '@/features/analytics/format'
import { fmtDateTime } from '@/lib/datetime'
import { useUi } from '@/stores/ui'
import { campaignStatusLabel, campaignStatusTone, providerLabel } from './labels'
import { useCampaignActivity } from './metrics'
import {
  ACTION_LABELS, LEVEL_ACTIONS, NEEDS_AMOUNT, STATE_LABELS, createOnPlatform, familyName, goalName, objectiveName, parseCountries,
  performWrite, removalConsequence, strategyName, useWriteOptions,
  type ActionState, type CreativeOption, type WriteActionKey, type WriteEntity, type WriteResult,
} from './providerWrites'

/**
 * CAMPAIGN-MGMT-WRITE-001 — the campaign's platform entities, operated from here.
 *
 * Each platform campaign, its ad sets and its ads, with exactly the actions the PLATFORM allows and
 * this reader may take — and, beside every action that is not offered, the reason. A change is sent
 * to the platform, and the screen says what the platform answered: its confirmation (with the
 * request id it gave), or its refusal in its own words. Nothing is shown as done until it is.
 */
type Dialog =
  | { kind: 'action'; entity: WriteEntity; action: Exclude<WriteActionKey, 'pause' | 'resume'> }
  | { kind: 'create' }
  | null

type Banner = { tone: 'success' | 'danger' | 'info'; text: string } | null

const INACTIVE = new Set(['archived', 'deleted', 'removed'])

export function CampaignControlPanel({ campaign, projectId, onOpenHistory }: { campaign: UnifiedCampaign; projectId: string; onOpenHistory?: () => void }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const locale = ar ? 'ar' : 'en'
  const options = useWriteOptions(projectId, campaign.id)
  const activity = useCampaignActivity(projectId, campaign.id)
  const queryClient = useQueryClient()
  const [dialog, setDialog] = useState<Dialog>(null)
  const [banner, setBanner] = useState<Banner>(null)

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['projects', projectId, 'campaigns', campaign.id] })
    void queryClient.invalidateQueries({ queryKey: ['project', projectId, 'campaigns'] })
  }

  const describe = (result: WriteResult, entity: WriteEntity | null, action: string): Banner => {
    const platform = entity ? providerLabel(entity.provider, locale) : ''
    if (result.ok) {
      const what = ACTION_LABELS[action as WriteActionKey]?.[locale] ?? action
      const copy = result.new_external_id
        ? (ar ? ` — أُنشئت نسخة (المعرّف ${result.new_external_id}) وتظهر هنا بعد المزامنة التالية` : ` — a copy was made (id ${result.new_external_id}); it appears here after the next sync`)
        : ''
      const rid = result.request_id ? (ar ? ` · معرّف الطلب ${result.request_id}` : ` · request ${result.request_id}`) : ''

      return { tone: 'success', text: ar ? `أكّدت ${platform}: ${what} — ${entity?.name ?? ''}${copy}${rid}` : `${platform} confirmed: ${what} — ${entity?.name ?? ''}${copy}${rid}` }
    }
    const reason = result.refusal && result.refusal in STATE_LABELS
      ? STATE_LABELS[result.refusal as Exclude<ActionState, 'available'>][locale]
      : (result.message ?? (ar ? 'لم يُنفَّذ التغيير.' : 'The change was not made.'))

    return { tone: 'danger', text: result.refusal === 'provider_refused' ? (ar ? `رفضت ${platform}: ${reason}` : `${platform} refused: ${reason}`) : reason }
  }

  const write = useMutation({
    mutationFn: (v: { entity: WriteEntity; action: WriteActionKey; extra?: Record<string, unknown> }) =>
      performWrite(projectId, campaign.id, { level: v.entity.level, entity_id: v.entity.id, action: v.action, ...(v.extra ?? {}) }),
    onSuccess: (result, v) => {
      setBanner(describe(result, v.entity, v.action))
      if (result.ok) setDialog(null)
      refresh()
    },
    onError: () => setBanner({ tone: 'danger', text: ar ? 'تعذّر الوصول إلى الخادم — لم يُرسل التغيير.' : 'The server could not be reached — nothing was sent.' }),
  })

  const create = useMutation({
    mutationFn: (body: { external_account_id: string; objective: string; daily_budget?: number; launch?: boolean }) => createOnPlatform(projectId, campaign.id, body),
    onSuccess: (result) => {
      if (result.ok) {
        setDialog(null)
        setBanner({
          tone: 'success',
          text: ar
            ? `أُنشئت الحملة على المنصة (المعرّف ${result.external_id})${result.launched ? ' وشُغّلت' : ' موقوفةً'}${result.launch_message ? ` — لم تُشغَّل: ${result.launch_message}` : ''}`
            : `Created on the platform (id ${result.external_id})${result.launched ? ' and launched' : ', paused'}${result.launch_message ? ` — not launched: ${result.launch_message}` : ''}`,
        })
      } else {
        setBanner(describe(result, null, 'create'))
      }
      refresh()
    },
  })

  const entities = options.data?.entities ?? []
  const campaigns = entities.filter((e) => e.level === 'campaign')
  const childrenOf = (id: string, level: 'ad_set' | 'ad') => entities.filter((e) => e.level === level && e.parent_id === id)
  const looseAds = entities.filter((e) => e.level === 'ad' && !entities.some((s) => s.level === 'ad_set' && s.id === e.parent_id))
  const changes = useMemo(() => (activity.data ?? []).filter((e) => e.action.startsWith('campaign.provider')).slice(0, 5), [activity.data])

  if (options.isLoading) return <Skeleton className="h-64" />
  if (options.isError) {
    return <QueryFailure error={options.error} ar={ar} testId="control-failure" onRetry={() => void options.refetch()} fallbackTitle={ar ? 'تعذّرت قراءة ما يمكن تغييره' : 'What can be changed could not be read'} />
  }

  return (
    <section data-testid="campaign-control" className="space-y-4">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h2 className="font-heading text-lg font-extrabold text-text-primary">{ar ? 'التحكم على المنصات' : 'Platform control'}</h2>
          <p className="text-sm text-text-secondary">
            {ar ? 'كل تغيير يُرسل إلى المنصة نفسها ويُسجَّل باسمك؛ لا يظهر هنا إلا ما أكّدته المنصة.' : 'Every change goes to the platform itself and is recorded under your name; only what the platform confirms is shown here.'}
          </p>
        </div>
        <Button data-testid="control-create" variant="secondary" onClick={() => setDialog({ kind: 'create' })}>
          <Plus size={15} aria-hidden /> {ar ? 'إنشاء على منصة' : 'Create on a platform'}
        </Button>
      </header>

      {banner && (
        <div data-testid="control-banner" data-tone={banner.tone} role="status" className={`flex items-start gap-2 rounded-xl border px-3.5 py-2.5 text-sm ${banner.tone === 'success' ? 'border-success/40 bg-success/10' : banner.tone === 'danger' ? 'border-danger/40 bg-danger/10' : 'border-info/40 bg-info/10'}`}>
          {banner.tone === 'success' ? <CheckCircle2 size={16} className="mt-0.5 shrink-0 text-success" aria-hidden /> : <XCircle size={16} className="mt-0.5 shrink-0 text-danger" aria-hidden />}
          <span className="min-w-0 break-words text-text-primary">{banner.text}</span>
        </div>
      )}

      {campaigns.length === 0 ? (
        <EmptyState
          title={ar ? 'لا حملة على أي منصة بعد' : 'Not on any platform yet'}
          description={ar
            ? 'هذه الحملة غير مرتبطة بحملة على منصة إعلانية. أنشئها على منصة من حساب محدَّد لهذا المشروع، أو اربطها بحملة موجودة من الإعدادات.'
            : 'This campaign is not linked to a platform campaign. Create it on a platform from an account selected for this project, or link an existing one from Settings.'}
        />
      ) : (
        <ul className="space-y-3">
          {campaigns.map((c) => (
            <li key={c.id} data-testid={`control-campaign-${c.id}`} className="rounded-2xl border border-border bg-surface p-4">
              <EntityRow entity={c} ar={ar} busy={write.isPending} onQuick={(action) => write.mutate({ entity: c, action })} onOpen={(action) => setDialog({ kind: 'action', entity: c, action })} prominent />
              {childrenOf(c.id, 'ad_set').length > 0 && (
                <ul className="mt-3 space-y-2 border-s-2 border-border ps-3">
                  {childrenOf(c.id, 'ad_set').map((s) => (
                    <li key={s.id} data-testid={`control-ad-set-${s.id}`} className="rounded-xl bg-surface-secondary p-3">
                      <EntityRow entity={s} ar={ar} busy={write.isPending} onQuick={(action) => write.mutate({ entity: s, action })} onOpen={(action) => setDialog({ kind: 'action', entity: s, action })} />
                      {childrenOf(s.id, 'ad').length > 0 && (
                        <ul className="mt-2 space-y-1.5 border-s border-border ps-3">
                          {childrenOf(s.id, 'ad').map((a) => (
                            <li key={a.id} data-testid={`control-ad-${a.id}`}>
                              <EntityRow entity={a} ar={ar} busy={write.isPending} onQuick={(action) => write.mutate({ entity: a, action })} onOpen={(action) => setDialog({ kind: 'action', entity: a, action })} compact />
                            </li>
                          ))}
                        </ul>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </li>
          ))}
          {looseAds.length > 0 && (
            <li className="rounded-2xl border border-border bg-surface p-4">
              <p className="mb-2 text-xs text-text-muted">{ar ? 'إعلانات بلا مجموعة' : 'Ads with no ad set'}</p>
              {looseAds.map((a) => (
                <EntityRow key={a.id} entity={a} ar={ar} busy={write.isPending} onQuick={(action) => write.mutate({ entity: a, action })} onOpen={(action) => setDialog({ kind: 'action', entity: a, action })} compact />
              ))}
            </li>
          )}
        </ul>
      )}

      <section data-testid="control-history" className="rounded-2xl border border-border bg-surface p-4">
        <div className="flex items-baseline justify-between gap-2">
          <h3 className="flex items-center gap-1.5 text-sm font-bold text-text-primary"><History size={15} aria-hidden /> {ar ? 'آخر التغييرات على المنصات' : 'Latest platform changes'}</h3>
          {onOpenHistory && <button type="button" onClick={onOpenHistory} className="text-xs font-bold text-brand-600 hover:underline">{ar ? 'السجل كاملًا' : 'Full history'}</button>}
        </div>
        {changes.length === 0 ? (
          <p className="mt-2 text-sm text-text-muted">{ar ? 'لم يُرسل أي تغيير إلى منصة من هنا بعد.' : 'No change has been sent to a platform from here yet.'}</p>
        ) : (
          <ul className="mt-2 divide-y divide-border text-sm">
            {changes.map((e) => {
              const before = (e.before ?? {}) as Record<string, unknown>
              const action = String(before.action ?? '')
              return (
                <li key={e.id} className="flex flex-wrap items-baseline justify-between gap-2 py-2">
                  <span className="min-w-0">
                    <Badge tone={e.action === 'campaign.provider_write_refused' ? 'danger' : 'success'}>{e.label}</Badge>{' '}
                    <span className="font-semibold text-text-primary">{ACTION_LABELS[action as WriteActionKey]?.[locale] ?? action}</span>{' '}
                    <span className="text-text-secondary">{String(before.name ?? '')} · {providerLabel(String(before.provider ?? ''), locale)}</span>
                  </span>
                  <span className="text-xs text-text-muted">{e.actor} · <span dir="ltr">{fmtDateTime(e.at)}</span></span>
                </li>
              )
            })}
          </ul>
        )}
      </section>

      {dialog?.kind === 'action' && (
        <ActionDialog
          entity={dialog.entity}
          action={dialog.action}
          creatives={options.data?.creatives?.[dialog.entity.provider] ?? []}
          ar={ar}
          busy={write.isPending}
          onClose={() => setDialog(null)}
          onSubmit={(extra) => write.mutate({ entity: dialog.entity, action: dialog.action, extra })}
        />
      )}
      {dialog?.kind === 'create' && (
        <CreateDialog
          campaign={campaign}
          projectId={projectId}
          accounts={options.data?.create ?? []}
          ar={ar}
          busy={create.isPending}
          onClose={() => setDialog(null)}
          onSubmit={(body) => create.mutate(body)}
        />
      )}
    </section>
  )
}

function EntityRow({ entity, ar, busy, onQuick, onOpen, prominent = false, compact = false }: {
  entity: WriteEntity
  ar: boolean
  busy: boolean
  onQuick: (action: 'pause' | 'resume') => void
  onOpen: (action: Exclude<WriteActionKey, 'pause' | 'resume'>) => void
  prominent?: boolean
  compact?: boolean
}) {
  const locale = ar ? 'ar' : 'en'
  const status = entity.status ?? ''
  const inactive = INACTIVE.has(status)
  const quick: 'pause' | 'resume' | null = inactive ? null : status === 'paused' ? 'resume' : 'pause'
  const secondary = LEVEL_ACTIONS[entity.level] as Array<Exclude<WriteActionKey, 'pause' | 'resume'>>
  const offered = secondary.filter((a) => entity.actions[a] === 'available' && !(inactive && a !== 'duplicate'))
  const withheld = (quick ? [quick, ...secondary] : secondary)
    .filter((a) => entity.actions[a] !== 'available' && entity.actions[a] !== 'provider_unsupported')
  const forbidden = secondary.filter((a) => entity.actions[a] === 'provider_unsupported')
  const budget = entity.daily_budget !== null
    ? `${money(entity.daily_budget, entity.currency ?? '')} ${ar ? 'يوميًا' : 'daily'}`
    : entity.lifetime_budget !== null ? `${money(entity.lifetime_budget, entity.currency ?? '')} ${ar ? 'إجمالًا' : 'lifetime'}` : null
  /* A demo row exists on no platform: one badge says so, and no control is drawn for it. */
  const demo = (Object.values(entity.actions) as ActionState[]).some((v) => v === 'demo')
  const levelName = entity.level === 'campaign' ? (ar ? 'حملة' : 'Campaign') : entity.level === 'ad_set' ? (ar ? 'مجموعة' : 'Ad set') : (ar ? 'إعلان' : 'Ad')

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            {prominent && <Badge tone="neutral">{providerLabel(entity.provider, locale)}</Badge>}
            <span className="text-[11px] text-text-muted">{levelName}</span>
            <span className={`truncate font-semibold text-text-primary ${compact ? 'text-xs' : 'text-sm'}`}>{entity.name ?? entity.external_id}</span>
            <Badge tone={campaignStatusTone(status)}>{campaignStatusLabel(status, locale)}</Badge>
            {demo && <Badge tone="neutral" data-testid={`control-demo-${entity.id}`}>{ar ? 'تجريبي' : 'Demo'}</Badge>}
          </div>
          {!compact && (
            <p className="mt-0.5 text-xs text-text-secondary">
              {entity.account?.name && <span>{entity.account.name} · </span>}
              <span dir="ltr">{entity.external_id}</span>
              {budget && <span> · <span dir="ltr">{budget}</span></span>}
              {entity.bid_strategy && <span> · {strategyName(entity.bid_strategy.toUpperCase(), ar)}</span>}
              {entity.targeting?.countries && entity.targeting.countries.length > 0 && <span> · <span dir="ltr">{entity.targeting.countries.join(', ')}</span></span>}
              {entity.targeting?.age && <span> · <span dir="ltr">{entity.targeting.age}</span></span>}
            </p>
          )}
        </div>
        {!demo && <div className="flex flex-wrap items-center gap-1.5">
          {quick && entity.actions[quick] !== 'available' && entity.actions[quick] !== 'provider_unsupported' && (
            <Button data-testid={`control-${quick}-${entity.id}`} size="sm" variant="secondary" disabled title={STATE_LABELS[entity.actions[quick] as Exclude<ActionState, 'available'>][locale]}>
              {quick === 'pause' ? <Pause size={14} aria-hidden /> : <Play size={14} aria-hidden />} {ACTION_LABELS[quick][locale]}
            </Button>
          )}
          {quick && entity.actions[quick] === 'available' && (
            <Button data-testid={`control-${quick}-${entity.id}`} size="sm" variant={quick === 'pause' ? 'secondary' : 'primary'} disabled={busy} onClick={() => onQuick(quick)}>
              {quick === 'pause' ? <Pause size={14} aria-hidden /> : <Play size={14} aria-hidden />} {ACTION_LABELS[quick][locale]}
            </Button>
          )}
          {!compact && withheld.filter((a) => a !== 'pause' && a !== 'resume' && !(inactive && a !== 'duplicate')).map((a) => (
            <Button key={a} data-testid={`control-${a}-${entity.id}`} size="sm" variant="ghost" disabled title={STATE_LABELS[entity.actions[a] as Exclude<ActionState, 'available'>][locale]}>
              {ACTION_LABELS[a][locale]}
            </Button>
          ))}
          {offered.map((a) => (
            <Button key={a} data-testid={`control-${a}-${entity.id}`} size="sm" variant={a === 'delete' ? 'danger' : 'ghost'} disabled={busy} onClick={() => onOpen(a)}>
              {a === 'rename' && <Pencil size={13} aria-hidden />}
              {a === 'duplicate' && <Copy size={13} aria-hidden />}
              {ACTION_LABELS[a][locale]}
            </Button>
          ))}
        </div>}
      </div>
      {!compact && !demo && (withheld.length > 0 || forbidden.length > 0) && (
        <p data-testid={`control-withheld-${entity.id}`} className="text-[11px] text-text-muted">
          {/* One line per REASON, not per action: the reason is what the reader needs, said once. */}
          {Object.entries(groupByReason(withheld, entity)).map(([reason, actions], i) => (
            <span key={reason}>
              {i > 0 && ' · '}
              {STATE_LABELS[reason as Exclude<ActionState, 'available'>][locale]}: {actions.map((a) => ACTION_LABELS[a][locale]).join(ar ? '، ' : ', ')}
            </span>
          ))}
          {withheld.length > 0 && forbidden.length > 0 && ' · '}
          {forbidden.length > 0 && (
            <span>
              {ar ? `لا تسمح به ${providerLabel(entity.provider, locale)}: ` : `${providerLabel(entity.provider, locale)} does not allow: `}
              {forbidden.map((a) => ACTION_LABELS[a][locale]).join(ar ? '، ' : ', ')}
            </span>
          )}
        </p>
      )}
    </div>
  )
}

function groupByReason(actions: WriteActionKey[], entity: WriteEntity): Record<string, WriteActionKey[]> {
  const out: Record<string, WriteActionKey[]> = {}
  for (const a of actions) {
    const reason = entity.actions[a]
    ;(out[reason] ??= []).push(a)
  }

  return out
}

function ActionDialog({ entity, action, creatives, ar, busy, onClose, onSubmit }: {
  entity: WriteEntity
  action: Exclude<WriteActionKey, 'pause' | 'resume'>
  creatives: CreativeOption[]
  ar: boolean
  busy: boolean
  onClose: () => void
  onSubmit: (extra: Record<string, unknown>) => void
}) {
  const locale = ar ? 'ar' : 'en'
  const [name, setName] = useState(entity.name ?? '')
  const [kind, setKind] = useState<'daily' | 'lifetime'>(entity.lifetime_budget !== null && entity.budget_kinds.includes('lifetime') ? 'lifetime' : (entity.budget_kinds[0] ?? 'daily'))
  const [amount, setAmount] = useState(String((kind === 'daily' ? entity.daily_budget : entity.lifetime_budget) ?? ''))
  const [startsAt, setStartsAt] = useState('')
  const [endsAt, setEndsAt] = useState('')
  const [strategy, setStrategy] = useState(entity.bid_strategy && entity.bid_strategies.includes(entity.bid_strategy) ? entity.bid_strategy : (entity.bid_strategies[0] ?? ''))
  const [bid, setBid] = useState('')
  const [understood, setUnderstood] = useState(false)
  const [childName, setChildName] = useState('')
  const [goal, setGoal] = useState(entity.optimization_goals[0] ?? '')
  const [childBudget, setChildBudget] = useState('')
  const [countryText, setCountryText] = useState((entity.targeting?.countries ?? []).join(', '))
  const [ageMin, setAgeMin] = useState(entity.targeting?.age?.split('-')[0] ?? '18')
  const [ageMax, setAgeMax] = useState(entity.targeting?.age?.split('-')[1] ?? '65')
  const [genders, setGenders] = useState(entity.targeting?.genders ?? 'all')
  const [placementMode, setPlacementMode] = useState<'automatic' | 'custom'>(entity.targeting?.placement_config === 'custom' ? 'custom' : 'automatic')
  const [families, setFamilies] = useState<string[]>(entity.targeting?.placements?.filter((p) => entity.placement_families.includes(p)) ?? [])
  const [creativeId, setCreativeId] = useState(creatives[0]?.id ?? '')
  const [url, setUrl] = useState(entity.destination_url ?? '')
  const [headlineText, setHeadlineText] = useState('')
  const [descriptionText, setDescriptionText] = useState('')

  const countries = parseCountries(countryText)
  const google = entity.provider === 'google'
  // A Google responsive search ad: one line per headline (3–15, ≤30 characters) and per description (2–4, ≤90).
  const lines = (text: string) => Array.from(new Set(text.split('\n').map((l) => l.trim()).filter((l) => l !== '')))
  const headlines = lines(headlineText)
  const descriptions = lines(descriptionText)
  const searchAdValid = headlines.length >= 3 && headlines.length <= 15 && headlines.every((h) => h.length <= 30)
    && descriptions.length >= 2 && descriptions.length <= 4 && descriptions.every((d) => d.length <= 90) && /^https:\/\/\S+$/.test(url)
  const destructive = action === 'archive' || action === 'delete'
  const valid = action === 'create_ad_set' ? childName.trim() !== '' && (google ? Number(bid) > 0 : (countries.length > 0 && goal !== ''))
    : action === 'create_ad' ? childName.trim() !== '' && (google ? searchAdValid : creativeId !== '')
      : action === 'targeting' ? countries.length > 0 && Number(ageMin) >= 13 && Number(ageMax) >= Number(ageMin) && Number(ageMax) <= 65
        : action === 'placements' ? (placementMode === 'automatic' || families.length > 0)
          : action === 'creative' ? creativeId !== ''
            : action === 'destination' ? /^https:\/\/\S+$/.test(url)
              : action === 'rename' ? name.trim().length > 0
    : action === 'budget' ? Number(amount) > 0
      : action === 'schedule' ? (startsAt !== '' || endsAt !== '')
        : action === 'bid_strategy' ? strategy !== '' && (!NEEDS_AMOUNT.has(strategy) || Number(bid) > 0)
          : destructive ? understood : true

  const submit = () => {
    if (action === 'create_ad_set') {
      return onSubmit(google
        ? { name: childName.trim(), bid_amount: Number(bid) }
        : { name: childName.trim(), optimization_goal: goal, countries, ...(Number(childBudget) > 0 ? { daily_budget: Number(childBudget) } : {}) })
    }
    if (action === 'create_ad') {
      return onSubmit(google
        ? { name: childName.trim(), headlines, descriptions, url }
        : { name: childName.trim(), creative_id: creativeId })
    }
    if (action === 'targeting') return onSubmit({ countries, age_min: Number(ageMin), age_max: Number(ageMax), genders })
    if (action === 'placements') return onSubmit(placementMode === 'automatic' ? { mode: 'automatic' } : { mode: 'custom', platforms: families })
    if (action === 'creative') return onSubmit({ creative_id: creativeId })
    if (action === 'destination') return onSubmit({ url })
    if (action === 'rename') return onSubmit({ name: name.trim() })
    if (action === 'budget') return onSubmit({ [kind === 'daily' ? 'daily_budget' : 'lifetime_budget']: Number(amount) })
    if (action === 'schedule') return onSubmit({ ...(startsAt ? { starts_at: startsAt } : {}), ...(endsAt ? { ends_at: endsAt } : {}) })
    if (action === 'bid_strategy') return onSubmit({ strategy, ...(NEEDS_AMOUNT.has(strategy) ? { bid_amount: Number(bid) } : {}) })
    if (destructive) return onSubmit({ confirm: true })

    return onSubmit({})
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={`${ACTION_LABELS[action][locale]} — ${entity.name ?? entity.external_id}`}
      footer={(
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{ar ? 'إلغاء' : 'Cancel'}</Button>
          <Button data-testid="control-dialog-submit" variant={destructive ? 'danger' : 'primary'} disabled={!valid} loading={busy} onClick={submit}>
            {ar ? `إرسال إلى ${providerLabel(entity.provider, locale)}` : `Send to ${providerLabel(entity.provider, locale)}`}
          </Button>
        </div>
      )}
    >
      <div className="space-y-3 text-sm">
        {action === 'rename' && (
          <label className="block">
            <span className="text-text-secondary">{ar ? 'الاسم على المنصة' : 'Name on the platform'}</span>
            <Input data-testid="control-input-name" value={name} onChange={(e) => setName(e.target.value)} maxLength={250} />
          </label>
        )}
        {action === 'budget' && (
          <>
            {entity.budget_kinds.length > 1 && (
              <Select data-testid="control-input-kind" value={kind} onChange={(e) => setKind(e.target.value as 'daily' | 'lifetime')} options={entity.budget_kinds.map((k) => ({ value: k, label: k === 'daily' ? (ar ? 'يومية' : 'Daily') : (ar ? 'إجمالية' : 'Lifetime') }))} />
            )}
            <label className="block">
              <span className="text-text-secondary">{ar ? 'المبلغ' : 'Amount'} {entity.currency ? <span dir="ltr">({entity.currency})</span> : null}</span>
              <Input data-testid="control-input-amount" type="number" min="0" step="0.01" inputMode="decimal" dir="ltr" value={amount} onChange={(e) => setAmount(e.target.value)} />
            </label>
            {entity.provider === 'tiktok' && <p className="text-xs text-text-muted">{ar ? 'تيك توك يغيّر المبلغ ويُبقي نوع الميزانية كما هو على المنصة.' : 'TikTok changes the amount and keeps the budget type as set on the platform.'}</p>}
          </>
        )}
        {action === 'schedule' && (
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="block"><span className="text-text-secondary">{ar ? 'البداية' : 'Start'}</span><DateField value={startsAt} onChange={setStartsAt} /></label>
            <label className="block"><span className="text-text-secondary">{ar ? 'النهاية' : 'End'}</span><DateField value={endsAt} onChange={setEndsAt} /></label>
          </div>
        )}
        {action === 'bid_strategy' && (
          <>
            <Select data-testid="control-input-strategy" value={strategy} onChange={(e) => setStrategy(e.target.value)} options={entity.bid_strategies.map((s) => ({ value: s, label: strategyName(s, ar) }))} />
            {NEEDS_AMOUNT.has(strategy) && (
              <label className="block">
                <span className="text-text-secondary">{ar ? 'قيمة المزايدة' : 'Bid amount'} {entity.currency ? <span dir="ltr">({entity.currency})</span> : null}</span>
                <Input data-testid="control-input-bid" type="number" min="0" step="0.01" inputMode="decimal" dir="ltr" value={bid} onChange={(e) => setBid(e.target.value)} />
              </label>
            )}
          </>
        )}
        {(action === 'create_ad_set' || action === 'create_ad') && (
          <label className="block">
            <span className="text-text-secondary">{ar ? 'الاسم' : 'Name'}</span>
            <Input data-testid="control-input-child-name" value={childName} onChange={(e) => setChildName(e.target.value)} maxLength={250} />
          </label>
        )}
        {action === 'create_ad_set' && !google && (
          <>
            <label className="block">
              <span className="text-text-secondary">{ar ? 'هدف التحسين' : 'Optimisation goal'}</span>
              <Select data-testid="control-input-goal" value={goal} onChange={(e) => setGoal(e.target.value)} options={entity.optimization_goals.map((g) => ({ value: g, label: goalName(g, ar) }))} />
            </label>
            <label className="block">
              <span className="text-text-secondary">{ar ? 'الدول (رموز من حرفين)' : 'Countries (two-letter codes)'}</span>
              <Input data-testid="control-input-countries" dir="ltr" value={countryText} onChange={(e) => setCountryText(e.target.value)} placeholder="SA, AE" />
            </label>
            <label className="block">
              <span className="text-text-secondary">{ar ? 'ميزانية يومية (اختيارية)' : 'Daily budget (optional)'} {entity.currency ? <span dir="ltr">({entity.currency})</span> : null}</span>
              <Input data-testid="control-input-child-budget" type="number" min="0" step="0.01" dir="ltr" value={childBudget} onChange={(e) => setChildBudget(e.target.value)} />
            </label>
          </>
        )}
        {action === 'create_ad_set' && google && (
          <label className="block">
            <span className="text-text-secondary">{ar ? 'أقصى تكلفة للنقرة' : 'Max CPC bid'} {entity.currency ? <span dir="ltr">({entity.currency})</span> : null}</span>
            <Input data-testid="control-input-bid" type="number" min="0" step="0.01" dir="ltr" value={bid} onChange={(e) => setBid(e.target.value)} />
          </label>
        )}
        {action === 'create_ad_set' && <p className="text-xs text-text-muted">{ar ? 'تُنشأ موقوفة؛ لا تصرف شيئًا حتى تشغّلها.' : 'Created paused; it spends nothing until you resume it.'}</p>}
        {action === 'create_ad' && google && (
          <>
            <label className="block">
              <span className="text-text-secondary">{ar ? 'العناوين — سطر لكل عنوان (3 إلى 15، حتى 30 حرفًا)' : 'Headlines, one per line (3 to 15, up to 30 characters)'}</span>
              <Textarea data-testid="control-input-headlines" rows={4} value={headlineText} onChange={(e) => setHeadlineText(e.target.value)} />
              <span data-testid="control-headlines-count" className={`text-xs ${headlines.some((h) => h.length > 30) ? 'text-danger' : 'text-text-muted'}`}>{ar ? `${headlines.length} عنوان` : `${headlines.length} headlines`}{headlines.some((h) => h.length > 30) ? (ar ? ' · أحدها أطول من 30 حرفًا' : ' · one is longer than 30 characters') : ''}</span>
            </label>
            <label className="block">
              <span className="text-text-secondary">{ar ? 'الأوصاف — سطر لكل وصف (2 إلى 4، حتى 90 حرفًا)' : 'Descriptions, one per line (2 to 4, up to 90 characters)'}</span>
              <Textarea data-testid="control-input-descriptions" rows={3} value={descriptionText} onChange={(e) => setDescriptionText(e.target.value)} />
              <span className={`text-xs ${descriptions.some((d) => d.length > 90) ? 'text-danger' : 'text-text-muted'}`}>{ar ? `${descriptions.length} وصف` : `${descriptions.length} descriptions`}{descriptions.some((d) => d.length > 90) ? (ar ? ' · أحدها أطول من 90 حرفًا' : ' · one is longer than 90 characters') : ''}</span>
            </label>
            <label className="block">
              <span className="text-text-secondary">{ar ? 'رابط الوجهة (https)' : 'Landing URL (https)'}</span>
              <Input data-testid="control-input-url" type="url" dir="ltr" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://" />
            </label>
            <p className="text-xs text-text-muted">{ar ? 'يُنشأ موقوفًا؛ إعلان Google هو عناوينه وأوصافه ورابطه.' : 'Created paused; a Google ad is its headlines, descriptions and URL.'}</p>
          </>
        )}
        {((action === 'create_ad' && !google) || action === 'creative') && (
          creatives.length === 0
            ? <p data-testid="control-no-creatives" className="rounded-xl border border-dashed border-border px-3 py-2 text-text-secondary">{ar ? 'لا محتوى لهذا المشروع على هذه المنصة بمعرّف منصة بعد.' : 'This project has no creative with a platform id on this platform yet.'}</p>
            : (
              <ul data-testid="control-creative-picker" className="max-h-64 space-y-1.5 overflow-y-auto">
                {creatives.map((c) => (
                  <li key={c.id}>
                    <label className={`flex items-center gap-2 rounded-xl border px-2.5 py-2 ${creativeId === c.id ? 'border-brand-500' : 'border-border'}`}>
                      <input type="radio" name="creative" checked={creativeId === c.id} onChange={() => setCreativeId(c.id)} className="accent-brand-600" />
                      {c.thumbnail_url ? <img src={c.thumbnail_url} alt="" className={`h-10 w-10 shrink-0 rounded-md ${mediaFitClass(null, null, 'thumb')}`} /> : <span className="h-10 w-10 shrink-0 rounded-md bg-surface-secondary" />}
                      <span className="min-w-0 truncate text-text-primary">{c.name ?? c.id}</span>
                      {c.format && <Badge tone="neutral">{c.format}</Badge>}
                    </label>
                  </li>
                ))}
              </ul>
            )
        )}
        {action === 'targeting' && (
          <>
            <label className="block">
              <span className="text-text-secondary">{ar ? 'الدول (رموز من حرفين)' : 'Countries (two-letter codes)'}</span>
              <Input data-testid="control-input-countries" dir="ltr" value={countryText} onChange={(e) => setCountryText(e.target.value)} placeholder="SA, AE" />
            </label>
            <div className="grid grid-cols-2 gap-3">
              <label className="block"><span className="text-text-secondary">{ar ? 'من عمر' : 'Age from'}</span><Input data-testid="control-input-age-min" type="number" min="13" max="65" dir="ltr" value={ageMin} onChange={(e) => setAgeMin(e.target.value)} /></label>
              <label className="block"><span className="text-text-secondary">{ar ? 'إلى عمر' : 'Age to'}</span><Input data-testid="control-input-age-max" type="number" min="13" max="65" dir="ltr" value={ageMax} onChange={(e) => setAgeMax(e.target.value)} /></label>
            </div>
            <Select data-testid="control-input-genders" value={genders} onChange={(e) => setGenders(e.target.value)} options={[
              { value: 'all', label: ar ? 'الجميع' : 'Everyone' }, { value: 'male', label: ar ? 'رجال' : 'Men' }, { value: 'female', label: ar ? 'نساء' : 'Women' },
            ]} />
            <p className="text-xs text-text-muted">{ar ? 'تُستبدل الدول والعمر والجنس فقط؛ تبقى الاهتمامات والجماهير كما هي على المنصة.' : 'Only countries, age and gender are replaced; interests and audiences stay as the platform holds them.'}</p>
            {entity.provider === 'tiktok' && (
              <p data-testid="control-tiktok-age-bands" className="text-xs text-text-muted">{ar ? 'تيك توك تستهدف العمر بفئات ثابتة (13-17، 18-24، 25-34، 35-44، 45-54، 55+)؛ تُختار الفئات الواقعة داخل المدى.' : 'TikTok targets age in fixed bands (13-17, 18-24, 25-34, 35-44, 45-54, 55+); the bands inside the range are chosen.'}</p>
            )}
          </>
        )}
        {action === 'placements' && (
          <>
            <Select data-testid="control-input-placement-mode" value={placementMode} onChange={(e) => setPlacementMode(e.target.value as 'automatic' | 'custom')} options={[
              { value: 'automatic', label: ar ? 'تلقائية (توصي بها المنصة)' : 'Automatic (platform recommended)' },
              { value: 'custom', label: ar ? 'أختارها بنفسي' : 'I choose' },
            ]} />
            {placementMode === 'custom' && (
              <div className="flex flex-wrap gap-3" data-testid="control-input-families">
                {entity.placement_families.map((f) => (
                  <label key={f} className="flex items-center gap-1.5">
                    <input type="checkbox" checked={families.includes(f)} onChange={(e) => setFamilies((cur) => e.target.checked ? [...cur, f] : cur.filter((x) => x !== f))} className="h-4 w-4 accent-brand-600" />
                    <span>{familyName(f, ar)}</span>
                  </label>
                ))}
              </div>
            )}
          </>
        )}
        {action === 'destination' && (
          <label className="block">
            <span className="text-text-secondary">{ar ? 'رابط الوجهة (https)' : 'Landing URL (https)'}</span>
            <Input data-testid="control-input-url" type="url" dir="ltr" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://" />
          </label>
        )}
        {action === 'duplicate' && (
          <p className="text-text-secondary">{ar ? 'تُنشئ ميتا نسخة كاملة موقوفة؛ لا تصرف شيئًا حتى تشغّلها.' : 'Meta makes a full copy, paused; it spends nothing until you resume it.'}</p>
        )}
        {destructive && (
          <>
            <p data-testid="control-consequence" className="rounded-xl border border-danger/40 bg-danger/10 px-3 py-2 text-text-primary">{removalConsequence(entity.provider, action, ar)}</p>
            <label className="flex items-center gap-2">
              <input data-testid="control-understood" type="checkbox" checked={understood} onChange={(e) => setUnderstood(e.target.checked)} className="h-4 w-4 accent-brand-600" />
              <span>{ar ? 'أفهم ما سيحدث على المنصة' : 'I understand what will happen on the platform'}</span>
            </label>
          </>
        )}
      </div>
    </Modal>
  )
}

function CreateDialog({ campaign, projectId, accounts, ar, busy, onClose, onSubmit }: {
  campaign: UnifiedCampaign
  projectId: string
  accounts: Array<{ id: string; provider: string; name: string | null; currency: string | null; state: ActionState; launch_permitted: boolean; objectives: string[] }>
  ar: boolean
  busy: boolean
  onClose: () => void
  onSubmit: (body: { external_account_id: string; objective: string; daily_budget?: number; launch?: boolean }) => void
}) {
  const locale = ar ? 'ar' : 'en'
  const portalBase = usePortalBase()
  const usable = accounts.filter((a) => a.state === 'available')
  const [accountId, setAccountId] = useState(usable[0]?.id ?? '')
  const account = accounts.find((a) => a.id === accountId)
  const [objective, setObjective] = useState(account?.objectives[0] ?? '')
  const [budget, setBudget] = useState('')
  const [launch, setLaunch] = useState(false)

  return (
    <Modal
      open
      onClose={onClose}
      title={ar ? `إنشاء «${campaign.name}» على منصة` : `Create “${campaign.name}” on a platform`}
      footer={(
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{ar ? 'إلغاء' : 'Cancel'}</Button>
          <Button data-testid="control-create-submit" disabled={!account || account.state !== 'available' || objective === ''} loading={busy}
            onClick={() => onSubmit({ external_account_id: accountId, objective, ...(Number(budget) > 0 ? { daily_budget: Number(budget) } : {}), launch })}>
            <Rocket size={14} aria-hidden /> {launch ? (ar ? 'إنشاء وتشغيل' : 'Create and launch') : (ar ? 'إنشاء موقوفة' : 'Create paused')}
          </Button>
        </div>
      )}
    >
      <div className="space-y-3 text-sm">
        {accounts.length === 0 && (
          <p data-testid="control-create-none" className="rounded-xl border border-dashed border-border px-3 py-3 text-text-secondary">
            {ar ? 'لا حساب إعلاني محدَّد لهذا المشروع — الإنشاء يكون على حساب اخترته للمشروع. ' : 'No ad account is selected for this project — a campaign is created on an account chosen for it. '}
            <Link to={`${portalBase}/projects/${projectId}/integrations`} className="font-bold text-brand-600 hover:underline">{ar ? 'اختر حسابات المشروع' : 'Choose the project’s accounts'}</Link>
          </p>
        )}
        <ul className="space-y-1.5" data-testid="control-create-accounts">
          {accounts.map((a) => (
            <li key={a.id}>
              <label className={`flex items-center justify-between gap-2 rounded-xl border px-3 py-2 ${a.state === 'available' ? 'cursor-pointer border-border hover:border-brand-400' : 'border-dashed border-border opacity-70'}`}>
                <span className="flex items-center gap-2">
                  <input type="radio" name="account" disabled={a.state !== 'available'} checked={accountId === a.id} onChange={() => { setAccountId(a.id); setObjective(a.objectives[0] ?? '') }} className="accent-brand-600" />
                  <span className="font-semibold text-text-primary">{providerLabel(a.provider, locale)}</span>
                  <span className="text-text-secondary">{a.name}</span>
                </span>
                {a.state !== 'available' && <span className="text-xs text-text-muted">{STATE_LABELS[a.state as Exclude<ActionState, 'available'>]?.[locale]}</span>}
              </label>
            </li>
          ))}
        </ul>
        {account && account.state === 'available' && (
          <>
            <label className="block">
              <span className="text-text-secondary">{ar ? 'الهدف على المنصة' : 'Objective on the platform'}</span>
              <Select data-testid="control-create-objective" value={objective} onChange={(e) => setObjective(e.target.value)} options={account.objectives.map((o) => ({ value: o, label: objectiveName(o, ar) }))} />
            </label>
            <label className="block">
              <span className="text-text-secondary">{ar ? 'ميزانية يومية' : 'Daily budget'} {account.currency ? <span dir="ltr">({account.currency})</span> : null}</span>
              <Input data-testid="control-create-budget" type="number" min="0" step="0.01" inputMode="decimal" dir="ltr" value={budget} onChange={(e) => setBudget(e.target.value)} />
            </label>
            {account.launch_permitted && (
              <label className="flex items-center gap-2">
                <input data-testid="control-create-launch" type="checkbox" checked={launch} onChange={(e) => setLaunch(e.target.checked)} className="h-4 w-4 accent-brand-600" />
                <span>{ar ? 'شغّلها فور إنشائها' : 'Launch as soon as it is created'}</span>
              </label>
            )}
            <p className="text-xs text-text-muted">{ar ? 'تُنشأ موقوفة دائمًا، ثم تُشغَّل فقط إن طلبت ذلك. الهدف لا يتغيّر بعد الإنشاء على أي منصة.' : 'Always created paused, then launched only if you ask. No platform lets the objective change after creation.'}</p>
          </>
        )}
      </div>
    </Modal>
  )
}
