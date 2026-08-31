import { useEffect, useState } from 'react'
import { fmtDateTime } from '@/lib/datetime'
import { useParams } from 'react-router-dom'
import { Download, Lock } from 'lucide-react'
import { fetchSharedReport, sharedBranding, sharedDownloadUrl } from './api'
import type { ReportFormat } from './api'
import { headerIdentity, hideBrokenLogo, type SharedBranding } from './sharedBranding'
import { InteractiveReport } from './InteractiveReport'
import { LiveSharedReport } from './LiveSharedReport'
import { productLabel } from './reportProduct'
import { SharedAttributionSection } from './SharedAttributionSection'
import { SharedCreativeSection } from './SharedCreativeSection'
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
  /**
   * REPORT-LINKS-13 — what the report IS, independent of where its numbers come from.
   *
   * A reader who opens a five-page summary needs to know it is a summary, or the absence of the
   * per-platform pages reads as data missing rather than as a deliberately shorter document.
   */
  form?: 'executive_summary' | 'detailed'
  branding?: { name: string | null; logo_url: string | null; accent: string | null }
  settings: { allow_download: boolean; watermark: boolean }
  /**
   * §15.12 — what this link may show about the content, stated before anything is drawn.
   *
   * Read here rather than discovered by calling the creative endpoint and handling its refusal: a
   * section that appears and then fails is worse than one that never appears, and a client cannot
   * tell the difference between «not shared» and «broken».
   */
  creatives?: { creatives: boolean }
  /**
   * ATTRIB-VIS-001 — which optional sections this link may open.
   *
   * Read for the same reason as `creatives` above: the section is mounted only when the link
   * carries it, so there is no path that renders a control which then answers 404.
   */
  sections?: { attribution: boolean }
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

  /*
   * Asked separately from the report, and deliberately not gated on it: the header is the frame
   * around the password prompt too, so a reader who has not yet unlocked the report still has to be
   * shown WHOSE report they are being asked to unlock.
   */
  const [branding, setBranding] = useState<SharedBranding | undefined>(undefined)
  useEffect(() => {
    let alive = true
    sharedBranding(token)
      .then((b) => alive && setBranding(b))
      // A failed branding lookup must never take the report down with it: `headerIdentity`
      // already answers «CampaignsHub, no mark» for undefined, which is the honest fallback.
      .catch(() => undefined)

    return () => { alive = false }
  }, [token])

  const identity = headerIdentity(branding)

  return (
    <div dir={locale === 'ar' ? 'rtl' : 'ltr'} className="min-h-screen bg-background text-text-primary">
      <header className="sticky top-0 z-10 border-b border-border bg-surface/85 px-4 py-3 backdrop-blur-md sm:px-8">
        <div className="mx-auto flex max-w-[1100px] items-center justify-between">
          {/*
            BRANDING-HIERARCHY-001 — the client's own identity, or the agency's, or the product's.
          
            This was `report.branding.name ?? 'CampaignsHub'` with no mark at all, so a report whose
            stored config carried no branding put the PRODUCT's name on an agency's client report. The
            chain is resolved by the backend, which alone knows the tenant; `logoUrl` is null rather
            than a URL that 404s, because a broken image here reads as a broken report.
          */}
          <span className="flex items-center gap-2">
            {identity.logoUrl && (
              <img src={identity.logoUrl} alt="" data-testid="shared-report-logo" onError={hideBrokenLogo} className="h-7 w-auto max-w-[160px] object-contain" />
            )}
            <span className="font-heading text-lg font-extrabold tracking-tight" data-testid="shared-report-name">{identity.name}</span>
            {identity.by && (
              <span className="text-xs text-text-secondary" data-testid="shared-report-by">
                {locale === 'ar' ? `بواسطة ${identity.by}` : `by ${identity.by}`}
              </span>
            )}
          </span>
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
              <h1 className="mb-2 font-heading text-xl font-extrabold tracking-tight sm:text-2xl">{report.name}</h1>

              {/*
                Says which document this is, beside its own title. Without it, a client opening the
                summary sees no per-platform section and reasonably concludes the report is
                incomplete — the one reading the trimming was meant to prevent.
              */}
              <p data-testid="report-form-label" className="mb-4 text-xs font-semibold text-text-secondary">
                {productLabel(report.mode ?? 'snapshot', report.form ?? 'detailed', locale)}
              </p>

              {/*
                A live link is a dashboard the client filters; a snapshot link is the document that was
                signed off. They get different readers, so they get different components — see the note
                at the top of LiveSharedReport for why folding them together would force the page to
                mislabel one half of itself.
              */}
              {report.mode === 'live' ? (
                <LiveSharedReport token={token} password={accepted} currency={report.currency} form={report.form ?? 'detailed'} />
              ) : (
                <>
                  <InteractiveReport
                    data={report.data as never}
                    meta={{ reportName: report.name, platforms, isDemo: report.is_demo, agencyName: report.branding?.name ?? 'CampaignsHub' }}
                  />
                  <ReportMetaStrip report={report} />
                </>
              )}

              {/*
                The creative sections, for BOTH modes.
                A live link and a snapshot link differ in where the report's own figures come from;
                the content section reads the pipeline either way, and says when it last synced. What
                keeps a snapshot honest is its ceiling: the link's window stops where the document
                does, so the section cannot show weeks the rest of the report never covered.
              */}
              {/*
                ATTRIB-VIS-001 — the answer to «why does your report say 1,169 orders when my shop
                recorded 640?», for the links whose operator chose to give it.
              */}
              {report.sections?.attribution && <SharedAttributionSection token={token} password={accepted} />}
              {report.creatives?.creatives && (
                <SharedCreativeSection
                  token={token}
                  password={accepted}
                  currency={report.currency}
                  form={report.form === 'executive_summary' ? 'executive_summary' : 'detailed'}
                />
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
    ['آخر تحديث', report.generated_at ? fmtDateTime(report.generated_at) : '—'],
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
