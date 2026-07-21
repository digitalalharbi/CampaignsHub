import { Card, CardDescription, CardTitle } from '@/components/ui/Card'

/** Honest placeholder for routes whose domain UI is not built yet — no fake data, no dead widgets. */
export function PagePlaceholder({ title }: { title: string }) {
  return (
    <section className="space-y-5">
      <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{title}</h1>
      <Card>
        <CardTitle>{title}</CardTitle>
        <CardDescription>
          This module is part of a later phase. The foundation (auth, tenancy, API envelope, design
          system) is in place; domain screens are built on top of it phase by phase.
        </CardDescription>
      </Card>
    </section>
  )
}
