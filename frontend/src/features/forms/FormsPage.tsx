import { AgencyFit, FitPanel, useFitCompact } from '@/components/fit/AgencyFit'
import { FitPaged } from '@/components/fit/FitPaged'
import { TabStrip } from '@/components/fit/TabStrip'
import { useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { Check, Copy, ExternalLink, Plus } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { PageIntro } from '@/components/ui/PageIntro'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { ProjectChooser } from '@/features/projects/ProjectChooser'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { ConversionsPanel } from './ConversionsPanel'
import { HealthPanel } from './HealthPanel'
import { useCreateForm, useForms } from './api'
import { starterDefinition } from './builderState'
import type { LeadFormRow } from './types'

/**
 * FORMS-HUB-001 — the project's lead forms: what exists, what is live, how many people have sent each.
 *
 * Reached from `/app/forms` and `/agency/forms`; everything a form collects lands in the project's own
 * Leads Centre, so this page links to the form's builder and its public address and nothing else.
 */
const TONE = { draft: 'neutral', published: 'success', archived: 'warning' } as const

export function FormsPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const { currentProjectId } = useProject()
  const base = useLocation().pathname.startsWith('/agency') ? '/agency' : '/app'
  /* AF2 — under /agency the page fits the screen: the forms, their health and their conversions are three tabs. Health and Conversions are long reports of their own: they unlock the page and flow. */
  const fit = base === '/agency'
  const [tab, setTab] = useState<'forms' | 'health' | 'conversions'>('forms')
  const navigate = useNavigate()
  const forms = useForms(currentProjectId)
  const create = useCreateForm(currentProjectId)

  const title = ar ? 'النماذج' : 'Forms'
  const purpose = ar
    ? 'نماذج جمع العملاء المحتملين لهذا المشروع: ابنِ النموذج، انشره على رابط عام، وكل من يرسله يصل إلى مركز العملاء المحتملين مع مصدره.'
    : 'Lead forms for this project: build one, publish it on a public address, and everyone who sends it arrives in the Leads Centre with where they came from.'

  if (!currentProjectId) {
    return (
      <div className="space-y-6">
        <PageIntro title={title} purpose={purpose} testid="forms-intro" />
        <ProjectChooser purpose="analytics" />
      </div>
    )
  }

  const start = () => create.mutate(
    { name: ar ? 'نموذج جديد' : 'New form', definition: starterDefinition() },
    { onSuccess: (row) => navigate(`${base}/forms/${row.id}`) },
  )

  return (
    <AgencyFit className="space-y-6" testid="forms-page" active={tab === 'forms'}>
      <PageIntro
        testid="forms-intro"
        title={title}
        purpose={purpose}
        actions={<Button onClick={start} loading={create.isPending} data-testid="forms-new"><Plus size={16} aria-hidden /> {ar ? 'نموذج جديد' : 'New form'}</Button>}
      />

      {create.isError && <p role="alert" className="text-sm text-danger">{ar ? 'تعذّر إنشاء النموذج.' : 'The form could not be created.'}</p>}

      {forms.isLoading && <Skeleton className="h-24" />}
      {forms.isSuccess && forms.data.length === 0 && (
        <EmptyState
          title={ar ? 'لا نماذج بعد' : 'No forms yet'}
          description={ar ? 'أنشئ أول نموذج ليصل العملاء المحتملون إلى هذا المشروع مباشرة.' : 'Create the first form so leads arrive in this project directly.'}
        />
      )}
      {fit && (
        <TabStrip
          label={ar ? 'أقسام النماذج' : 'Form sections'}
          testid="forms-tabs"
          active={tab}
          onChange={(k) => setTab(k as 'forms' | 'health' | 'conversions')}
          items={[
            { key: 'forms', label: ar ? 'النماذج' : 'Forms', count: forms.data?.length ?? 0 },
            { key: 'health', label: ar ? 'السلامة' : 'Health' },
            { key: 'conversions', label: ar ? 'التحويلات' : 'Conversions' },
          ]}
        />
      )}
      {fit ? (
        <FitPanel>
          {tab === 'forms' && (
            <FitPaged
              items={forms.data ?? []}
              itemHeight={104}
              columns={2}
              listClassName="grid grid-cols-1 md:grid-cols-2"
              rowKey={(f) => String(f.id)}
              render={(f) => <FormCard form={f} base={base} ar={ar} />}
              ar={ar}
              testid="forms-list"
            />
          )}
          {tab === 'health' && <HealthPanel projectId={currentProjectId} />}
          {tab === 'conversions' && <ConversionsPanel projectId={currentProjectId} />}
        </FitPanel>
      ) : (
        <>
          <div className="grid gap-3 md:grid-cols-2">
            {(forms.data ?? []).map((f) => <FormCard key={f.id} form={f} base={base} ar={ar} />)}
          </div>
          <HealthPanel projectId={currentProjectId} />
          <ConversionsPanel projectId={currentProjectId} />
        </>
      )}
    </AgencyFit>
  )
}

function FormCard({ form, base, ar }: { form: LeadFormRow; base: string; ar: boolean }) {
  const [copied, setCopied] = useState(false)
  const label = { draft: ar ? 'مسودة' : 'Draft', published: ar ? 'منشور' : 'Published', archived: ar ? 'مؤرشف' : 'Archived' }[form.status]
  const copy = async () => {
    try { await navigator.clipboard.writeText(form.public_url); setCopied(true); setTimeout(() => setCopied(false), 1500) } catch { /* clipboard refused; the address is shown */ }
  }

  const compact = useFitCompact()

  return (
    <Card className={compact ? 'flex h-full flex-col justify-between gap-1 overflow-hidden px-3 py-2' : 'space-y-3 p-4'} data-testid={`form-card-${form.id}`}>
      <div className="flex items-start justify-between gap-2">
        <Link to={`${base}/forms/${form.id}`} className="text-base font-bold text-text-primary hover:text-brand-600" data-testid={`form-open-${form.id}`}>{form.name}</Link>
        <Badge tone={TONE[form.status]}>{label}</Badge>
      </div>
      <p className="text-xs text-text-secondary">
        {ar ? `${form.submissions ?? 0} إرسال` : `${form.submissions ?? 0} submissions`}
      </p>
      {form.status === 'published' && (
        <div className="flex flex-wrap items-center gap-2">
          <code className="min-w-0 flex-1 truncate rounded-lg bg-surface-secondary px-2 py-1 text-xs" dir="ltr">{form.public_url}</code>
          <button type="button" onClick={copy} className="inline-flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs font-semibold" aria-label={ar ? 'نسخ الرابط' : 'Copy link'}>
            {copied ? <Check size={14} aria-hidden /> : <Copy size={14} aria-hidden />} {copied ? (ar ? 'نُسخ' : 'Copied') : (ar ? 'نسخ' : 'Copy')}
          </button>
          <a href={form.public_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs font-semibold"><ExternalLink size={14} aria-hidden /> {ar ? 'فتح' : 'Open'}</a>
        </div>
      )}
    </Card>
  )
}
