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
        <PageIntro title={title} purpose={purpose} />
        <ProjectChooser purpose="analytics" />
      </div>
    )
  }

  const start = () => create.mutate(
    { name: ar ? 'نموذج جديد' : 'New form', definition: starterDefinition() },
    { onSuccess: (row) => navigate(`${base}/forms/${row.id}`) },
  )

  return (
    <div className="space-y-6" data-testid="forms-page">
      <PageIntro
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
      <div className="grid gap-3 md:grid-cols-2">
        {(forms.data ?? []).map((f) => <FormCard key={f.id} form={f} base={base} ar={ar} />)}
      </div>

      <ConversionsPanel projectId={currentProjectId} />
    </div>
  )
}

function FormCard({ form, base, ar }: { form: LeadFormRow; base: string; ar: boolean }) {
  const [copied, setCopied] = useState(false)
  const label = { draft: ar ? 'مسودة' : 'Draft', published: ar ? 'منشور' : 'Published', archived: ar ? 'مؤرشف' : 'Archived' }[form.status]
  const copy = async () => {
    try { await navigator.clipboard.writeText(form.public_url); setCopied(true); setTimeout(() => setCopied(false), 1500) } catch { /* clipboard refused; the address is shown */ }
  }

  return (
    <Card className="space-y-3 p-4" data-testid={`form-card-${form.id}`}>
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
