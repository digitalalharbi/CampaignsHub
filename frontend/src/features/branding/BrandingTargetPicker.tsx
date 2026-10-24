import { useQuery } from '@tanstack/react-query'
import { listClients } from '@/features/clients/api'
import { listProjects } from '@/features/projects/api'
import { listReports } from '@/features/reports/api'
import type { BrandingScope } from './api'

const COPY = {
  ar: { client: 'العميل', project: 'المشروع', report: 'التقرير', choose: 'اختر…', loading: 'جارٍ التحميل…', none: 'لا توجد عناصر.', ofProject: 'مشروع التقرير', hint: { client: 'الهوية تُطبَّق على هذا العميل ومشاريعه وتقاريره.', project: 'الهوية تُطبَّق على هذا المشروع وتقاريره.', report: 'الهوية تُطبَّق على هذا التقرير فقط.', portal: 'هوية بوابة هذا العميل.', email: 'هوية رسائل هذا العميل.' } as Record<string, string> },
  en: { client: 'Client', project: 'Project', report: 'Report', choose: 'Choose…', loading: 'Loading…', none: 'Nothing to choose from.', ofProject: 'Report’s project', hint: { client: 'The identity applies to this client, its projects and reports.', project: 'The identity applies to this project and its reports.', report: 'The identity applies to this report only.', portal: 'This client’s portal identity.', email: 'This client’s email identity.' } as Record<string, string> },
}

/** Which kind of record a scope is keyed to. `tenant` and `platform` need none. */
export function targetKind(scope: BrandingScope): 'client' | 'project' | 'report' | null {
  if (scope === 'client' || scope === 'portal' || scope === 'email') return 'client'
  if (scope === 'project') return 'project'
  if (scope === 'report') return 'report'
  return null
}

/**
 * BRANDING-TARGET-PICKER-001 — a scoped brand is chosen from the records that exist, never typed as a UUID.
 *
 * The page used to ask for «the client/project/report UUID» in a free text box, which nobody has to hand and which let a typo
 * address a record that is not there. The list is what the signed-in user can already reach (the same endpoints the client, project
 * and report screens use), so the picker cannot offer a record the server would refuse.
 */
export function BrandingTargetPicker({ scope, value, onChange, locale, projectForReport, onProjectForReport }: {
  scope: BrandingScope
  value: string
  onChange: (id: string) => void
  locale: 'ar' | 'en'
  projectForReport: string
  onProjectForReport: (id: string) => void
}) {
  const c = COPY[locale]
  const kind = targetKind(scope)
  const clients = useQuery({ queryKey: ['branding-pick', 'clients'], queryFn: () => listClients({}), enabled: kind === 'client' })
  const projects = useQuery({ queryKey: ['branding-pick', 'projects'], queryFn: () => listProjects(), enabled: kind === 'project' || kind === 'report' })
  const reports = useQuery({
    queryKey: ['branding-pick', 'reports', projectForReport],
    queryFn: () => listReports(projectForReport),
    enabled: kind === 'report' && projectForReport !== '',
  })
  if (kind === null) return null

  const select = 'rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary'
  const options: { id: string; name: string }[] =
    kind === 'client' ? (clients.data?.data ?? []).map((x) => ({ id: x.id, name: x.name }))
      : kind === 'project' ? (projects.data ?? []).map((x) => ({ id: x.id, name: x.name }))
        : (reports.data?.reports ?? []).map((x) => ({ id: x.id, name: x.name }))
  const loading = kind === 'client' ? clients.isLoading : kind === 'project' ? projects.isLoading : reports.isLoading

  return (
    <div className="flex flex-[2] flex-col gap-2 sm:flex-row">
      {kind === 'report' && (
        <label className="flex flex-1 flex-col gap-1 text-xs font-semibold text-text-secondary">
          {c.ofProject}
          <select data-testid="branding-report-project" className={select} value={projectForReport} onChange={(e) => { onProjectForReport(e.target.value); onChange('') }}>
            <option value="">{c.choose}</option>
            {(projects.data ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </label>
      )}
      <label className="flex flex-1 flex-col gap-1 text-xs font-semibold text-text-secondary">
        {c[kind]}
        <select data-testid="branding-target" className={select} value={value} disabled={loading || (kind === 'report' && projectForReport === '')} onChange={(e) => onChange(e.target.value)}>
          <option value="">{loading ? c.loading : c.choose}</option>
          {options.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
        </select>
        <span className="text-[11px] font-normal text-text-muted">{options.length === 0 && !loading && kind !== 'report' ? c.none : c.hint[scope]}</span>
      </label>
    </div>
  )
}
