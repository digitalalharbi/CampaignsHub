import { BarChart3, FolderOpen, Megaphone } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { PortalShell } from './PortalShell'
import { useUi } from '@/stores/ui'

/**
 * Honest "not yet available" page for portal sections whose backend endpoints don't exist yet
 * (files / campaigns / reports). We render NO fabricated data — just a clear, truthful empty state.
 * The moment a real endpoint ships, replace the matching route with a data-backed page.
 */
type Section = 'files' | 'campaigns' | 'reports'

const META: Record<Section, { icon: LucideIcon; ar: { title: string; note: string }; en: { title: string; note: string } }> = {
  files: {
    icon: FolderOpen,
    ar: { title: 'الملفات', note: 'مكتبة ملفات موحّدة عبر جميع طلباتك قيد الإعداد. حالياً تجد ملفات كل طلب داخل صفحة الطلب نفسه.' },
    en: { title: 'Files', note: 'A unified file library across all your requests is being built. For now, each request’s files live on that request’s page.' },
  },
  campaigns: {
    icon: Megaphone,
    ar: { title: 'الحملات', note: 'عرض حملاتك المباشر قيد الإعداد. عند تحويل طلبك إلى تنفيذ ستظهر تفاصيل الحملة داخل صفحة الطلب.' },
    en: { title: 'Campaigns', note: 'A live view of your campaigns is being built. Once your request is converted to delivery, the campaign details appear on that request’s page.' },
  },
  reports: {
    icon: BarChart3,
    ar: { title: 'التقارير', note: 'تقارير الأداء للعملاء قيد الإعداد ولم تُتَح بعد. سنُعلمك فور جاهزيتها.' },
    en: { title: 'Reports', note: 'Client performance reports are being built and aren’t available yet. We’ll let you know as soon as they’re ready.' },
  },
}

export function ClientComingSoonPage({ section }: { section: Section }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const m = META[section]
  const copy = ar ? m.ar : m.en
  const badge = ar ? 'غير متاح بعد' : 'Not yet available'

  return (
    <PortalShell title={copy.title} nav showLogout>
      <div className="mb-5">
        <h1 className="font-heading text-2xl font-extrabold text-text-primary">{copy.title}</h1>
      </div>
      <div className="flex flex-col items-center gap-3 rounded-2xl border border-border bg-surface p-12 text-center">
        <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-surface-secondary text-text-secondary"><m.icon size={26} /></span>
        <span className="rounded-full bg-warning/15 px-3 py-1 text-[11px] font-semibold text-warning">{badge}</span>
        <p className="max-w-md text-sm text-text-secondary">{copy.note}</p>
      </div>
    </PortalShell>
  )
}
