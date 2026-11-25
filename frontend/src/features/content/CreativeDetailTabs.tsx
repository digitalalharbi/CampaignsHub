import type { ReactNode } from 'react'
import {
  Activity, BadgeCheck, CalendarClock, CircleAlert, Clock3, Fingerprint, Gauge, Lightbulb, Link2, Megaphone, ScanSearch,
  Eye, Heart, MousePointerClick, PlaySquare, ShoppingCart, Sparkles, TrendingDown, TrendingUp, Type, UserPlus, Zap,
} from 'lucide-react'
import { ProviderMark } from '@/components/ProviderMark'
import { Num } from '@/components/ui/Num'
import { formatPeriodRange } from '@/lib/period/formatPeriodLabel'
import { marketingPathLabel, objectiveLabel, providerLabel } from '@/features/campaigns/labels'
import { useUi, type Locale } from '@/stores/ui'
import { diagnosis, MIN_IMPRESSIONS, scoreContent, type Criterion, type Grade } from './contentScore'
import { creativeKindLabel } from './CreativesPage'
import { formatMetric, metricLabel } from './metrics'
import type { CreativeDetail, CreativeInsight } from './api'

/**
 * CONTENT-DETAIL-TABS-002 (Owner 2026-11-25, «التوصيات والتفاصيل بحاجة لتحسين، احتاج كل الاجزاء تفاصيل ابداعية») — the
 * two tabs that were still walls of text.
 *
 * «التوصيات»: each finding opens on its verdict, shows the figure it rests on against what it was measured against as two
 * bars, the ad's own supporting figures as chips, and the suggested action in its own highlighted box — nothing behind a
 * disclosure. The fatigue verdict is a gauge with its signals; «not enough data yet» is one quiet line with its reason.
 *
 * «التفاصيل»: the record in three cards — the ad, its timeline, and how it is measured — with every value the platform
 * sent; what it did not send is named once at the foot instead of a «غير مُرسَل» in every other cell.
 */

const SEVERITY: Record<CreativeInsight['severity'], { tone: string; bar: string; icon: ReactNode; ar: string; en: string }> = {
  warning: { tone: 'border-danger/30 bg-danger/5', bar: 'bg-danger', icon: <CircleAlert size={18} className="text-danger" aria-hidden />, ar: 'تنبيه', en: 'Warning' },
  opportunity: { tone: 'border-brand-500/30 bg-brand-500/5', bar: 'bg-brand-500', icon: <Sparkles size={18} className="text-brand-600" aria-hidden />, ar: 'فرصة تحسين', en: 'Opportunity' },
  positive: { tone: 'border-success/30 bg-success/5', bar: 'bg-success', icon: <BadgeCheck size={18} className="text-success" aria-hidden />, ar: 'نقطة قوة', en: 'Strength' },
}

const CONFIDENCE: Record<string, { ar: string; en: string; dots: number }> = {
  high: { ar: 'ثقة عالية', en: 'High confidence', dots: 3 },
  medium: { ar: 'ثقة متوسطة', en: 'Medium confidence', dots: 2 },
  insufficient_data: { ar: 'بيانات قليلة', en: 'Little data', dots: 1 },
}

const fmt = (key: string, value: number | null, locale: Locale, currency: string) =>
  value === null ? '—' : formatMetric({ kind: 'value', value }, key, locale, currency)

function CompareBars({ item, locale, currency }: { item: CreativeInsight; locale: Locale; currency: string }) {
  const m = item.movement
  if (!m || m.current === null || m.previous === null) return null
  const ar = locale === 'ar'
  const max = Math.max(Math.abs(m.current), Math.abs(m.previous)) || 1
  const against = item.comparison === 'peers' ? (ar ? 'وسيط المسار' : 'Path median') : (ar ? 'الفترة السابقة' : 'Previous period')
  const up = (m.change ?? 0) >= 0
  const rows: Array<[string, number, string]> = [
    [ar ? 'هذا الإعلان' : 'This ad', m.current, SEVERITY[item.severity].bar],
    [against, m.previous, 'bg-border-strong'],
  ]
  return (
    <div className="rounded-xl bg-surface p-3" data-testid="insight-compare">
      <div className="mb-2 flex items-center justify-between gap-2 text-xs">
        <span className="font-semibold text-text-secondary">{metricLabel(m.metric, locale)}</span>
        {m.change !== null && (
          <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-bold ${up ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'}`}>
            {up ? <TrendingUp size={12} aria-hidden /> : <TrendingDown size={12} aria-hidden />}
            <Num>{`${up ? '+' : ''}${Math.round(m.change * 100)}%`}</Num>
          </span>
        )}
      </div>
      <div className="space-y-1.5">
        {rows.map(([label, value, bar]) => (
          <div key={label} className="grid grid-cols-[88px_minmax(0,1fr)_auto] items-center gap-2 text-xs">
            <span className="truncate text-text-secondary">{label}</span>
            <span className="h-2 overflow-hidden rounded-full bg-surface-secondary">
              <span className={`block h-full rounded-full ${bar}`} style={{ width: `${Math.max(4, (Math.abs(value) / max) * 100)}%` }} />
            </span>
            <span className="font-bold text-text-primary"><Num>{fmt(m.metric, value, locale, currency)}</Num></span>
          </div>
        ))}
      </div>
    </div>
  )
}

function InsightCard({ item, locale, currency }: { item: CreativeInsight; locale: Locale; currency: string }) {
  const ar = locale === 'ar'
  const s = SEVERITY[item.severity]
  const c = CONFIDENCE[item.confidence] ?? CONFIDENCE.medium!
  const action = ar ? item.action_ar : item.action_en
  const chips = Object.entries(item.supporting_metrics).filter(([k]) => k !== item.movement?.metric)
  return (
    <li className={`overflow-hidden rounded-2xl border ${s.tone}`} data-testid="creative-insight">
      <div className="flex items-start gap-3 p-4">
        <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-xl bg-surface">{s.icon}</span>
        <div className="min-w-0 flex-1 space-y-3">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="text-base font-bold text-text-primary">{ar ? item.title_ar : item.title_en}</h3>
            <span className="rounded-full bg-surface px-2 py-0.5 text-xs font-semibold text-text-secondary">{ar ? s.ar : s.en}</span>
            <span className="ms-auto inline-flex items-center gap-1 text-xs text-text-muted" title={ar ? c.ar : c.en}>
              {[1, 2, 3].map((d) => <span key={d} className={`size-1.5 rounded-full ${d <= c.dots ? s.bar : 'bg-border'}`} />)}
              <span className="ms-1">{ar ? c.ar : c.en}</span>
            </span>
          </div>
          <p className="text-sm leading-relaxed text-text-secondary">{ar ? item.detail_ar : item.detail_en}</p>
          <div className="grid gap-3 md:grid-cols-2">
            <CompareBars item={item} locale={locale} currency={currency} />
            {chips.length > 0 && (
              <div className="flex flex-wrap content-start gap-2">
                {chips.map(([k, v]) => (
                  <span key={k} className="inline-flex flex-col rounded-xl bg-surface px-3 py-2">
                    <span className="text-xs text-text-muted">{metricLabel(k, locale)}</span>
                    <span className="text-sm font-bold text-text-primary"><Num>{fmt(k, v, locale, currency)}</Num></span>
                  </span>
                ))}
              </div>
            )}
          </div>
          {action && (
            <div className="flex items-start gap-2.5 rounded-xl border border-warning/30 bg-warning/10 p-3" data-testid="creative-insight-action">
              <Lightbulb size={18} className="mt-0.5 shrink-0 text-warning" aria-hidden />
              <div>
                <p className="text-xs font-bold text-warning">{ar ? 'الإجراء المقترح' : 'Suggested action'}</p>
                <p className="mt-0.5 text-sm text-text-primary">{action}</p>
              </div>
            </div>
          )}
          {item.needs_human_review && (
            <p className="text-xs text-warning">{ar ? 'مولَّد آليًا — يحتاج مراجعة بشرية' : 'Generated — needs human review'}</p>
          )}
        </div>
      </div>
    </li>
  )
}

const FATIGUE: Record<string, { ar: string; en: string; pct: number; color: string }> = {
  improving: { ar: 'يتحسّن', en: 'Improving', pct: 15, color: 'var(--success)' },
  stable: { ar: 'مستقر', en: 'Stable', pct: 35, color: 'var(--success)' },
  watch: { ar: 'تحت المراقبة', en: 'Watch', pct: 65, color: 'var(--warning)' },
  fatigued: { ar: 'مُجهَد', en: 'Fatigued', pct: 90, color: 'var(--danger)' },
}

function FatigueCard({ data, locale }: { data: CreativeDetail; locale: Locale }) {
  const ar = locale === 'ar'
  const f = data.fatigue as CreativeDetail['fatigue'] & { note_ar?: string; note_en?: string }
  const reason = (ar ? (f.reason_ar || f.note_ar) : (f.reason_en || f.note_en)) ?? ''
  const meta = FATIGUE[f.status]
  if (!meta) {
    return (
      <section className="flex items-center gap-3 rounded-2xl border border-dashed border-border bg-surface p-4" data-testid="creative-fatigue">
        <Clock3 size={18} className="shrink-0 text-text-muted" aria-hidden />
        <div>
          <h2 className="text-sm font-bold text-text-primary">{ar ? 'حالة الإجهاد' : 'Fatigue'}</h2>
          <p className="text-xs text-text-secondary">{reason || (ar ? 'لا تكفي البيانات للحكم بعد.' : 'Not enough data to judge yet.')}</p>
        </div>
      </section>
    )
  }
  return (
    <section className="rounded-2xl border border-border bg-surface p-4" data-testid="creative-fatigue">
      <div className="flex items-center gap-4">
        <div className="relative size-16 shrink-0 rounded-full" style={{ background: `conic-gradient(${meta.color} ${meta.pct}%, var(--surface-secondary) 0)` }} aria-hidden>
          <span className="absolute inset-1.5 flex items-center justify-center rounded-full bg-surface"><Gauge size={20} style={{ color: meta.color }} /></span>
        </div>
        <div className="min-w-0">
          <h2 className="text-sm font-bold text-text-primary">{ar ? 'حالة الإجهاد' : 'Fatigue'}: <span style={{ color: meta.color }}>{ar ? meta.ar : meta.en}</span></h2>
          {reason && <p className="mt-0.5 text-xs text-text-secondary">{reason}</p>}
        </div>
      </div>
      {f.signals.length > 0 && (
        <div className="mt-3 flex flex-wrap gap-2">
          {f.signals.map((sig) => {
            const down = (sig.change ?? 0) < 0
            return (
              <span key={`${sig.metric}-${sig.direction}`} className="inline-flex items-center gap-1.5 rounded-full bg-surface-secondary px-3 py-1 text-xs">
                {down ? <TrendingDown size={12} className="text-danger" aria-hidden /> : <TrendingUp size={12} className="text-success" aria-hidden />}
                <span className="font-semibold text-text-primary">{metricLabel(sig.metric, locale)}</span>
                {sig.change !== null && <Num>{`${(sig.change * 100).toFixed(1)}%`}</Num>}
              </span>
            )
          })}
        </div>
      )}
    </section>
  )
}

export function AdviceTab({ data, locale, currency }: { data: CreativeDetail; locale: Locale; currency: string }) {
  const ar = locale === 'ar'
  const against = data.insights.compared_against
  return (
    <div className="space-y-4">
      <section>
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <h2 className="text-sm font-bold text-text-primary">{ar ? 'التحليلات والتوصيات' : 'Insights and recommendations'}</h2>
          <span className="inline-flex items-center gap-1 rounded-full bg-surface-secondary px-2.5 py-0.5 text-xs text-text-secondary">
            <ScanSearch size={12} aria-hidden />
            {ar ? 'قورن مع' : 'Compared with'} <Num>{against.creatives}</Num> · {marketingPathLabel(against.path, locale)}
            {against.capped && (ar ? ' — الأعلى إنفاقًا' : ' — top spenders')}
          </span>
        </div>
        {data.insights.items.length === 0 ? (
          <p className="flex items-center gap-2 rounded-2xl border border-dashed border-border p-4 text-sm text-text-secondary">
            <BadgeCheck size={18} className="text-success" aria-hidden />
            {ar ? 'لا شيء يستدعي تدخلًا في هذه الفترة.' : 'Nothing calls for action in this period.'}
          </p>
        ) : (
          <ul className="space-y-3">{data.insights.items.map((item) => <InsightCard key={item.id} item={item} locale={locale} currency={currency} />)}</ul>
        )}
      </section>
      <FatigueCard data={data} locale={locale} />
    </div>
  )
}

function Field({ label, value, mono = false }: { label: string; value: ReactNode; mono?: boolean }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs text-text-muted">{label}</dt>
      <dd className={`mt-0.5 truncate text-sm font-semibold text-text-primary ${mono ? 'font-mono text-xs' : ''}`} title={typeof value === 'string' ? value : undefined}>{value}</dd>
    </div>
  )
}

function RecordCard({ icon, title, children }: { icon: ReactNode; title: string; children: ReactNode }) {
  return (
    <section className="rounded-2xl border border-border bg-surface p-4">
      <h2 className="mb-3 flex items-center gap-2 text-sm font-bold text-text-primary">
        <span className="flex size-7 items-center justify-center rounded-lg bg-brand-500/10 text-brand-600">{icon}</span>{title}
      </h2>
      {children}
    </section>
  )
}

export function RecordTab({ data, locale }: { data: CreativeDetail; locale: Locale }) {
  const ar = locale === 'ar'
  const c = data.creative
  const missing: string[] = []
  const has = <T,>(v: T | null | undefined, label: string): v is T => { if (v === null || v === undefined || v === '') { missing.push(label); return false } return true }
  const ads = (c.ads ?? []).map((a) => a.external_id)
  const date = (v: string | null | undefined) => (v ? v.slice(0, 10) : null)
  const stamp = (v: string | null | undefined) => (v ? v.slice(0, 16).replace('T', ' ') : null)

  const L = ar
    ? { ad: 'الإعلان', platform: 'المنصة', campaign: 'الحملة', adSet: 'المجموعة', adIds: 'رقم الإعلان', objective: 'الهدف', path: 'المسار التسويقي', kind: 'نوع الإعلان', ai: 'وسائط بالذكاء الاصطناعي', time: 'الخط الزمني', first: 'أول ظهور', active: 'آخر نشاط', sync: 'آخر مزامنة', source: 'آخر تحديث من المصدر', measure: 'القياس', currency: 'العملة', tz: 'المنطقة الزمنية', period: 'الفترة', prev: 'الفترة السابقة', copy: 'نص الإعلان', headline: 'العنوان', body: 'النص', cta: 'زر الإجراء', dest: 'رابط الوجهة', notSent: 'لم ترسل المنصة' }
    : { ad: 'The ad', platform: 'Platform', campaign: 'Campaign', adSet: 'Ad set', adIds: 'Ad id', objective: 'Objective', path: 'Marketing path', kind: 'Ad type', ai: 'AI media', time: 'Timeline', first: 'First seen', active: 'Last active', sync: 'Last sync', source: 'Source updated', measure: 'Measurement', currency: 'Currency', tz: 'Time zone', period: 'Period', prev: 'Previous period', copy: 'Ad copy', headline: 'Headline', body: 'Body', cta: 'Call to action', dest: 'Destination', notSent: 'Not sent by the platform' }

  const timeline: Array<[string, string | null]> = [
    [L.first, date(c.freshness.first_seen_at)], [L.active, date(c.freshness.last_active_at)],
    [L.sync, stamp(c.freshness.last_synced_at)], [L.source, stamp(c.freshness.source_updated_at)],
  ]
  const ai = c.ai_media?.declared ? ((ar ? c.ai_media.label_ar : c.ai_media.label_en) ?? c.ai_media.source) : null

  const adFields = (
    <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
      <Field label={L.platform} value={<span className="inline-flex items-center gap-1.5"><ProviderMark provider={c.provider} size={16} />{providerLabel(c.provider, locale)}</span>} />
      {has(c.campaign_name, L.campaign) && <Field label={L.campaign} value={c.campaign_name} />}
      {has(c.external_ids.ad_set, L.adSet) && <Field label={L.adSet} value={c.external_ids.ad_set} mono />}
      {ads.length > 0 ? <Field label={L.adIds} value={ads.join(' · ')} mono /> : (missing.push(L.adIds), null)}
      {has(c.objective, L.objective) && <Field label={L.objective} value={objectiveLabel(c.objective, locale)} />}
      <Field label={L.path} value={marketingPathLabel(data.path, locale)} />
      <Field label={L.kind} value={creativeKindLabel(c.preview.kind, ar)} />
      {has(ai, L.ai) && <Field label={L.ai} value={ai} />}
    </dl>
  )
  const shownTimeline = timeline.filter(([label, v]) => has(v, label))
  const measureFields = (
    <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
      {has(data.currency, L.currency) && <Field label={L.currency} value={data.currency} />}
      {has(data.timezone, L.tz) && <Field label={L.tz} value={data.timezone} />}
      <Field label={L.period} value={formatPeriodRange(data.period.from, data.period.to, ar ? 'ar' : 'en')} />
      <Field label={L.prev} value={formatPeriodRange(data.previous_period.from, data.previous_period.to, ar ? 'ar' : 'en')} />
    </dl>
  )

  return (
    <div className="space-y-4" data-testid="creative-record">
      <div className="grid gap-4 lg:grid-cols-3">
        <RecordCard icon={<Megaphone size={15} aria-hidden />} title={L.ad}>{adFields}</RecordCard>
        <RecordCard icon={<CalendarClock size={15} aria-hidden />} title={L.time}>
          {shownTimeline.length === 0 ? <p className="text-xs text-text-muted">—</p> : (
            <ol className="relative space-y-3 border-s-2 border-border ps-4">
              {shownTimeline.map(([label, v]) => (
                <li key={label} className="relative">
                  <span className="absolute -start-[1.4rem] top-1 size-2.5 rounded-full border-2 border-surface bg-brand-500" aria-hidden />
                  <p className="text-xs text-text-muted">{label}</p>
                  <p className="text-sm font-semibold text-text-primary"><Num>{v}</Num></p>
                </li>
              ))}
            </ol>
          )}
        </RecordCard>
        <RecordCard icon={<Activity size={15} aria-hidden />} title={L.measure}>
          {measureFields}
          <p className="mt-3 rounded-lg bg-surface-secondary p-2 text-xs text-text-secondary">{ar ? data.attribution.note_ar : data.attribution.note_en}</p>
        </RecordCard>
      </div>

      <CopyCard data={data} ar={ar} title={L.copy} />

      {missing.length > 0 && (
        <p className="flex flex-wrap items-center gap-1.5 text-xs text-text-muted" data-testid="creative-record-missing">
          <Fingerprint size={13} aria-hidden /> {L.notSent}: {missing.join(ar ? '، ' : ', ')}
        </p>
      )}
    </div>
  )
}

/**
 * CONTENT-EVALUATION-001 (Owner 2026-11-25, «اضيف جوانب تحليل المحتوى وتقييمك هل المحتوى ناجح او يحتاج تحسين») — one
 * verdict on the ad as content, read from the same scorer the library popup and the report use (`scoreContent`), so the
 * three never disagree: a 0–100 ring, a plain verdict, each stage of the viewer's path against its normal range, and for
 * every stage below the range what to change in the next cut. Too little delivery says so rather than grading on noise.
 */
const STAGE_ICON: Record<Criterion['key'], typeof Zap> = {
  hook: Zap, hold: Eye, completion: PlaySquare, ctr: MousePointerClick, cart_order: ShoppingCart, roas: TrendingUp,
  click_lead: UserPlus, engagement: Heart,
}

const GRADE_TONE: Record<Grade, { card: string; text: string; bar: string }> = {
  good: { card: 'border-success/40 bg-success/5', text: 'text-success', bar: 'bg-success' },
  ok: { card: 'border-warning/40 bg-warning/5', text: 'text-warning', bar: 'bg-warning' },
  weak: { card: 'border-danger/40 bg-danger/5', text: 'text-danger', bar: 'bg-danger' },
  na: { card: 'border-border bg-surface', text: 'text-text-muted', bar: 'bg-border' },
}

const IMPROVE: Record<Criterion['key'], { ar: string; en: string }> = {
  hook: { ar: 'غيّر أول ثلاث ثوانٍ: ابدأ بالمنتج أو النتيجة مباشرة، حركة واضحة ونص كبير على الشاشة.', en: 'Change the first three seconds: open on the product or the result, clear motion and large on-screen text.' },
  hold: { ar: 'اختصر المقدمة وقدّم الرسالة قبل الثانية الثامنة، وقطّع اللقطات بإيقاع أسرع.', en: 'Shorten the intro, land the message before second eight, and cut faster.' },
  completion: { ar: 'خفّض المدة إلى 15 ثانية أو أقل، وضع دعوة الإجراء قبل النهاية لا بعدها.', en: 'Cut to 15 seconds or less and put the call to action before the end, not after it.' },
  ctr: { ar: 'أضف دعوة صريحة للإجراء على الشاشة وفي النص، مع عرض أو سبب للضغط الآن.', en: 'Add an explicit on-screen and caption call to action with an offer or reason to tap now.' },
  cart_order: { ar: 'السلة تمتلئ ولا تتحول: راجع الشحن والدفع وتطابق السعر بين الإعلان والمتجر.', en: 'Carts fill but do not convert: check shipping, checkout and that the ad price matches the store.' },
  roas: { ar: 'العائد أقل من المطلوب: وجّه الإنفاق للجمهور والمنتجات الأعلى تحويلًا أو حسّن العرض.', en: 'Return below the bar: steer spend to the audiences and products that convert, or sharpen the offer.' },
  click_lead: { ar: 'النقرات لا تصبح عملاء: قصّر النموذج واجعل صفحة الهبوط تكمل وعد الإعلان.', en: 'Clicks do not become leads: shorten the form and make the landing page keep the ad’s promise.' },
  engagement: { ar: 'التفاعل ضعيف: اسأل سؤالًا مباشرًا أو استخدم صانع محتوى بأسلوب طبيعي.', en: 'Weak engagement: ask a direct question or use a creator in a natural style.' },
}

const pct = (key: Criterion['key'], v: number) => (key === 'roas' ? `${v.toFixed(1)}×` : `${(v * 100).toFixed(v < 0.1 ? 1 : 0)}%`)

export function ContentEvaluation({ data }: { data: CreativeDetail }) {
  const locale = useUi((s) => s.locale)
  const ar = locale === 'ar'
  const bag = { ...(data.metrics ?? {}), provider: data.creative.provider, objective: data.creative.objective } as Record<string, unknown>
  const s = scoreContent(bag)
  const shown = s.criteria.filter((c) => c.grade !== 'na')
  const tone: 'good' | 'ok' | 'weak' | 'na' = s.score === null ? 'na' : s.score >= 75 ? 'good' : s.score >= 50 ? 'ok' : 'weak'
  const verdict = {
    good: { ar: 'محتوى ناجح', en: 'Content that works' },
    ok: { ar: 'مقبول ويحتاج تحسين', en: 'Fair — needs improving' },
    weak: { ar: 'يحتاج تحسين', en: 'Needs improving' },
    na: { ar: 'لم يُقيَّم بعد', en: 'Not rated yet' },
  }[tone]
  const line = diagnosis(s, ar)
  const fixes = shown.filter((c) => c.grade !== 'good')
  const strengths = shown.filter((c) => c.grade === 'good')
  const R = 30
  const circ = 2 * Math.PI * R

  return (
    <section className="rounded-2xl border border-border bg-surface p-4" data-testid="content-evaluation" data-score={s.score ?? ''}>
      <div className="flex flex-wrap items-center gap-4">
        <svg width="76" height="76" viewBox="0 0 76 76" role="img" aria-label={s.score === null ? verdict[ar ? 'ar' : 'en'] : `${s.score}/100`} className="shrink-0">
          <circle cx="38" cy="38" r={R} fill="none" stroke="var(--surface-secondary)" strokeWidth="7" />
          {s.score !== null && (
            <circle cx="38" cy="38" r={R} fill="none" strokeWidth="7" strokeLinecap="round" transform="rotate(-90 38 38)"
              className={GRADE_TONE[tone].text} stroke="currentColor" strokeDasharray={`${(s.score / 100) * circ} ${circ}`} />
          )}
          <text x="38" y="44" textAnchor="middle" className="fill-text-primary text-lg font-bold">{s.score ?? '—'}</text>
        </svg>
        <div className="min-w-0 flex-1">
          <p className="text-xs font-semibold text-text-muted">{ar ? 'تقييم المحتوى' : 'Content rating'}</p>
          <h2 className={`text-lg font-bold ${GRADE_TONE[tone].text}`}>{verdict[ar ? 'ar' : 'en']}</h2>
          <p className="text-sm text-text-secondary">
            {!s.sufficient
              ? (ar ? `يحتاج ${MIN_IMPRESSIONS.toLocaleString('en')} ظهورًا على الأقل قبل الحكم عليه.` : `Needs at least ${MIN_IMPRESSIONS.toLocaleString('en')} impressions before it can be judged.`)
              : line}
          </p>
        </div>
      </div>

      {shown.length > 0 && (
        <div className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6" data-testid="content-evaluation-stages">
          {shown.map((c) => {
            const Icon = STAGE_ICON[c.key]
            const t = GRADE_TONE[c.grade]
            const top = c.key === 'roas' ? c.norm[1] * 1.6 : c.norm[1] * 1.6
            const at = c.value === null ? 0 : Math.min(100, (c.value / top) * 100)
            return (
              <div key={c.key} className={`rounded-xl border p-3 ${t.card}`}>
                <p className="flex items-center gap-1.5 text-xs font-semibold text-text-secondary"><Icon size={13} className={t.text} aria-hidden />{ar ? c.ar : c.en}</p>
                <p className={`mt-1 text-lg font-bold ${t.text}`}><Num>{c.value === null ? '—' : pct(c.key, c.value)}</Num></p>
                <div className="relative mt-1.5 h-1.5 rounded-full bg-surface-secondary">
                  <span className="absolute inset-y-0 rounded-full bg-success/25" style={{ insetInlineStart: `${(c.norm[0] / top) * 100}%`, width: `${((c.norm[1] - c.norm[0]) / top) * 100}%` }} />
                  <span className={`absolute top-1/2 size-2.5 -translate-y-1/2 rounded-full border-2 border-surface ${t.bar}`} style={{ insetInlineStart: `calc(${at}% - 5px)` }} />
                </div>
                <p className="mt-1 text-xs text-text-muted">{ar ? 'الطبيعي' : 'Normal'} <Num>{`${pct(c.key, c.norm[0]).replace(/[%×]/, '')}–${pct(c.key, c.norm[1])}`}</Num></p>
              </div>
            )
          })}
        </div>
      )}

      {(fixes.length > 0 || strengths.length > 0) && (
        <div className="mt-4 grid gap-3 md:grid-cols-2">
          {fixes.length > 0 && (
            <div className="rounded-xl bg-warning/10 p-3" data-testid="content-evaluation-fixes">
              <p className="mb-2 flex items-center gap-1.5 text-sm font-bold text-text-primary"><Lightbulb size={15} className="text-warning" aria-hidden />{ar ? 'وش يتحسّن في النسخة الجاية' : 'What to change in the next cut'}</p>
              <ul className="space-y-1.5 text-sm text-text-secondary">
                {fixes.map((c) => <li key={c.key}><span className="font-semibold text-text-primary">{ar ? c.ar : c.en}: </span>{IMPROVE[c.key][ar ? 'ar' : 'en']}</li>)}
              </ul>
            </div>
          )}
          {strengths.length > 0 && (
            <div className="rounded-xl bg-success/10 p-3">
              <p className="mb-2 flex items-center gap-1.5 text-sm font-bold text-text-primary"><BadgeCheck size={15} className="text-success" aria-hidden />{ar ? 'نقاط القوة — حافظ عليها' : 'Strengths — keep them'}</p>
              <div className="flex flex-wrap gap-1.5">
                {strengths.map((c) => <span key={c.key} className="rounded-full bg-surface px-2.5 py-1 text-xs font-semibold text-success">{ar ? c.ar : c.en} <Num>{c.value === null ? '' : pct(c.key, c.value)}</Num></span>)}
              </div>
            </div>
          )}
        </div>
      )}
    </section>
  )
}

/**
 * CREATIVE-COPY-ANALYSIS-001 (Owner 2026-11-25, «التفاصيل ليش ما يظهر فيه الهيد لاين والرابط التوجيه وCTA … هل يمدي تضيف
 * التحليل») — the ad's words, its call to action and its link, read against the Owner's own ad standards: a call to
 * action always, a destination, no hashtags, no emoji, short enough to read in the first seconds. When the platform sent
 * none of it, the card says so — it never disappears, so «no copy» is never confused with «nothing to show».
 */
const CTA_AR: Record<string, string> = {
  SHOP_NOW: 'تسوّق الآن', ORDER_NOW: 'اطلب الآن', BUY_NOW: 'اشترِ الآن', LEARN_MORE: 'اعرف المزيد', SIGN_UP: 'سجّل الآن',
  DOWNLOAD: 'حمّل الآن', INSTALL_NOW: 'ثبّت الآن', BOOK_NOW: 'احجز الآن', CONTACT_US: 'تواصل معنا', APPLY_NOW: 'قدّم الآن',
  GET_OFFER: 'احصل على العرض', SUBSCRIBE: 'اشترك', WATCH_MORE: 'شاهد المزيد', VISIT_STORE: 'زر المتجر', GET_QUOTE: 'اطلب عرض سعر',
}
const ctaText = (cta: string, ar: boolean) => (ar && CTA_AR[cta.toUpperCase()]) || cta.replace(/_/g, ' ').toLowerCase().replace(/^\w/, (x) => x.toUpperCase())
const MAX_TEXT = 100

function CopyCard({ data, ar, title }: { data: CreativeDetail; ar: boolean; title: string }) {
  const c = data.creative
  const copy = c.copy
  const words = [copy.headline, copy.body, copy.description].filter((x): x is string => !!x).join(' ')
  const sent = !!(words || copy.cta || c.destination_url)
  const provider = providerLabel(c.provider, ar ? 'ar' : 'en')

  if (!sent) {
    return (
      <RecordCard icon={<Type size={15} aria-hidden />} title={title}>
        <p className="rounded-xl border border-dashed border-border p-4 text-sm text-text-secondary" data-testid="creative-copy-none">
          {ar
            ? `${provider} ما أرسلت نص هذا الإعلان ولا زر الإجراء ولا رابط التوجيه في المزامنة. يظهر التحليل هنا أول ما تصل.`
            : `${provider} sent no text, call to action or link for this ad in the sync. The analysis appears here once they arrive.`}
          {c.provider === 'tiktok' && (
            <span className="mt-1 block text-xs text-text-muted">{ar ? 'تيك توك ما فيه حقل عنوان للإعلان؛ عنده النص وزر الإجراء والرابط.' : 'TikTok has no headline field; it has the ad text, the call to action and the link.'}</span>
          )}
        </p>
      </RecordCard>
    )
  }

  const hashtags = (words.match(/#[\p{L}\p{N}_]+/gu) ?? []).length
  const emoji = /\p{Extended_Pictographic}/u.test(words)
  const checks: Array<{ ok: boolean; label: string; note?: string }> = [
    { ok: !!copy.cta, label: ar ? 'زر إجراء' : 'Call to action', note: copy.cta ? ctaText(copy.cta, ar) : (ar ? 'غير موجود' : 'missing') },
    { ok: !!c.destination_url, label: ar ? 'رابط توجيه' : 'Destination link', note: c.destination_url ? undefined : (ar ? 'غير موجود' : 'missing') },
    ...(words ? [
      { ok: words.length <= MAX_TEXT, label: ar ? 'طول النص' : 'Text length', note: ar ? `${words.length} حرف` : `${words.length} chars` },
      { ok: hashtags === 0, label: ar ? 'بدون هاشتاقات' : 'No hashtags', note: hashtags ? (ar ? `${hashtags} هاشتاق` : `${hashtags} hashtags`) : undefined },
      { ok: !emoji, label: ar ? 'بدون إيموجي' : 'No emoji', note: emoji ? (ar ? 'فيه إيموجي' : 'has emoji') : undefined },
    ] : [{ ok: false, label: ar ? 'نص الإعلان' : 'Ad text', note: ar ? 'لم يُرسل' : 'not sent' }]),
  ]
  const passed = checks.filter((x) => x.ok).length

  return (
    <RecordCard icon={<Type size={15} aria-hidden />} title={title}>
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,320px)]">
        <div className="space-y-2 rounded-xl bg-surface-secondary p-4" data-testid="creative-copy">
          {copy.headline && <p className="text-base font-bold text-text-primary">{copy.headline}</p>}
          {copy.body && <p className="whitespace-pre-line text-sm text-text-primary">{copy.body}</p>}
          {copy.description && <p className="text-sm text-text-secondary">{copy.description}</p>}
          <div className="flex flex-wrap items-center gap-2 pt-1">
            {copy.cta && <span className="rounded-lg bg-brand-600 px-3 py-1 text-xs font-bold text-white">{ctaText(copy.cta, ar)}</span>}
            {c.destination_url && (
              <span className="inline-flex min-w-0 items-center gap-1 break-all font-mono text-xs text-text-secondary"><Link2 size={12} className="shrink-0" aria-hidden /><Num>{c.destination_url}</Num></span>
            )}
          </div>
        </div>
        <div data-testid="creative-copy-analysis">
          <p className="mb-2 text-xs font-semibold text-text-secondary">
            {ar ? 'تحليل النص' : 'Copy check'} · <Num>{`${passed}/${checks.length}`}</Num>
          </p>
          <ul className="space-y-1.5">
            {checks.map((x) => (
              <li key={x.label} className="flex items-center justify-between gap-2 rounded-lg bg-surface-secondary px-3 py-2 text-sm">
                <span className="inline-flex items-center gap-2 font-semibold text-text-primary">
                  {x.ok ? <BadgeCheck size={15} className="text-success" aria-hidden /> : <CircleAlert size={15} className="text-warning" aria-hidden />}
                  {x.label}
                </span>
                {x.note && <span className={`text-xs ${x.ok ? 'text-text-secondary' : 'text-warning'}`}>{x.note}</span>}
              </li>
            ))}
          </ul>
        </div>
      </div>
    </RecordCard>
  )
}
