import { useQuery } from '@tanstack/react-query'
import { Mail, Phone, ShieldCheck } from 'lucide-react'
import { portalSession } from '../clientPortalApi'
import { PortalShell } from './PortalShell'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { usePortalGuard } from './usePortalGuard'
import { useUi } from '@/stores/ui'

const COPY = {
  ar: {
    title: 'الملف الشخصي', subtitle: 'معلومات التواصل التي نستخدمها لتحديثاتك.',
    email: 'البريد الإلكتروني', phone: 'رقم الجوال', none: 'غير مُسجّل', error: 'تعذّر تحميل الملف الشخصي.',
    verified: 'هذه المعلومات مُوثّقة عبر رمز التحقق. لتعديلها تواصل مع فريقك — التعديل الذاتي غير متاح بعد.',
  },
  en: {
    title: 'Profile', subtitle: 'The contact details we use for your updates.',
    email: 'Email', phone: 'Phone', none: 'Not on file', error: 'Could not load your profile.',
    verified: 'These details are verified via your sign-in code. To change them, contact your team — self-service editing isn’t available yet.',
  },
}

export function ClientProfilePage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const q = useQuery({ queryKey: ['client', 'session'], queryFn: portalSession, retry: false })
  usePortalGuard(q.isError, q.error)

  return (
    <PortalShell title={t.title} nav showLogout>
      <div className="mb-5">
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">{t.title}</h1>
        <p className="mt-1 text-sm text-text-secondary">{t.subtitle}</p>
      </div>

      {q.isLoading ? (
        <div className="h-40 animate-pulse rounded-2xl bg-surface-secondary" />
      ) : q.isError ? (
        <QueryFailure error={q.error} ar={ar} onRetry={() => void q.refetch()} fallbackTitle={t.error} testId="portal-failure" />
      ) : (
        <div className="rounded-2xl border border-border bg-surface p-5 sm:p-6">
          <dl className="space-y-4">
            <Field icon={Mail} label={t.email} value={q.data?.contact_email} none={t.none} />
            <Field icon={Phone} label={t.phone} value={q.data?.contact_phone} none={t.none} />
          </dl>
          <p className="mt-5 flex items-start gap-1.5 rounded-xl bg-surface-secondary px-4 py-3 text-[13px] text-text-secondary">
            <ShieldCheck size={15} className="mt-0.5 shrink-0 text-success" /> {t.verified}
          </p>
        </div>
      )}
    </PortalShell>
  )
}

function Field({ icon: Icon, label, value, none }: {
  icon: typeof Mail; label: string; value: string | null | undefined; none: string
}) {
  return (
    <div className="flex items-center gap-3">
      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-surface-secondary text-text-secondary"><Icon size={17} /></span>
      <div>
        <dt className="text-[11px] font-semibold uppercase tracking-wide text-text-muted">{label}</dt>
        <dd className={`text-sm font-semibold ${value ? 'text-text-primary' : 'text-text-muted'}`} dir="ltr">{value || none}</dd>
      </div>
    </div>
  )
}
