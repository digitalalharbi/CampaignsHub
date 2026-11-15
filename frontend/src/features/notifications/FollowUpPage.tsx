import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Sunrise } from 'lucide-react'
import { PageIntro } from '@/components/ui/PageIntro'
import { getBrief, isUpgradeRequired, type Brief } from './followupApi'
import { TaskList } from './FollowUpTasks'
import { useFollowupTasks } from './useFollowupTasks'
import { UpgradeLock } from './UpgradeLock'
import { useCopy, type CopyKey } from './centerCopy'

/**
 * NOTIF-V2-FOLLOWUP-001 — «المتابعة»: the morning brief and the automatic task list, at one address that every workspace type
 * mounts (`/followup`). The page knows no workspace type: the words «project / client» come from the server (`terms`), and a
 * plan without the feature shows the lock and «ترقية» with the data kept.
 */
const TABS: { key: 'open' | 'snoozed' | 'done'; label: CopyKey }[] = [
  { key: 'open', label: 'status_open' },
  { key: 'snoozed', label: 'status_snoozed' },
  { key: 'done', label: 'status_done' },
]

export function FollowUpPage() {
  const c = useCopy()
  const [tab, setTab] = useState<'open' | 'snoozed' | 'done'>('open')
  const tasks = useFollowupTasks(tab)
  const brief = useQuery({ queryKey: ['followup', 'brief'], queryFn: () => getBrief(), retry: false })
  const locked = isUpgradeRequired(tasks.error)
  const briefLocked = isUpgradeRequired(brief.error)
  const word = tasks.data?.terms.project ?? brief.data?.terms.project ?? ''

  return (
    <div className="flex min-w-0 flex-col gap-5" data-testid="followup-page">
      <PageIntro title={c('page_title')} purpose={c('page_sub')} testid="followup-intro" />

      <section aria-labelledby="brief-h" className="min-w-0 rounded-xl border border-border bg-surface p-4">
        <h2 id="brief-h" className="mb-2 flex items-center gap-2 text-base font-bold text-text-primary"><Sunrise size={17} /> {c('brief_title')}</h2>
        {briefLocked ? <UpgradeLock name={c('brief_title')} /> : <BriefView brief={brief.data?.brief ?? null} word={word} />}
      </section>

      <section aria-labelledby="tasks-h" className="min-w-0 rounded-xl border border-border bg-surface">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-3 py-2">
          <h2 id="tasks-h" className="text-base font-bold text-text-primary">{c('followup_all')}</h2>
          <div role="tablist" aria-label={c('page_title')} className="flex gap-1">
            {TABS.map((t) => (
              <button key={t.key} role="tab" aria-selected={tab === t.key} data-testid={`followup-tab-${t.key}`} onClick={() => setTab(t.key)}
                className={`h-9 rounded-full px-3 text-xs font-semibold ${tab === t.key ? 'bg-brand-500 text-white' : 'bg-surface-secondary text-text-secondary hover:bg-surface-hover'}`}>
                {c(t.label)}{tasks.data ? ` ${tasks.data.counts[t.key]}` : ''}
              </button>
            ))}
          </div>
        </div>
        {locked ? <div className="p-3"><UpgradeLock name={c('page_title')} /></div> : <TaskList tasks={tasks.data?.items ?? []} projectWord={word} />}
      </section>
    </div>
  )
}

function BriefView({ brief, word }: { brief: Brief | null; word: string }) {
  const c = useCopy()
  if (!brief) return <p className="text-sm text-text-muted" data-testid="brief-none">{c('brief_none')}</p>
  const s = brief.sections
  const ext = s.external_yesterday

  return (
    <div className="flex min-w-0 flex-col gap-3" data-testid="brief">
      <p className="break-words text-sm font-semibold text-text-primary" data-testid="brief-summary">{brief.summary}</p>
      <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        {s.tasks.count > 0 && (
          <Block title={c('brief_tasks')} testId="brief-tasks">
            {s.tasks.items.map((t) => <li key={t.id} className="break-words">{t.project_name ?? word}</li>)}
          </Block>
        )}
        {s.budgets_ending.length > 0 && (
          <Block title={c('brief_budgets_ending')} testId="brief-ending">
            {s.budgets_ending.map((b) => <li key={b.project_id} className="break-words">{b.project_name} · {c('brief_enough_until')} <span dir="ltr" className="tnum">{b.until ?? `${b.days} ${c('days')}`}</span></li>)}
          </Block>
        )}
        {s.no_budget.length > 0 && <Block title={c('brief_no_budget')} testId="brief-no-budget">{s.no_budget.map((p) => <li key={p.project_id} className="break-words">{p.project_name}</li>)}</Block>}
        {s.recommendations > 0 && <Block title={c('brief_recommendations')} testId="brief-reco"><li className="tnum">{s.recommendations}</li></Block>}
        {s.problems.length > 0 && <Block title={c('brief_problems')} testId="brief-problems">{s.problems.map((p) => <li key={p.project_id} className="break-words">{p.project_name}</li>)}</Block>}
        {ext.sent + ext.held + ext.unsubscribed > 0 && (
          <Block title={c('brief_external')} testId="brief-external">
            <li>{c('brief_sent')}: <span className="tnum" dir="ltr">{ext.sent}</span> · {c('brief_held')}: <span className="tnum" dir="ltr">{ext.held}</span> · {c('brief_unsub')}: <span className="tnum" dir="ltr">{ext.unsubscribed}</span></li>
          </Block>
        )}
      </dl>
    </div>
  )
}

function Block({ title, testId, children }: { title: string; testId: string; children: React.ReactNode }) {
  return (
    <div data-testid={testId} className="min-w-0 rounded-lg bg-surface-secondary p-3">
      <dt className="mb-1 text-xs font-bold text-text-muted">{title}</dt>
      <dd><ul className="flex flex-col gap-0.5 text-sm text-text-primary">{children}</ul></dd>
    </div>
  )
}
