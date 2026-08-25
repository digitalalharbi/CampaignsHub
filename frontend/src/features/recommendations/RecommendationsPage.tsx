import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'

import { listRecommendations, type Recommendation, type RecommendationPriority } from './api'
import { Badge } from '@/components/ui/Badge'
import { EmptyState } from '@/components/ui/States'
import { FilterBar, FilterSelect } from '@/components/ui/FilterBar'
import { PageIntro } from '@/components/ui/PageIntro'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'

/**
 * RECOMMENDATIONS-001 — «ماذا نفعل الآن», on one screen.
 *
 * `/app/recommendations` answered 404 while the records existed: every recommendation carried a
 * priority, an evidence line, an owner and a due date, and could be read only by opening campaigns
 * one at a time. Nobody reads twelve campaign pages to assemble a to-do list, so in practice the
 * field was written and never acted on.
 *
 * This page SURFACES those records. It generates nothing. A screen that derived advice from the same
 * figures would look identical to this one and mean something entirely different — the reader could
 * not tell an analyst's judgement from a template firing on a threshold, and would be right not to
 * trust either. So every row shows who is accountable and what it was based on, and a row with no
 * evidence says so rather than leaving the column blank.
 */
const PRIORITY_ORDER: RecommendationPriority[] = ['critical', 'high', 'medium', 'low']

const PRIORITY_TONE: Record<RecommendationPriority, 'danger' | 'warning' | 'info' | 'neutral'> = {
  critical: 'danger',
  high: 'warning',
  medium: 'info',
  low: 'neutral',
}

export function RecommendationsPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const { currentProjectId } = useProject()
  const [status, setStatus] = useState('all')
  const [priority, setPriority] = useState('all')

  const t = ar
    ? {
        title: 'التوصيات',
        purpose: 'ما يستحق التنفيذ الآن — مجمَّعًا من حملات المشروع، بأولويته وصاحبه وما بُني عليه.',
        status: 'الحالة', priority: 'الأولوية', all: 'الكل',
        empty: 'لا توجد توصيات في هذا المشروع بعد.',
        emptyFiltered: 'لا توجد توصيات تطابق هذه الفلاتر.',
        noProject: 'اختر مشروعًا', noProjectBody: 'التوصيات مرتبطة بحملات المشروع — اختر مشروعًا لعرضها.',
        evidence: 'المستند', noEvidence: 'لم يُذكر ما بُنيت عليه', action: 'الإجراء المقترح',
        due: 'الاستحقاق', campaign: 'الحملة',
        statuses: { draft: 'مسودة', reviewed: 'مراجَعة', approved: 'معتمدة', hidden: 'مخفية', rejected: 'مرفوضة' } as Record<string, string>,
        priorities: { critical: 'حرجة', high: 'عالية', medium: 'متوسطة', low: 'منخفضة' } as Record<string, string>,
      }
    : {
        title: 'Recommendations',
        purpose: 'What is worth doing now — gathered from the project’s campaigns, with its priority, its owner and what it rests on.',
        status: 'Status', priority: 'Priority', all: 'All',
        empty: 'No recommendations have been written for this project yet.',
        emptyFiltered: 'No recommendations match these filters.',
        noProject: 'Select a project', noProjectBody: 'Recommendations belong to a project’s campaigns — pick one to see them.',
        evidence: 'Based on', noEvidence: 'No basis was recorded', action: 'Proposed action',
        due: 'Due', campaign: 'Campaign',
        statuses: { draft: 'Draft', reviewed: 'Reviewed', approved: 'Approved', hidden: 'Hidden', rejected: 'Rejected' } as Record<string, string>,
        priorities: { critical: 'Critical', high: 'High', medium: 'Medium', low: 'Low' } as Record<string, string>,
      }

  const query = useQuery({
    queryKey: ['recommendations', currentProjectId, status, priority],
    queryFn: () => listRecommendations(currentProjectId!, { status, priority }),
    enabled: Boolean(currentProjectId),
  })

  const rows = useMemo(() => query.data ?? [], [query.data])
  const filtersTouched = status !== 'all' || priority !== 'all'

  /** How many are waiting on somebody, by urgency — the reason to open this page at all. */
  const counts = useMemo(() => {
    const open = rows.filter((r) => r.status !== 'hidden' && r.status !== 'rejected')
    return PRIORITY_ORDER.map((p) => ({ priority: p, count: open.filter((r) => r.priority === p).length }))
  }, [rows])

  if (!currentProjectId) {
    return (
      <div className="space-y-6">
        <PageIntro title={t.title} purpose={t.purpose} />
        <EmptyState title={t.noProject} description={t.noProjectBody} />
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <PageIntro title={t.title} purpose={t.purpose} />

      {counts.some((c) => c.count > 0) && (
        <div className="flex flex-wrap gap-2" data-testid="recommendations-counts">
          {counts
            .filter((c) => c.count > 0)
            .map((c) => (
              <Badge key={c.priority} tone={PRIORITY_TONE[c.priority]}>
                {t.priorities[c.priority]}: {c.count}
              </Badge>
            ))}
        </div>
      )}

      <FilterBar
        id="recommendations"
        ar={ar}
        applied={[
          ...(status !== 'all' ? [{ key: 'status', axis: t.status, label: t.statuses[status] ?? status, onRemove: () => setStatus('all') }] : []),
          ...(priority !== 'all' ? [{ key: 'priority', axis: t.priority, label: t.priorities[priority] ?? priority, onRemove: () => setPriority('all') }] : []),
        ]}
        onReset={() => { setStatus('all'); setPriority('all') }}
      >
        <FilterSelect
          label={t.status}
          value={status}
          testid="recommendations-status"
          options={[{ value: 'all', label: t.all }, ...Object.entries(t.statuses).map(([value, label]) => ({ value, label }))]}
          onChange={setStatus}
        />
        <FilterSelect
          label={t.priority}
          value={priority}
          testid="recommendations-priority"
          options={[{ value: 'all', label: t.all }, ...PRIORITY_ORDER.map((p) => ({ value: p, label: t.priorities[p] }))]}
          onChange={setPriority}
        />
      </FilterBar>

      {!query.isLoading && rows.length === 0 && (
        <EmptyState title={filtersTouched ? t.emptyFiltered : t.empty} description="" />
      )}

      <ul className="grid gap-3" data-testid="recommendations-list">
        {rows.map((r) => (
          <li key={r.id}>
            <RecommendationRow rec={r} t={t} />
          </li>
        ))}
      </ul>
    </div>
  )
}

function RecommendationRow({ rec, t }: { rec: Recommendation; t: Record<string, never> | any }) {
  return (
    <article className="rounded-xl border border-border bg-surface p-4" data-testid={`recommendation-${rec.id}`}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <h2 className="text-sm font-bold text-text-primary">{rec.title}</h2>
          <p className="mt-0.5 text-xs text-text-secondary">
            {rec.campaign_name && (
              <Link to={`/app/campaigns/${rec.campaign_id}`} className="font-semibold hover:text-text-primary">
                {rec.campaign_name}
              </Link>
            )}
            {rec.platform && <span> · {rec.platform}</span>}
            {rec.kpi && <span> · {rec.kpi}</span>}
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-1.5">
          {/*
            `priority` is NOT NULL DEFAULT 'medium' on `campaign_annotations`, so «nobody ranked
            this» reaches the reader as «medium» and there is no null to distinguish. An earlier
            draft of this row rendered a «no priority» state and explained why it mattered — a
            branch the schema makes unreachable, under a comment asserting a rule the database does
            not keep. The fallback stays as a guard and says nothing the data cannot support.
          */}
          <Badge tone={rec.priority ? PRIORITY_TONE[rec.priority] : 'neutral'}>
            {rec.priority ? t.priorities[rec.priority] : rec.priority ?? '—'}
          </Badge>
          <Badge tone={rec.status === 'approved' ? 'success' : 'neutral'}>{t.statuses[rec.status] ?? rec.status}</Badge>
        </div>
      </div>

      {rec.body && <p className="mt-2 text-sm text-text-secondary">{rec.body}</p>}

      {rec.proposed_action && (
        <p className="mt-2 text-sm text-text-primary">
          <span className="font-semibold">{t.action}: </span>
          {rec.proposed_action}
        </p>
      )}

      <p className="mt-2 text-xs text-text-secondary">
        <span className="font-semibold">{t.evidence}: </span>
        {rec.evidence ? rec.evidence : <span className="italic opacity-80">{t.noEvidence}</span>}
      </p>

      {rec.due_date && (
        <p className="mt-1 tnum text-xs text-text-secondary">
          {t.due}: {rec.due_date}
        </p>
      )}
    </article>
  )
}
