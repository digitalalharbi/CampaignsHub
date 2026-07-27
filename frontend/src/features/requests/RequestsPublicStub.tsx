import { Link } from 'react-router-dom'
import { ArrowLeft, Search } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { useUi } from '@/stores/ui'

/**
 * Request TRACKING page — honest placeholder until the tracking UI ships (next commit). The intake form
 * at /requests/new is now the real dynamic experience (RequestIntakePage.tsx).
 */
function Shell({ icon, title, body }: { icon: React.ReactNode; title: string; body: string }) {
  const { locale } = useUi()
  const dir = locale === 'ar' ? 'rtl' : 'ltr'
  const Arrow = ArrowLeft
  return (
    <div dir={dir} className="flex min-h-screen flex-col items-center justify-center bg-background px-5 text-center text-text-primary">
      <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-primary-soft text-brand-600">{icon}</div>
      <h1 className="mt-5 font-heading text-2xl font-extrabold">{title}</h1>
      <p className="mt-2 max-w-md text-sm text-text-secondary">{body}</p>
      <Link to="/" className="mt-6"><Button variant="secondary"><Arrow size={15} className="me-1.5 rtl:rotate-180" />{locale === 'ar' ? 'العودة للصفحة الرئيسية' : 'Back to home'}</Button></Link>
    </div>
  )
}

export function RequestTrackPage() {
  const { locale } = useUi()
  return (
    <Shell
      icon={<Search size={26} />}
      title={locale === 'ar' ? 'تتبع الطلب' : 'Track your request'}
      body={locale === 'ar'
        ? 'أدخل رمز التتبع الآمن الخاص بطلبك — هذه الشاشة ستعرض حالة الطلب ومساره فور اكتمال بوابة الطلبات.'
        : 'Enter your secure tracking token — this screen will show your request status and timeline once the request portal is complete.'}
    />
  )
}
