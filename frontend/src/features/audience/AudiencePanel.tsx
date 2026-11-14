import { useState } from 'react'
import { getData } from '@/lib/api/client'
import { useSwrQuery } from '@/lib/query/swr'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { ProviderName } from '@/components/ProviderMark'
import { useUi } from '@/stores/ui'
import { DeliveryGroupCard, DIMENSION_LABEL, SiteGroupCard, STATE_LABEL, TargetingList } from './AudienceBlocks'
import type { AudienceReading } from './types'

/**
 * AUDIENCE-001 — «الجمهور» in Analytics: one screen. Four kind chips (targeting · paid delivery · site · unpaid), a
 * dimension switch for paid delivery, the cards, and an availability matrix folded behind one disclosure.
 */
type Kind = 'paid_delivery' | 'targeting' | 'site_events' | 'organic'

export function AudiencePanel({ projectId, range }: { projectId: string | null; range: { from: string; to: string } }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const locale = ar ? 'ar' : 'en'
  const tr = (a: string, e: string) => (ar ? a : e)
  const [kind, setKind] = useState<Kind>('paid_delivery')
  const [dimension, setDimension] = useState<string | null>(null)
  const q = useSwrQuery({
    name: 'audience',
    scope: [projectId],
    window: [range.from, range.to],
    queryFn: () => getData<AudienceReading>(`/projects/${projectId}/audience?from=${range.from}&to=${range.to}`),
    enabled: Boolean(projectId),
  })

  if (q.isLoading) return <div data-testid="audience-loading" className="h-40 animate-pulse rounded-2xl bg-surface-secondary" />
  if (q.isError || !q.data) {
    return <QueryFailure error={q.error} ar={ar} testId="audience-failure" onRetry={() => void q.refetch()} fallbackTitle={tr('تعذّرت قراءة الجمهور', 'The audience could not be read')} />
  }
  const d = q.data
  const dims = [...new Set(d.paid_delivery.groups.map((g) => g.dimension))]
  const activeDim = dimension && dims.includes(dimension) ? dimension : dims[0] ?? null
  const kinds: { id: Kind; ar: string; en: string; n: number | null }[] = [
    { id: 'paid_delivery', ar: 'التسليم المدفوع', en: 'Paid delivery', n: d.paid_delivery.groups.length },
    { id: 'targeting', ar: 'المستهدف', en: 'Targeted', n: d.targeting.items.length },
    { id: 'site_events', ar: 'زوار الموقع', en: 'Site visitors', n: d.site_events.groups.length },
    { id: 'organic', ar: 'غير المدفوع', en: 'Unpaid', n: null },
  ]

  return (
    <div data-testid="audience-panel" className="flex min-w-0 flex-col gap-3">
      <div role="tablist" aria-label={tr('نوع بيانات الجمهور', 'Audience data kind')} className="flex min-w-0 flex-wrap gap-2">
        {kinds.map((k) => (
          <button key={k.id} type="button" role="tab" aria-selected={kind === k.id} data-testid={`audience-kind-${k.id}`} onClick={() => setKind(k.id)}
            className={`rounded-full border px-3 py-1 text-xs font-semibold ${kind === k.id ? 'border-brand-500 bg-brand-500/10 text-brand-700 dark:text-brand-300' : 'border-border text-text-secondary'}`}>
            {tr(k.ar, k.en)}{k.n !== null && <span className="tnum ms-1 opacity-70">{k.n}</span>}
          </button>
        ))}
      </div>

      {kind === 'paid_delivery' && (
        d.paid_delivery.groups.length === 0 ? (
          <Empty text={tr('لا يوجد تفصيل مدفوع مخزن لهذه الفترة — غير متاح، وليس صفرًا.', 'No paid breakdown stored for this period — unavailable, not zero.')} />
        ) : (
          <>
            <div className="flex min-w-0 flex-wrap gap-1.5" role="tablist" aria-label={tr('التقسيم', 'Dimension')}>
              {dims.map((dim) => (
                <button key={dim} type="button" role="tab" aria-selected={activeDim === dim} data-testid={`audience-dim-${dim}`} onClick={() => setDimension(dim)}
                  className={`rounded-lg px-2.5 py-1 text-xs ${activeDim === dim ? 'bg-surface-secondary font-bold text-text-primary' : 'text-text-secondary'}`}>
                  {tr(DIMENSION_LABEL[dim]?.ar ?? dim, DIMENSION_LABEL[dim]?.en ?? dim)}
                </button>
              ))}
            </div>
            <p className="text-xs text-text-secondary">{tr('كل منصة بمقامها — لا تُجمع المنصات ولا الوصول الفريد. «المنطقة» عند المنصة ليست موقع الاستهداف.', 'Each platform on its own denominator — never added. A platform\'s «region» is not the targeted location.')}</p>
            <div className="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
              {d.paid_delivery.groups.filter((g) => g.dimension === activeDim).map((g) => <DeliveryGroupCard key={`${g.provider}-${g.dimension}`} group={g} locale={locale} />)}
            </div>
          </>
        )
      )}
      {kind === 'targeting' && (d.targeting.items.length === 0 ? <Empty text={tr('لا مجموعات إعلانية نشطة بإعدادات مخزنة.', 'No active ad set with stored settings.')} /> : <TargetingList items={d.targeting.items} locale={locale} />)}
      {kind === 'site_events' && (d.site_events.groups.length === 0
        ? <Empty text={tr('لا قياس للموقع في هذه الفترة.', 'No site measurement for this period.')} />
        : <div className="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2">{d.site_events.groups.map((g) => <SiteGroupCard key={g.dimension} group={g} locale={locale} />)}</div>)}
      {kind === 'organic' && <Empty text={tr('المشاهدات والمشاركات غير المدفوعة لا تُجلب حاليًا — غير متاح.', 'Unpaid views and shares are not fetched — unavailable.')} />}

      <details data-testid="audience-availability" className="min-w-0 rounded-2xl border border-border bg-surface p-3 text-xs">
        <summary className="cursor-pointer font-semibold text-text-primary">{tr('ما المتاح من كل منصة', 'What each platform offers')}</summary>
        <div className="mt-2 flex flex-col gap-2">
          {Object.entries(d.capabilities).map(([provider, cap]) => (
            <div key={provider} className="min-w-0">
              <div className="mb-1 font-semibold"><ProviderName provider={provider} locale={locale} size={16} /></div>
              <div className="flex min-w-0 flex-wrap gap-1">
                {Object.entries(cap.dimensions).map(([dim, f]) => (
                  <span key={dim} className="max-w-full break-words rounded-full bg-surface-secondary px-2 py-0.5 text-xs text-text-secondary">
                    {tr(DIMENSION_LABEL[dim]?.ar ?? dim, DIMENSION_LABEL[dim]?.en ?? dim)}: {tr(STATE_LABEL[f.delivery]?.ar ?? f.delivery, STATE_LABEL[f.delivery]?.en ?? f.delivery)}{f.targeting ? tr(' · استهداف', ' · targeting') : ''}
                  </span>
                ))}
              </div>
            </div>
          ))}
        </div>
      </details>
    </div>
  )
}

function Empty({ text }: { text: string }) {
  return <p className="rounded-2xl border border-dashed border-border p-6 text-center text-sm text-text-secondary">{text}</p>
}
