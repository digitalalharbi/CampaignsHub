import { useQuery } from '@tanstack/react-query'
import { BarChart3, CalendarClock, Eye, FileText, Hammer, Lightbulb, ShieldCheck, Sparkles } from 'lucide-react'
import type { ReactNode } from 'react'
import { Skeleton } from '@/components/ui/States'
import { fmtDateTime } from '@/lib/datetime'
import { getTeam, type AgentJob, type TeamMember } from './api'
import { AUTONOMY, JOB, reasonText } from './agentCopy'

/**
 * AGENTS-TEAM-001 — «الفريق»: the specialists behind the assistant, grouped by JOB, each a card — one-line job, how far it may
 * go (suggests only / acts after your approval), last activity, pending proposals and whether its schedule is on.
 */
const ICON: Record<AgentJob, ReactNode> = {
  watch: <Eye size={15} />,
  analyse: <BarChart3 size={15} />,
  content: <Lightbulb size={15} />,
  reports: <FileText size={15} />,
  build: <Hammer size={15} />,
}

export function TeamTab({ projectId, ar, onProposals }: { projectId: string; ar: boolean; onProposals: () => void }) {
  const q = useQuery({ queryKey: ['agents-team', projectId], queryFn: () => getTeam(projectId), retry: false })
  if (q.isLoading) return <Skeleton className="h-40" />
  const data = q.data
  if (!data) return null

  return (
    <div className="space-y-4" data-testid="team-tab">
      {data.jobs.map((job) => {
        const members = data.members.filter((m) => m.job === job)
        if (members.length === 0) return null
        return (
          <section key={job} data-testid={`team-job-${job}`}>
            <h3 className="mb-2 flex items-center gap-1.5 text-sm font-bold text-text-primary"><span className="text-brand-600" aria-hidden>{ICON[job]}</span>{ar ? JOB[job].ar : JOB[job].en}</h3>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
              {members.map((m) => <MemberCard key={m.key} m={m} ar={ar} onProposals={onProposals} />)}
            </div>
          </section>
        )
      })}
    </div>
  )
}

function MemberCard({ m, ar, onProposals }: { m: TeamMember; ar: boolean; onProposals: () => void }) {
  return (
    <div className={`min-w-0 rounded-xl border border-border bg-surface p-3 ${m.enabled ? '' : 'opacity-70'}`} data-testid={`team-member-${m.key}`}>
      <div className="flex items-start justify-between gap-2">
        <p className="flex items-center gap-1.5 text-sm font-bold text-text-primary"><Sparkles size={13} className="text-brand-600" aria-hidden />{ar ? m.ar : m.en}</p>
        <span className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold ${m.autonomy === 'acts_after_approval' ? 'bg-[var(--warning-background)] text-warning' : 'bg-surface-secondary text-text-secondary'}`} data-testid="team-autonomy">
          {m.autonomy === 'acts_after_approval' && <ShieldCheck size={11} className="me-0.5 inline" aria-hidden />}{ar ? AUTONOMY[m.autonomy].ar : AUTONOMY[m.autonomy].en}
        </span>
      </div>
      <p className="mt-1 text-xs text-text-secondary">{ar ? m.job_ar : m.job_en}</p>
      {!m.enabled && <p className="mt-1 text-xs text-warning">{reasonText(m.why_disabled, ar)}</p>}
      <dl className="mt-2 grid grid-cols-3 gap-1 text-xs">
        <div className="min-w-0">
          <dt className="text-text-muted">{ar ? 'آخر نشاط' : 'Last active'}</dt>
          <dd className="truncate text-text-primary"><bdi dir="ltr" className="tnum">{m.last_activity_at ? fmtDateTime(m.last_activity_at) : '—'}</bdi></dd>
        </div>
        <div>
          <dt className="text-text-muted">{ar ? 'اقتراحات' : 'Proposals'}</dt>
          <dd>
            {m.pending_proposals > 0
              ? <button type="button" onClick={onProposals} className="font-bold text-brand-700 underline-offset-2 hover:underline" data-testid="team-pending"><bdi dir="ltr" className="tnum">{m.pending_proposals}</bdi></button>
              : <bdi dir="ltr" className="tnum text-text-primary" data-testid="team-pending">0</bdi>}
          </dd>
        </div>
        <div>
          <dt className="text-text-muted">{ar ? 'الجدولة' : 'Schedule'}</dt>
          <dd className="flex items-center gap-1 text-text-primary" data-testid="team-schedule">
            <CalendarClock size={11} className={m.schedule === 'on' ? 'text-success' : 'text-text-muted'} aria-hidden />
            {m.schedule === 'on' ? (ar ? 'تعمل' : 'On') : m.schedule === 'off' ? (ar ? 'متوقفة' : 'Off') : '—'}
          </dd>
        </div>
      </dl>
    </div>
  )
}
