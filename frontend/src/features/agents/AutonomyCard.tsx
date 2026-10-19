import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Gauge } from 'lucide-react'
import { getAutonomy, setAutonomy, type LadderClass } from './api'
import { Badge } from '@/components/ui/Badge'
import { Card } from '@/components/ui/Card'
import { toApiError } from '@/lib/api/client'
import { countedAr, countedEn, days } from '@/lib/counted'
import { useAuth } from '@/stores/auth'

const CLASS_WORDS: Record<LadderClass['class'], { ar: string; en: string; perm: string }> = {
  pause: { ar: 'إيقاف حملة', en: 'Pausing a campaign', perm: 'campaigns.pause' },
  budget_decrease: { ar: 'خفض ميزانية يومية', en: 'Lowering a daily budget', perm: 'campaigns.budget.change' },
}

const decisionsAr = (n: number) => countedAr(n, { one: 'قرار', two: 'قراران', few: 'قرارات', many: 'قرارًا' })

const NEEDS: Record<string, { ar: string; en: string }> = {
  history_days: { ar: 'سجل أقصر من المطلوب', en: 'history too short' },
  decided_count: { ar: 'قرارات أقل من المطلوب', en: 'too few decisions' },
  approval_rate: { ar: 'نسبة الاعتماد أقل من المطلوب', en: 'approval rate too low' },
  recent_failures: { ar: 'تنفيذ فشل خلال 30 يومًا', en: 'a failed execution in the last 30 days' },
}

/**
 * AGENTS-AUTONOMY-001 — what this project's record has earned, and the owner's switch for it.
 *
 * Only two classes exist, both of which reduce spend. Raises and resumes always wait for a person and are
 * not offered here at all.
 */
export function AutonomyCard({ projectId, ar }: { projectId: string; ar: boolean }) {
  const qc = useQueryClient()
  const canManage = useAuth((s) => s.hasPermission('ai.manage'))
  const has = useAuth((s) => s.hasPermission)
  const state = useQuery({ queryKey: ['agent-autonomy', projectId], queryFn: () => getAutonomy(projectId), retry: false })
  const toggle = useMutation({
    mutationFn: ({ cls, enabled }: { cls: string; enabled: boolean }) => setAutonomy(projectId, cls, enabled),
    onSuccess: (data) => qc.setQueryData(['agent-autonomy', projectId], data),
  })

  if (!state.data) return null
  const req = state.data.requirements

  return (
    <Card className="space-y-2" data-testid="agent-autonomy">
      <p className="flex items-center gap-1 text-sm font-bold text-text-primary"><Gauge size={14} aria-hidden />{ar ? 'التنفيذ التلقائي (سُلّم الثقة)' : 'Automatic execution (trust ladder)'}</p>
      <p className="text-xs text-text-secondary">
        {ar
          ? `يُتاح لصنف بعد ${days(req.min_days, 'ar')} و${decisionsAr(req.min_decided)} على الأقل بنسبة اعتماد ${Math.round(req.min_approval_rate * 100)}% دون فشل حديث، ثم يفعّله مالك. الرفع والاستئناف ينتظران دائمًا شخصًا.`
          : `A class qualifies after ${days(req.min_days, 'en')} and at least ${countedEn(req.min_decided, 'decision', 'decisions')} with a ${Math.round(req.min_approval_rate * 100)}% approval rate and no recent failure; then an owner switches it on. Raises and resumes always wait for a person.`}
      </p>
      <ul className="grid gap-2 sm:grid-cols-2">
        {state.data.classes.map((c) => {
          const words = CLASS_WORDS[c.class]
          const mayToggle = canManage && has(words.perm) && (c.granted || c.eligible)
          return (
            <li key={c.class} className="rounded-lg border border-border p-2" data-testid={`autonomy-${c.class}`}>
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-semibold text-text-primary">{ar ? words.ar : words.en}</span>
                <Badge tone={c.granted ? 'success' : c.eligible ? 'info' : 'neutral'}>
                  {c.granted ? (ar ? 'مفعّل' : 'On') : c.eligible ? (ar ? 'مؤهّل' : 'Qualifies') : (ar ? 'غير مؤهّل' : 'Not yet')}
                </Badge>
              </div>
              <p className="mt-1 text-[11px] text-text-muted">
                {ar ? `${c.approved} معتمد من ${c.decided} · ${days(c.days, 'ar')}` : `${c.approved} approved of ${c.decided} · ${days(c.days, 'en')}`}
                {c.needs.length > 0 && ` · ${c.needs.map((n) => (NEEDS[n] ? (ar ? NEEDS[n].ar : NEEDS[n].en) : n)).join('، ')}`}
              </p>
              {mayToggle && (
                <button
                  type="button"
                  disabled={toggle.isPending}
                  onClick={() => toggle.mutate({ cls: c.class, enabled: !c.granted })}
                  data-testid={`autonomy-${c.class}-toggle`}
                  className="mt-1 text-xs font-semibold text-brand-700 hover:underline"
                >
                  {c.granted ? (ar ? 'إيقاف التنفيذ التلقائي' : 'Turn off') : (ar ? 'تفعيل التنفيذ التلقائي' : 'Turn on')}
                </button>
              )}
            </li>
          )
        })}
      </ul>
      {toggle.isError && <p role="alert" className="text-xs text-danger">{toApiError(toggle.error).message}</p>}
    </Card>
  )
}
