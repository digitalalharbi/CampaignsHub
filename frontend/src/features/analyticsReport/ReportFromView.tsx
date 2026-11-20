import { useState } from 'react'
import { Check, Copy, ExternalLink, FileText } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { useUrlList } from '@/features/analytics/filterUrlState'
import type { Range } from '@/features/analytics/api'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { useCreateReportFromView } from './api'

/**
 * «إنشاء تقرير من هذا العرض» — the view's filters and period become a live /r/ link, through the report builder's own endpoint.
 *
 * Nothing here decides what a report may show: `POST /projects/{p}/reports/live` requires `reports.share` and checks every account and
 * campaign against the project, and the button is hidden for a reader without that permission rather than shown and refused. The link
 * is shown once, as the builder shows it, because only its hash is stored.
 */
export function ReportFromViewButton({ projectId, range }: { projectId: string; range: Range }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const allowed = useAuth((s) => s.hasPermission('reports.share'))
  const [providers] = useUrlList('provider')
  const [campaigns] = useUrlList('campaign')
  const [url, setUrl] = useState<string | null>(null)
  const [copied, setCopied] = useState(false)
  const create = useCreateReportFromView()
  if (!allowed) return null

  const name = `${ar ? 'تقرير من التحليلات' : 'Analytics report'} ${range.from} → ${range.to}`
  const run = () => create.mutate({ projectId, range, providers, campaigns, name }, { onSuccess: (r) => setUrl(r.url) })
  const failure = create.isError
    ? (create.error instanceof Error && create.error.message === 'no-accounts'
      ? (ar ? 'لا حسابات إعلانية لهذا الاختيار' : 'No ad accounts for this choice')
      : (ar ? 'تعذّر إنشاء التقرير' : 'The report could not be created'))
    : null

  return (
    <>
      <Button type="button" variant="secondary" size="sm" data-testid="report-from-view" disabled={create.isPending} onClick={run}>
        <FileText size={14} aria-hidden />
        {create.isPending ? (ar ? 'جارٍ الإنشاء…' : 'Creating…') : (ar ? 'إنشاء تقرير من هذا العرض' : 'Create a report from this view')}
      </Button>
      {failure && <span role="alert" data-testid="report-from-view-error" className="text-xs text-danger">{failure}</span>}
      <Modal open={url !== null} onClose={() => { setUrl(null); setCopied(false) }} size="md" title={ar ? 'رابط التقرير' : 'Report link'}>
        {url && (
          <div className="flex flex-col gap-3" data-testid="report-from-view-result">
            <p className="text-sm text-text-secondary">{ar ? 'ينعرض مرة وحدة، انسخه الحين.' : 'Shown once — copy it now.'}</p>
            <input readOnly dir="ltr" value={url} aria-label={ar ? 'الرابط' : 'Link'} data-testid="report-from-view-url" className="h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm" onFocus={(e) => e.currentTarget.select()} />
            <div className="flex flex-wrap gap-2">
              <Button type="button" size="sm" onClick={() => void navigator.clipboard?.writeText(url).then(() => setCopied(true))}>
                {copied ? <Check size={14} aria-hidden /> : <Copy size={14} aria-hidden />}
                {copied ? (ar ? 'انسخ' : 'Copied') : (ar ? 'نسخ الرابط' : 'Copy link')}
              </Button>
              <a href={url} target="_blank" rel="noreferrer" data-testid="report-from-view-open" className="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-text-primary">
                <ExternalLink size={14} aria-hidden />
                {ar ? 'افتح التقرير' : 'Open report'}
              </a>
            </div>
          </div>
        )}
      </Modal>
    </>
  )
}
