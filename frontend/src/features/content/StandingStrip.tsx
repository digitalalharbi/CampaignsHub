import { useState } from 'react'
import { ArrowDownRight, ArrowUpRight, Sparkles, Trophy } from 'lucide-react'
import { Modal } from '@/components/ui/Modal'
import { Num } from '@/components/ui/Num'
import { formatNumber } from '@/lib/numerals'
import { providerLabel, objectiveLabel } from '@/features/campaigns/labels'
import type { WhyRow } from '@/features/analyticsShared/contentWhy'
import { classLabel, compareOnObjective, fatigueWord, indicatorOf, rankLabel, whyReading, whySentence, type StandingItem } from './standing'

/**
 * CONTENT-CARDS-001 — the three things a card owes beside its figures: how it did on ITS OWN objective, where it stands
 * among content bought for that objective, and why. Short labels; real numbers only; «غير مُقاس» where nothing was
 * measured. The strip draws nothing while the server's answer has not arrived.
 */
const COPY = {
  ar: { rank: 'ترتيبه بين محتوى نفس الهدف', why: 'ليش؟', whyTitle: 'ليش نجح / ليش ما نجح', gap: 'نسبة الضغط عن المتوسط', share: 'حصته من الإنفاق', freq: 'التكرار', ctr: 'تغيّر نسبة الضغط', fatigue: 'إرهاق الإعلان', group: 'محتوى بنفس الهدف والمنصة', close: 'إغلاق' },
  en: { rank: 'Rank among content with the same objective', why: 'Why?', whyTitle: 'Why it worked / why it did not', gap: 'Click rate vs average', share: 'Share of spend', freq: 'Frequency', ctr: 'Click-rate change', fatigue: 'Ad fatigue', group: 'Items with the same objective and platform', close: 'Close' },
}

export function StandingStrip({ item, why, name, ar }: { item: StandingItem | undefined; why: WhyRow | undefined; name: string; ar: boolean }) {
  const [open, setOpen] = useState(false)
  const t = COPY[ar ? 'ar' : 'en']
  const indicator = indicatorOf(item, ar)
  if (!item || !indicator) return null
  const cls = classLabel(item.class, ar)
  const rank = rankLabel(item, ar)

  return (
    <div data-testid="standing-strip" data-class={item.class ?? 'none'} className="flex flex-col gap-1.5 rounded-md bg-surface-secondary px-2 py-1.5 text-xs">
      <div className="flex flex-wrap items-center gap-1.5">
        {cls && item.class && (
          <span data-testid="standing-class" className={`inline-flex items-center gap-1 rounded px-1.5 py-0.5 font-semibold ${item.class === 'best' ? 'bg-success/15 text-success' : 'bg-warning/15 text-warning'}`}>
            {item.class === 'best' ? <Trophy size={12} aria-hidden /> : <Sparkles size={12} aria-hidden />}
            {cls}
          </span>
        )}
        {rank && <span data-testid="standing-rank" title={t.rank} className="rounded bg-surface px-1.5 py-0.5 text-text-secondary">{rank}</span>}
        <button type="button" data-testid="standing-why-open" onClick={() => setOpen(true)} className="ms-auto rounded px-1 text-[11px] text-brand-700 underline-offset-2 hover:underline">
          {t.why}
        </button>
      </div>
      <div data-testid="standing-indicator" data-measured={indicator.measured} className="flex items-baseline justify-between gap-2">
        <span className="text-text-secondary">{indicator.label}</span>
        <Num className={`tabular-nums ${indicator.measured ? 'font-semibold text-text-primary' : 'text-text-muted'}`}>{indicator.text}</Num>
      </div>
      <WhyPopup open={open} onClose={() => setOpen(false)} item={item} why={why} name={name} ar={ar} />
    </div>
  )
}

/** «ليش نجح / ليش ما نجح» — the numbers behind the verdict, in a popup. */
export function WhyPopup({ open, onClose, item, why, name, ar }: { open: boolean; onClose: () => void; item: StandingItem; why: WhyRow | undefined; name: string; ar: boolean }) {
  const t = COPY[ar ? 'ar' : 'en']
  const reading = whyReading(why)
  const sign = (n: number) => `${n > 0 ? '+' : ''}${formatNumber(n)}%`

  return (
    <Modal open={open} onClose={onClose} size="sm" title={`${t.whyTitle}: ${name}`}>
      <div data-testid="standing-why" className="flex flex-col gap-3 text-sm">
        <p data-testid="standing-why-sentence" className="font-semibold text-text-primary">{whySentence(reading, ar)}</p>
        {reading && (
          <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
            {reading.gapPercent !== null && <Fig label={t.gap} value={sign(reading.gapPercent)} tone={reading.gapPercent >= 0 ? 'good' : 'bad'} />}
            {reading.sharePercent !== null && <Fig label={t.share} value={`${formatNumber(reading.sharePercent)}%`} />}
            {reading.frequency !== null && <Fig label={t.freq} value={formatNumber(reading.frequency, { maximumFractionDigits: 2 })} />}
            {reading.ctrChangePercent !== null && <Fig label={t.ctr} value={sign(reading.ctrChangePercent)} tone={reading.ctrChangePercent >= 0 ? 'good' : 'bad'} />}
            <Fig label={t.fatigue} value={fatigueWord(reading.fatigue, ar)} />
            <Fig label={t.group} value={formatNumber(reading.groupSize)} />
          </dl>
        )}
        <p className="text-[11px] text-text-muted">
          {providerLabel(item.provider, ar ? 'ar' : 'en')}{item.objective ? ` · ${objectiveLabel(item.objective, ar ? 'ar' : 'en')}` : ''}
        </p>
      </div>
    </Modal>
  )
}

function Fig({ label, value, tone }: { label: string; value: string; tone?: 'good' | 'bad' }) {
  return (
    <div className="flex flex-col">
      <dt className="text-text-secondary">{label}</dt>
      <dd><Num className={`tabular-nums font-semibold ${tone === 'good' ? 'text-success' : tone === 'bad' ? 'text-danger' : 'text-text-primary'}`}>{value}</Num></dd>
    </div>
  )
}

const CMP = {
  ar: { title: 'على هدف المحتوى', ref: 'المرجع', mismatch: 'أهداف مختلفة: كل محتوى يُقاس بنتيجة هدفه (طلبات أو زيارات أو ليدز)، فما نرتّب واحد على الثاني.', unit: 'نفس الهدف لكن بوحدتين مختلفتين، فما نقارن بينهم.', none: 'غير مُقاس: ما فيه رقم نتيجة نقارن عليه.' },
  en: { title: 'On the content objective', ref: 'Reference', mismatch: 'Different objectives: each item is judged on its own objective result, so they are not ranked against each other.', unit: 'Same objective but different units, so they are not compared.', none: 'Not measured: there is no result figure to compare.' },
}

/** The shared-objective block of the compare popup: delta arrows and percent, or the honest mismatch. */
export function ObjectiveCompare({ items, names, ar }: { items: StandingItem[]; names: Record<string, string>; ar: boolean }) {
  const t = CMP[ar ? 'ar' : 'en']
  if (items.length < 2) return null
  const cmp = compareOnObjective(items)
  const byId = new Map(items.map((i) => [i.id, i]))

  return (
    <section data-testid="objective-compare" data-comparable={cmp.comparable} className="mb-4 rounded-lg border border-border bg-surface p-3">
      <h3 className="mb-2 text-sm font-semibold text-text-primary">{t.title}</h3>
      {!cmp.comparable && (
        <p data-testid="objective-compare-reason" data-reason={cmp.reason} className="mb-2 rounded-md border border-warning/40 bg-warning/10 p-2 text-xs text-text-primary">
          {cmp.reason === 'objective_mismatch' ? t.mismatch : cmp.reason === 'unit_mismatch' ? t.unit : t.none}
        </p>
      )}
      <ul className={`grid gap-2 ${cmp.columns.length > 2 ? 'sm:grid-cols-2 lg:grid-cols-4' : 'sm:grid-cols-2'}`}>
        {cmp.columns.map((c) => {
          const item = byId.get(c.id)
          const ind = indicatorOf(item, ar)
          const up = c.delta !== null && c.delta > 0
          const down = c.delta !== null && c.delta < 0

          return (
            <li key={c.id} data-testid={`objective-compare-${c.id}`} className="flex flex-col gap-0.5 rounded-md bg-surface-secondary p-2 text-xs">
              <span className="truncate font-medium text-text-primary" title={names[c.id]}>{names[c.id] ?? c.id}</span>
              <span className="text-text-secondary">{ind?.label}</span>
              <Num className={`text-sm tabular-nums ${ind?.measured ? 'font-bold text-text-primary' : 'text-text-muted'}`}>{ind?.text}</Num>
              {cmp.comparable && c.isReference && <span className="text-[11px] text-text-muted">{t.ref}</span>}
              {cmp.comparable && !c.isReference && c.delta !== null && (
                <span data-testid="objective-compare-delta" data-direction={up ? 'up' : down ? 'down' : 'flat'} className={`inline-flex items-center gap-0.5 font-semibold ${up ? 'text-success' : down ? 'text-danger' : 'text-text-muted'}`}>
                  {up ? <ArrowUpRight size={13} aria-hidden /> : down ? <ArrowDownRight size={13} aria-hidden /> : null}
                  <Num>{`${c.delta > 0 ? '+' : ''}${formatNumber(Math.round(c.delta * 100))}%`}</Num>
                </span>
              )}
            </li>
          )
        })}
      </ul>
    </section>
  )
}
