import { Construction } from 'lucide-react'
import { Card } from '@/components/ui/Card'
import { useT } from '@/lib/i18n'

/** Honest placeholder for routes whose domain is not built yet — clearly labelled, no fake widgets. */
export function PagePlaceholder({ title }: { title: string }) {
  const t = useT()
  return (
    <section className="space-y-5">
      <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{title}</h1>
      <Card>
        <div className="flex flex-col items-center gap-2 py-8 text-center">
          <Construction size={28} className="text-warning" />
          <p className="text-sm font-bold text-text-primary">{t('not_implemented')}</p>
          <p className="max-w-md text-[12px] text-text-secondary">
            The foundation (auth, tenancy, projects, per-project source binding, isolation) is in
            place. This domain is built on top of it phase by phase — it is not presented as working
            until it actually is.
          </p>
        </div>
      </Card>
    </section>
  )
}
