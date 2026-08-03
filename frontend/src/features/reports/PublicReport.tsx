import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { Download, Lock } from 'lucide-react'
import { fetchSharedReport, sharedDownloadUrl } from './api'
import type { ReportFormat } from './api'
import { InteractiveReport } from './InteractiveReport'
import { LiveSharedReport } from './LiveSharedReport'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { useUi } from '@/stores/ui'

interface Shared {
  name: string
  currency: string
  is_demo: boolean
  generated_at: string | null
  /** LIVEREP-001 — `live` renders the filterable dashboard; `snapshot` renders the generated document. */
  mode?: 'live' | 'snapshot'
  branding?: { name: string | null; logo_url: string | null; accent: string | null }
  settings: { allow_download: boolean; watermark: boolean }
  data: Record<string, unknown>
}

/** Client-facing report at /reports/share/:token — no app chrome, token-gated, optional password. */
export function PublicReport() {
  const { token = '' } = useParams()
  const { locale } = useUi()
  const [state, setState] = useState<'loading' | 'password' | 'ready' | 'error'>('loading')
  const [report, setReport] = useState<Shared | null>(null)
  const [password, setPassword] = useState('')
  const [message, setMessage] = useState('')

  /*
   * The password that actually worked, kept so the live endpoint can be called with it.
   *
   * Separate from `password`, which is bound to the input and is whatever the reader has typed at this
   * instant — including a wrong guess, or an empty field after a re-render. Sending that to the live
   * endpoint on every filter change would start 401-ing partway through a session that is working fine.
   */
  const [accepted, setAccepted] = useState<string | undefined>(undefined)

  const load = async (pw?: string) => {
    setState('loading')
    const { status, envelope } = await fetchSharedReport(token, pw)
    if (status === 200) {
      setReport(envelope.data)
      setAccepted(pw)
      setState('ready')
    } else if (status === 401) {
      setState('password')
      setMessage(pw ? 'كلمة المرور غير صحيحة.' : '')
    } else {
      setState('error')
      setMessage(envelope.message ?? 'الرابط غير صالح أو انتهت صلاحيته.')
    }
  }

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token])

  const platforms = ((report?.data?.platforms as Array<Record<string, unknown>>) ?? []).map((p) => String(p.provider))

  return (
    <div dir={locale === 'ar' ? 'rtl' : 'ltr'} className="min-h-screen bg-background text-text-primary">
      <header className="sticky top-0 z-10 border-b border-border bg-surface/85 px-4 py-3 backdrop-blur-md sm:px-8">
        <div className="mx-auto flex max-w-[1100px] items-center justify-between">
          <span className="font-heading text-lg font-extrabold tracking-tight">{report?.branding?.name ?? 'CampaignsHub'}</span>
          {report && (
            <div className="flex items-center gap-2">
              {report.is_demo && <span className="rounded-full bg-[var(--warning-background)] px-2 py-0.5 text-xs font-semibold text-warning">Demo</span>}
              {report.settings.allow_download && state === 'ready' && (
                <div className="flex gap-1">
                  {(['pdf', 'xlsx', 'csv'] as ReportFormat[]).map((f) => (
                    <a key={f} href={sharedDownloadUrl(token, f)} className="rounded-lg border border-border px-2 py-1 text-xs font-semibold hover:bg-surface-hover">
                      <Download size={12} className="inline" /> {f.toUpperCase()}
                    </a>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>
      </header>

      <main className="mx-auto max-w-[1100px] px-4 py-8 sm:px-8">
        {state === 'loading' && <p className="py-20 text-center text-text-secondary">جارٍ التحميل…</p>}

        {state === 'password' && (
          <div className="mx-auto max-w-sm rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
            <div className="mb-4 flex items-center gap-2">
              <Lock size={18} className="text-brand-600" />
              <h1 className="text-lg font-bold">تقرير محمي بكلمة مرور</h1>
            </div>
            <form
              onSubmit={(e) => {
                e.preventDefault()
                load(password)
              }}
            >
              <Field label="كلمة المرور" error={message || undefined}>
                <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} className="w-full rounded-xl border border-border bg-surface px-3 py-2.5 text-base outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20" />
              </Field>
              <Button className="mt-3 w-full">عرض التقرير</Button>
            </form>
          </div>
        )}

        {state === 'error' && (
          <div className="mx-auto max-w-md rounded-2xl border border-border bg-surface p-8 text-center shadow-[var(--shadow-small)]">
            <p className="text-lg font-bold">تعذّر فتح التقرير</p>
            <p className="mt-2 text-sm text-text-secondary">{message}</p>
          </div>
        )}

        {state === 'ready' && report && (
          <div className="relative">
            {report.settings.watermark && (
              <div className="pointer-events-none absolute inset-0 z-0 flex items-center justify-center overflow-hidden">
                <span className="rotate-[-25deg] text-[80px] font-extrabold text-text-primary/5">CampaignsHub</span>
              </div>
            )}
            <div className="relative z-[1]">
              <h1 className="mb-4 font-heading text-xl font-extrabold tracking-tight sm:text-2xl">{report.name}</h1>

              {/*
                A live link is a dashboard the client filters; a snapshot link is the document that was
                signed off. They get different readers, so they get different components — see the note
                at the top of LiveSharedReport for why folding them together would force the page to
                mislabel one half of itself.
              */}
              {report.mode === 'live' ? (
                <LiveSharedReport token={token} password={accepted} currency={report.currency} />
              ) : (
                <>
                  <InteractiveReport
                    data={report.data as never}
                    meta={{ reportName: report.name, platforms, isDemo: report.is_demo, agencyName: report.branding?.name ?? 'CampaignsHub' }}
                  />
                  <ReportMetaStrip report={report} />
                </>
              )}
            </div>
          </div>
        )}
      </main>
    </div>
  )
}

/** Truthful data-lineage strip for the client link: what the numbers are, and how fresh. */
function ReportMetaStrip({ report }: { report: Shared }) {
  const d = report.data as Record<string, unknown>
  const period = d.period as { from?: string; to?: string } | undefined
  const objective = typeof d.objective === 'string' ? d.objective : undefined
  const mode = (d.mode as string) === 'live' ? 'Live' : 'Snapshot'
  const items: Array<[string, string]> = [
    ['آخر تحديث', report.generated_at ? new Date(report.generated_at).toLocaleString('en-GB') : '—'],
    ['الفترة', period?.from && period?.to ? `${period.from} → ${period.to}` : '—'],
    ['العملة', report.currency],
    ['مصدر البيانات', String((d.data_source as string) ?? 'daily_metrics')],
    ['نموذج/نافذة الإسناد', String((d.attribution_window as string) ?? '—')],
    ['المنطقة الزمنية', String((d.timezone as string) ?? 'Asia/Riyadh')],
    ['حالة التقرير', mode],
  ]
  if (objective) items.push(['هدف الحملة', objective])
  return (
    <div className="mt-4 flex flex-wrap gap-x-5 gap-y-1.5 rounded-xl border border-border bg-surface-secondary px-4 py-3 text-xs text-text-secondary">
      {items.map(([k, v]) => (
        <span key={k}><span className="text-text-muted">{k}:</span> <b className="tnum font-semibold text-text-primary">{v}</b></span>
      ))}
    </div>
  )
}
