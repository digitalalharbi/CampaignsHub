import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Sparkles } from 'lucide-react'
import { Switch } from '@/components/ui/Switch'
import { toApiError } from '@/lib/api/client'
import { objectiveLabel } from '@/features/campaigns/labels'
import { SPECS } from '@/features/analytics/metricCatalog'
import {
  applyAutoCompose,
  getAutoCompose,
  revertAutoCompose,
  type AutoComposeDecision,
  type AutoComposeProposal,
  type GenericNameKind,
} from './api'

/**
 * REPORT-GENERIC-LABELS-001 — one independent switch per kind of name. The row, its figures and its chart stay; only the name goes,
 * and the server removes it from every surface, so nothing here decides what a reader receives.
 */
export const GENERIC_NAME_KINDS: ReadonlyArray<{ kind: GenericNameKind; ar: string; en: string }> = [
  { kind: 'campaign', ar: 'أسماء الحملات', en: 'Campaign names' },
  { kind: 'ad_set', ar: 'أسماء المجموعات الإعلانية', en: 'Ad set names' },
  { kind: 'account', ar: 'أسماء الحسابات الإعلانية', en: 'Ad account names' },
  { kind: 'content', ar: 'أسماء المحتوى', en: 'Content names' },
]

export const GENERIC_NAMES_TITLE = { ar: 'الأسماء الظاهرة', en: 'Names shown' }
export const GENERIC_NAMES_HINT = {
  ar: 'الاسم المخفي يُستبدل بتسمية عامة مثل «حملة 1»، وتبقى الصفوف والأرقام والمقارنات كما هي.',
  en: 'A hidden name is replaced by a generic label such as “Campaign 1”. Rows, figures and comparisons stay as they are.',
}

export function GenericNameSwitches({
  ar,
  chosen,
  lockedByReport,
  disabled,
  onChange,
}: {
  ar: boolean
  chosen: GenericNameKind[]
  lockedByReport?: GenericNameKind[]
  disabled: boolean
  onChange: (next: GenericNameKind[]) => void
}) {
  return (
    <>
      <p className="mt-4 mb-1 text-xs font-bold text-text-muted">{ar ? GENERIC_NAMES_TITLE.ar : GENERIC_NAMES_TITLE.en}</p>
      <p className="mb-1 text-[11px] text-text-muted">{ar ? GENERIC_NAMES_HINT.ar : GENERIC_NAMES_HINT.en}</p>
      <ul className="divide-y divide-border" data-testid="generic-names">
        {GENERIC_NAME_KINDS.map(({ kind, ar: labelAr, en }) => (
          <li key={kind} className="flex items-center justify-between gap-3 py-1.5">
            <span className="text-sm font-semibold text-text-primary">{ar ? labelAr : en}</span>
            {lockedByReport?.includes(kind) ? (
              <span data-testid={`generic-name-locked-${kind}`} className="text-[11px] text-text-muted">
                {ar ? 'مخفية في التقرير' : 'Hidden by the report'}
              </span>
            ) : (
              <span className="flex items-center gap-2">
                <span className="text-[11px] text-text-muted">{chosen.includes(kind) ? (ar ? 'تسمية عامة' : 'Generic label') : (ar ? 'الاسم' : 'Name')}</span>
                <Switch
                  id={`generic-name-${kind}`}
                  testId={`generic-name-${kind}`}
                  checked={chosen.includes(kind)}
                  disabled={disabled}
                  onCheckedChange={(on) => onChange(GENERIC_NAME_KINDS.map((k) => k.kind).filter((k) => (k === kind ? on : chosen.includes(k))))}
                />
              </span>
            )}
          </li>
        ))}
      </ul>
    </>
  )
}

/** The same choice before a link exists: plain tick boxes in the builder, sent once with the link. */
export function GenericNameChoices({ ar, chosen, onChange, testid }: { ar: boolean; chosen: GenericNameKind[]; onChange: (next: GenericNameKind[]) => void; testid: string }) {
  return (
    <fieldset data-testid={testid} className="rounded-xl border border-border px-3 py-2 text-sm">
      <legend className="px-1 text-xs font-bold text-text-muted">{ar ? 'إخفاء الأسماء بتسمية عامة' : 'Hide names behind a generic label'}</legend>
      <p className="mb-1 text-[11px] text-text-muted">{ar ? GENERIC_NAMES_HINT.ar : GENERIC_NAMES_HINT.en}</p>
      <div className="grid gap-1 sm:grid-cols-2">
        {GENERIC_NAME_KINDS.map(({ kind, ar: labelAr, en }) => (
          <label key={kind} className="flex cursor-pointer items-center justify-between gap-2 py-0.5">
            <span>{ar ? labelAr : en}</span>
            <input
              type="checkbox"
              data-testid={`${testid}-${kind}`}
              checked={chosen.includes(kind)}
              onChange={(e) => onChange(GENERIC_NAME_KINDS.map((k) => k.kind).filter((k) => (k === kind ? e.target.checked : chosen.includes(k))))}
              className="h-4 w-4 accent-brand-600"
            />
          </label>
        ))}
      </div>
    </fieldset>
  )
}

const DECISION_WORDS: Record<string, { ar: string; en: string }> = {
  show: { ar: 'يظهر', en: 'Shown' },
  objective: { ar: 'لا يناسب هذا الهدف', en: 'Not suited to this objective' },
  no_data: { ar: 'لا بيانات في هذه الفترة', en: 'No data in this period' },
  unsupported: { ar: 'غير مدعوم للمنصات أو الهدف', en: 'Not supported for these platforms or objective' },
  turned_off: { ar: 'يبقى مطفأً، يمكنك تشغيله يدويًا', en: 'Stays off; you can turn it on by hand' },
}

const LEFT_OUT_WORDS: Record<string, { ar: string; en: string }> = {
  not_reported: { ar: 'لم تبلغ عنه المنصات', en: 'Not reported' },
  hidden_by_link: { ar: 'مخفي في هذا الرابط', en: 'Hidden on this link' },
  hidden_by_report: { ar: 'مخفي في هذا التقرير', en: 'Hidden on this report' },
}

const DECISION_TONE: Record<AutoComposeDecision, string> = {
  show: 'text-text-primary',
  hide: 'text-text-secondary',
  unavailable: 'text-text-muted',
  off: 'text-text-muted',
}

const metricName = (key: string, ar: boolean) => SPECS[key]?.label?.[ar ? 'ar' : 'en'] ?? key

/**
 * REPORT-AUTO-COMPOSE-001 — a preset, not a form: closed until somebody opens it, and nothing is saved until they apply it. Applying
 * writes the same switches listed above it, so every one of them can still be changed by hand afterwards.
 */
export function AutoComposePanel({
  projectId,
  reportId,
  shareId,
  ar,
  onChanged,
}: {
  projectId: string
  reportId: string
  shareId?: string
  ar: boolean
  onChanged: () => void
}) {
  const [open, setOpen] = useState(false)
  const [latest, setLatest] = useState<{ for: string; proposal: AutoComposeProposal } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const level = shareId ?? ''
  const read = useQuery({
    queryKey: ['report-auto-compose', projectId, reportId, level],
    queryFn: () => getAutoCompose(projectId, reportId, shareId),
    enabled: open,
  })
  const done = (next: AutoComposeProposal) => {
    setError(null)
    setLatest({ for: level, proposal: next })
    onChanged()
  }
  const failed = (e: unknown) => setError(toApiError(e).message)
  const apply = useMutation({ mutationFn: () => applyAutoCompose(projectId, reportId, shareId), onSuccess: done, onError: failed })
  const revert = useMutation({ mutationFn: () => revertAutoCompose(projectId, reportId, shareId), onSuccess: done, onError: failed })

  if (!open) {
    return (
      <button
        type="button"
        data-testid="auto-compose-open"
        onClick={() => setOpen(true)}
        className="mb-3 inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-semibold text-text-secondary hover:bg-surface-hover"
      >
        <Sparkles size={13} aria-hidden />
        {ar ? 'تركيب تلقائي حسب الهدف' : 'Compose automatically by objective'}
      </button>
    )
  }

  const proposal = latest?.for === level ? latest.proposal : read.data
  const busy = apply.isPending || revert.isPending

  return (
    <section data-testid="auto-compose-panel" className="mb-3 rounded-xl border border-border bg-surface-secondary p-3 text-xs">
      <div className="mb-2 flex items-center justify-between gap-2">
        <p className="font-bold text-text-primary">{ar ? 'التركيب التلقائي' : 'Automatic composition'}</p>
        <button type="button" data-testid="auto-compose-close" onClick={() => setOpen(false)} className="text-text-muted hover:text-text-primary">
          {ar ? 'إغلاق' : 'Close'}
        </button>
      </div>

      {!proposal && !read.isError && <p className="text-text-muted">{ar ? 'جارٍ قراءة التقرير…' : 'Reading the report…'}</p>}
      {read.isError && !proposal && <p role="alert" className="text-danger">{ar ? 'تعذّرت قراءة الاقتراح.' : 'The proposal could not be read.'}</p>}

      {proposal && (
        <>
          <p data-testid="auto-compose-objective" className="mb-2 text-text-secondary">
            {ar ? 'الهدف: ' : 'Objective: '}
            <b className="text-text-primary">
              {proposal.objective === 'custom' ? (ar ? 'عدة أهداف' : 'Several objectives') : objectiveLabel(proposal.objective, ar ? 'ar' : 'en')}
            </b>
          </p>
          {!proposal.availability_judged && (
            <p data-testid="auto-compose-not-judged" className="mb-2 text-[11px] text-text-muted">
              {ar ? 'لا أرقام بعد لهذا المستوى، لذا لم يُفحص توفّر البيانات. اختر رابطًا لفحصه.' : 'No figures at this level yet, so data availability was not checked. Choose a link to check it.'}
            </p>
          )}

          <ul className="mb-2 space-y-1">
            {proposal.sections.map((s) => (
              <li key={s.key} data-testid={`auto-compose-section-${s.key}`} data-decision={s.decision} className={`flex items-center justify-between gap-3 ${DECISION_TONE[s.decision]}`}>
                <span className="min-w-0 truncate">{ar ? s.title_ar : s.title_en}</span>
                <span className="shrink-0 text-[11px]">{(DECISION_WORDS[s.reason ?? 'show'] ?? DECISION_WORDS.show)[ar ? 'ar' : 'en']}</span>
              </li>
            ))}
          </ul>

          <p className="mb-1 font-bold text-text-primary">{ar ? 'المؤشرات' : 'Indicators'}</p>
          {proposal.metrics_apply ? (
            <ul data-testid="auto-compose-metrics" className="mb-2 flex flex-wrap gap-1">
              {proposal.metrics.map((m) => <li key={m} className="rounded-md border border-border px-1.5 py-0.5 text-text-primary">{metricName(m, ar)}</li>)}
              {proposal.metrics.length === 0 && <li className="text-text-muted">{ar ? 'لا مؤشر متاح؛ تبقى البطاقات الحالية.' : 'No indicator available; the current cards stay.'}</li>}
            </ul>
          ) : (
            <p data-testid="auto-compose-metrics-on-links" className="mb-2 text-[11px] text-text-muted">
              {ar ? 'بطاقات التقرير الحي تخص كل رابط. اختر رابطًا لتطبيق المؤشرات عليه.' : "A live report's cards belong to each link. Choose a link to apply indicators to it."}
            </p>
          )}
          {proposal.metrics_left_out.length > 0 && (
            <ul data-testid="auto-compose-left-out" className="mb-2 space-y-0.5 text-[11px] text-text-muted">
              {proposal.metrics_left_out.map((m) => (
                <li key={m.key}>{metricName(m.key, ar)}: {(LEFT_OUT_WORDS[m.reason] ?? LEFT_OUT_WORDS.not_reported)[ar ? 'ar' : 'en']}</li>
              ))}
            </ul>
          )}

          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              data-testid="auto-compose-apply"
              disabled={busy}
              onClick={() => apply.mutate()}
              className="rounded-lg bg-brand-600 px-2.5 py-1.5 font-semibold text-white disabled:opacity-50"
            >
              {ar ? 'تطبيق' : 'Apply'}
            </button>
            {proposal.applied && (
              <button
                type="button"
                data-testid="auto-compose-revert"
                disabled={busy}
                onClick={() => revert.mutate()}
                className="rounded-lg border border-border px-2.5 py-1.5 font-semibold text-text-secondary hover:bg-surface-hover disabled:opacity-50"
              >
                {ar ? 'الرجوع للإعدادات السابقة' : 'Go back to the earlier settings'}
              </button>
            )}
            <span className="text-[11px] text-text-muted">{ar ? 'بعد التطبيق يبقى كل مفتاح قابلًا للتعديل يدويًا.' : 'After applying, every switch can still be changed by hand.'}</span>
          </div>
        </>
      )}
      {error && <p role="alert" data-testid="auto-compose-error" className="mt-2 text-danger">{error}</p>}
    </section>
  )
}
