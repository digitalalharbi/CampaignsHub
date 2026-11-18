import { Eye, MousePointerClick, PlaySquare, ShoppingCart, TrendingUp, Zap, type LucideIcon } from 'lucide-react'
import { Num } from '@/components/ui/Num'
import { diagnosis, scoreContent, type Criterion, type Grade } from './contentScore'

/**
 * CONTENT-SCORE-001 — the scorecard drawn: a ring for the score, one coloured tile per criterion with a short name and
 * its figure, and a single diagnosis line. No paragraphs. Not enough data → a grey ring and no verdict.
 */
const ICONS: Record<Criterion['key'], LucideIcon> = {
  hook: Zap,
  hold: Eye,
  completion: PlaySquare,
  ctr: MousePointerClick,
  cart_order: ShoppingCart,
  roas: TrendingUp,
}

const TONE: Record<Grade, string> = {
  good: 'border-success/40 text-success',
  ok: 'border-warning/40 text-warning',
  weak: 'border-danger/40 text-danger',
  na: 'border-border text-text-muted',
}

const RING: Record<'good' | 'ok' | 'weak' | 'none', string> = {
  good: 'var(--color-success, #1D9E75)',
  ok: 'var(--color-warning, #EF9F27)',
  weak: 'var(--color-danger, #E24B4A)',
  none: 'var(--color-border, #888780)',
}

const show = (c: Criterion): string => {
  if (c.value === null) return '—'

  return c.key === 'roas' ? `${c.value.toFixed(1)}×` : `${(c.value * 100).toFixed(c.value < 0.1 ? 1 : 0)}%`
}

export function ContentScoreCard({ ad, ar }: { ad: Record<string, unknown>; ar: boolean }) {
  const s = scoreContent(ad)
  const shown = s.criteria.filter((c) => c.grade !== 'na')
  const tone = s.score === null ? 'none' : s.score >= 75 ? 'good' : s.score >= 50 ? 'ok' : 'weak'
  const circumference = 2 * Math.PI * 22
  const line = diagnosis(s, ar)

  return (
    <section data-testid="content-score" data-score={s.score ?? ''} className="flex items-center gap-3 rounded-xl border border-border p-3">
      <svg width="56" height="56" viewBox="0 0 56 56" role="img" aria-label={s.score === null ? (ar ? 'بيانات غير كافية' : 'Not enough data') : `${s.score}/100`} className="shrink-0">
        <circle cx="28" cy="28" r="22" fill="none" stroke="currentColor" className="text-border" strokeWidth="6" />
        {s.score !== null && (
          <circle cx="28" cy="28" r="22" fill="none" stroke={RING[tone]} strokeWidth="6" strokeLinecap="round"
            strokeDasharray={`${(s.score / 100) * circumference} ${circumference}`} transform="rotate(-90 28 28)" />
        )}
        <text x="28" y="33" textAnchor="middle" fontSize="15" fontWeight="700" fill="currentColor" className="tnum text-text-primary">{s.score ?? '—'}</text>
      </svg>
      <div className="grid min-w-0 flex-1 gap-1.5">
        {shown.length > 0 ? (
          <ul className="grid grid-cols-3 gap-1.5 sm:grid-cols-6">
            {shown.map((c) => {
              const Icon = ICONS[c.key]

              return (
                <li key={c.key} data-testid={`content-score-${c.key}`} data-grade={c.grade} className={`flex flex-col items-center rounded-lg border px-1 py-1 ${TONE[c.grade]}`}>
                  <Icon size={15} aria-hidden />
                  <span className="text-[10px] font-semibold leading-tight text-text-secondary">{ar ? c.ar : c.en}</span>
                  <span className="tnum text-xs font-bold"><Num>{show(c)}</Num></span>
                </li>
              )
            })}
          </ul>
        ) : (
          <span className="text-xs font-semibold text-text-muted">{ar ? 'بيانات غير كافية للتقييم' : 'Not enough data to grade'}</span>
        )}
        {line && <span data-testid="content-score-line" className="truncate text-xs font-bold text-text-primary">{line}</span>}
      </div>
    </section>
  )
}
