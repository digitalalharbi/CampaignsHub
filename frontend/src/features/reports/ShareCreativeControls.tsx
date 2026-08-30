import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronDown, ChevronUp } from 'lucide-react'
import { listCreatives } from '@/features/content/api'
import { Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

/**
 * §15.12 — what a client link may show about the content, chosen by the operator.
 *
 * ## Two different kinds of control, kept visibly apart
 *
 * The first block is a CEILING: which creatives this link may reach at all. The second is a set of
 * VISIBILITY switches: what may be shown about the creatives it reaches. They are separated on
 * screen because they fail differently — an excluded creative cannot be opened by any address, while
 * a hidden download is a control that is not drawn. Presenting them as one list of tick-boxes would
 * invite an operator to treat «no download» as though it withheld the picture.
 *
 * That distinction is stated on the panel, not left to be inferred. An operator who needs a creative
 * genuinely unavailable has to exclude it, and this is the only place they will be told so.
 *
 * ## Everything starts off
 *
 * The switches default to false, matching the backend, so a link created by an operator who never
 * opened this panel shows no creative section at all. Nothing about a client's ad content is
 * published because a form had a helpful default.
 */

export interface CreativeSharing {
  /** The fifteen visibility switches, exactly as `CreativeVisibility` names them. */
  permissions: Record<string, boolean>
  /** An allow-list; empty means «whatever else the link's scope covers». */
  creativeIds: string[]
  /** A deny-list, applied whatever the allow-list says. */
  excludedIds: string[]
  /** Group allow-list, UNIONED with `creativeIds` — «this one, plus everything in that group». */
  groupIds: string[]
}

export const emptyCreativeSharing = (): CreativeSharing => ({
  permissions: {},
  creativeIds: [],
  excludedIds: [],
  groupIds: [],
})

/** The switches, grouped the way an operator thinks about them rather than alphabetically. */
const GROUPS: Array<{
  title: [string, string]
  keys: Array<{ key: string; ar: string; en: string; note?: [string, string] }>
}> = [
  {
    title: ['ما يمكن فتحه', 'What can be opened'],
    keys: [
      { key: 'video', ar: 'تشغيل الفيديو', en: 'Play video' },
      { key: 'image_zoom', ar: 'تكبير الصور', en: 'Zoom images' },
      {
        key: 'download',
        ar: 'تحميل الأصل',
        en: 'Download the asset',
        note: [
          'إيقافه يزيل الزر ورابط الملف. لمنع الوصول إلى إعلان فعليًا، استبعده أعلاه.',
          'Turning this off removes the button and the file URL. To truly withhold a ad, exclude it above.',
        ],
      },
      { key: 'comparison', ar: 'مقارنة الإعلانات', en: 'Compare ads' },
    ],
  },
  {
    title: ['نص الإعلان', 'Ad copy'],
    keys: [
      { key: 'ad_copy', ar: 'النص الأساسي', en: 'Body copy' },
      { key: 'headline', ar: 'العنوان', en: 'Headline' },
      { key: 'cta', ar: 'زر الإجراء', en: 'Call to action' },
      { key: 'destination_url', ar: 'رابط الوجهة', en: 'Destination URL' },
    ],
  },
  {
    title: ['الأرقام', 'Figures'],
    keys: [
      { key: 'spend', ar: 'الإنفاق', en: 'Spend' },
      { key: 'revenue', ar: 'الإيراد', en: 'Revenue' },
      { key: 'cpa', ar: 'تكلفة الطلب', en: 'Cost per order' },
      { key: 'roas', ar: 'العائد على الإنفاق', en: 'Return on ad spend' },
    ],
  },
  {
    title: ['التحليل', 'Analysis'],
    keys: [
      { key: 'insights', ar: 'التحليلات', en: 'Insights' },
      { key: 'recommendations', ar: 'التوصيات', en: 'Recommendations' },
    ],
  },
]

export function ShareCreativeControls({
  projectId,
  value,
  onChange,
}: {
  projectId: string
  value: CreativeSharing
  onChange: (next: CreativeSharing) => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const [open, setOpen] = useState(false)

  const on = (key: string) => value.permissions[key] === true

  /*
   * The dependencies are shown as they are ENFORCED, not as they were ticked.
   *
   * The server resolves `roas` to false when spend is hidden, because ROAS is revenue over spend and
   * showing it beside a hidden spend publishes the spend. A form that left the box ticked would be
   * telling the operator they had shared something they had not.
   */
  const resolved = (key: string): boolean => {
    if (!on('creatives')) return false
    if (key === 'cpa') return on('cpa') && on('spend')
    if (key === 'roas') return on('roas') && on('spend') && on('revenue')
    if (key === 'recommendations') return on('recommendations') && on('insights')
    return on(key)
  }

  const set = (key: string, next: boolean) =>
    onChange({ ...value, permissions: { ...value.permissions, [key]: next } })

  const creatives = useQuery({
    queryKey: ['share-creatives', projectId],
    queryFn: () => listCreatives({ per_page: 48 }, projectId),
    // Only fetched once the operator opens the section: a share modal that always loaded a project's
    // whole creative library would pay for a list most links never narrow.
    enabled: open && on('creatives'),
  })

  const rows = useMemo(() => creatives.data?.creatives ?? [], [creatives.data])

  const toggleIn = (list: 'creativeIds' | 'excludedIds' | 'groupIds', id: string) =>
    onChange({
      ...value,
      [list]: value[list].includes(id) ? value[list].filter((v) => v !== id) : [...value[list], id],
    })

  return (
    <div className="rounded-xl border border-border" data-testid="share-creatives">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center justify-between px-3 py-2.5 text-start"
      >
        <span className="text-sm">
          <b className="block font-semibold">{ar ? 'الإعلان' : 'Ad ads'}</b>
          <span className="text-xs text-text-secondary">
            {on('creatives')
              ? ar
                ? `مسموح — ${Object.values(value.permissions).filter(Boolean).length} خيار مفعّل`
                : `Shown — ${Object.values(value.permissions).filter(Boolean).length} options on`
              : ar
                ? 'لا يعرض هذا الرابط أي إعلان'
                : 'This link shows no ads'}
          </span>
        </span>
        {open ? <ChevronUp size={16} /> : <ChevronDown size={16} />}
      </button>

      {open && (
        <div className="grid gap-3 border-t border-border p-3">
          <label className="flex cursor-pointer items-center justify-between rounded-xl border border-border bg-surface-secondary px-3 py-2 text-sm">
            <span className="font-semibold">{ar ? 'عرض قسم الإعلان للعميل' : 'Show the ad section'}</span>
            <input
              type="checkbox"
              data-testid="creatives-master"
              checked={on('creatives')}
              onChange={(e) => set('creatives', e.target.checked)}
              className="h-4 w-4 accent-brand-600"
            />
          </label>

          {on('creatives') && (
            <>
              {/* The ceiling first: it decides what exists, before anything decides what is shown. */}
              <div className="grid gap-2 rounded-xl border border-border p-3">
                <span className="text-xs font-bold text-text-muted">
                  {ar ? 'أي إعلان يستطيع هذا الرابط الوصول إليه؟' : 'Which ads may this link reach?'}
                </span>

                {creatives.isLoading ? (
                  <Skeleton className="h-24 w-full" />
                ) : rows.length === 0 ? (
                  <p className="text-xs text-text-muted">
                    {ar ? 'لا يوجد إعلان مُزامَن في هذا المشروع بعد.' : 'No synced ads in this project yet.'}
                  </p>
                ) : (
                  <div className="grid max-h-48 gap-1 overflow-y-auto" data-testid="creative-ceiling">
                    {rows.map((c) => {
                      const included = value.creativeIds.includes(c.id)
                      const excluded = value.excludedIds.includes(c.id)
                      return (
                        <div key={c.id} className="flex items-center justify-between gap-2 rounded-lg px-1 py-1 text-xs">
                          <span className="min-w-0 flex-1 truncate">{c.name}</span>
                          <span className="flex shrink-0 gap-1">
                            <button
                              type="button"
                              data-testid={`include-${c.id}`}
                              onClick={() => toggleIn('creativeIds', c.id)}
                              className={`rounded border px-1.5 py-0.5 font-semibold ${
                                included ? 'border-brand-500 bg-[var(--brand-background)] text-brand-700' : 'border-border text-text-secondary'
                              }`}
                            >
                              {ar ? 'تحديد' : 'Include'}
                            </button>
                            <button
                              type="button"
                              data-testid={`exclude-${c.id}`}
                              onClick={() => toggleIn('excludedIds', c.id)}
                              className={`rounded border px-1.5 py-0.5 font-semibold ${
                                excluded ? 'border-danger bg-danger/10 text-danger' : 'border-border text-text-secondary'
                              }`}
                            >
                              {ar ? 'استبعاد' : 'Exclude'}
                            </button>
                            {c.group_id && (
                              <button
                                type="button"
                                onClick={() => toggleIn('groupIds', c.group_id!)}
                                className={`rounded border px-1.5 py-0.5 font-semibold ${
                                  value.groupIds.includes(c.group_id) ? 'border-brand-500 bg-[var(--brand-background)] text-brand-700' : 'border-border text-text-secondary'
                                }`}
                              >
                                {ar ? 'المجموعة' : 'Group'}
                              </button>
                            )}
                          </span>
                        </div>
                      )
                    })}
                  </div>
                )}

                <p className="text-[11px] text-text-muted">
                  {ar
                    ? 'اترك التحديد فارغًا ليشمل الرابط كل إعلان النطاق. المستبعَد لا يُفتح بأي رابط أو معرّف.'
                    : 'Leave the selection empty to cover every ad in the link’s scope. An excluded ad cannot be opened by any address or id.'}
                </p>
              </div>

              {GROUPS.map((group) => (
                <div key={group.title[1]} className="grid gap-1.5">
                  <span className="text-xs font-bold text-text-muted">{ar ? group.title[0] : group.title[1]}</span>
                  <div className="grid gap-1.5 sm:grid-cols-2">
                    {group.keys.map((item) => {
                      const enforced = resolved(item.key)
                      const overridden = on(item.key) && !enforced
                      return (
                        <label
                          key={item.key}
                          className="flex cursor-pointer items-start justify-between gap-2 rounded-xl border border-border px-3 py-2 text-sm"
                        >
                          <span className="min-w-0">
                            <span className="block">{ar ? item.ar : item.en}</span>
                            {overridden && (
                              <span className="block text-[11px] text-warning">
                                {ar
                                  ? 'غير معروض — يعتمد على مؤشر مخفي.'
                                  : 'Not shown — it depends on a figure you have hidden.'}
                              </span>
                            )}
                            {item.note && (
                              <span className="block text-[11px] text-text-muted">{ar ? item.note[0] : item.note[1]}</span>
                            )}
                          </span>
                          <input
                            type="checkbox"
                            data-testid={`creative-perm-${item.key}`}
                            checked={on(item.key)}
                            onChange={(e) => set(item.key, e.target.checked)}
                            className="mt-0.5 h-4 w-4 shrink-0 accent-brand-600"
                          />
                        </label>
                      )
                    })}
                  </div>
                </div>
              ))}
            </>
          )}
        </div>
      )}
    </div>
  )
}

/** The body fields a share request carries for this panel — empty lists omitted, never sent as `[]`. */
export function creativeSharingBody(value: CreativeSharing): Record<string, unknown> {
  return {
    creatives: value.permissions,
    ...(value.creativeIds.length ? { creative_ids: value.creativeIds } : {}),
    ...(value.excludedIds.length ? { excluded_creative_ids: value.excludedIds } : {}),
    ...(value.groupIds.length ? { creative_group_ids: value.groupIds } : {}),
  }
}
