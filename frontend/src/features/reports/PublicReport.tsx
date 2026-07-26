import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { Download, Lock } from 'lucide-react'
import { fetchSharedReport, sharedDownloadUrl } from './api'
import type { ReportFormat } from './api'
import { InteractiveReport } from './InteractiveReport'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { useUi } from '@/stores/ui'

interface Shared {
  name: string
  currency: string
  is_demo: boolean
  generated_at: string | null
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

  const load = async (pw?: string) => {
    setState('loading')
    const { status, envelope } = await fetchSharedReport(token, pw)
    if (status === 200) {
      setReport(envelope.data)
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
          <span className="font-heading text-lg font-extrabold tracking-tight">CampaignsHub</span>
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
              <InteractiveReport
                data={report.data as never}
                meta={{ reportName: report.name, platforms, isDemo: report.is_demo, agencyName: 'CampaignsHub' }}
              />
            </div>
          </div>
        )}
      </main>
    </div>
  )
}
