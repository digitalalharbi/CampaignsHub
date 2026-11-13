import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { DeliveryGroupCard, SiteGroupCard, TargetingList } from '@/features/audience/AudienceBlocks'
import type { AudienceReading } from '@/features/audience/types'

/**
 * AUDIENCE-001 — «الجمهور» under the client's performance dashboard.
 *
 * The server decides everything: the agency's two switches (dashboard, audience), the projects this contact may reach,
 * and the client reading (no money, no campaign or ad-set names). A refusal draws nothing — the section is simply not
 * offered. Each project is its own block; platforms and projects are never added together.
 */
interface PortalAudienceResponse { period: { from: string; to: string }; projects: { project: { id: string; name: string }; reading: AudienceReading }[] }

export function PortalAudience({ from, to }: { from?: string; to?: string }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const locale = ar ? 'ar' : 'en'
  const query = new URLSearchParams(Object.entries({ from, to }).filter(([, v]) => v) as [string, string][]).toString()
  const q = useQuery({
    queryKey: ['portal', 'audience', from ?? null, to ?? null],
    queryFn: () => getData<PortalAudienceResponse>(`/client/audience${query === '' ? '' : `?${query}`}`),
    retry: false,
  })
  if (q.isPending || q.isError || !q.data) return null
  const projects = (Array.isArray(q.data.projects) ? q.data.projects : []).filter((p) => (p.reading?.paid_delivery?.groups?.length ?? 0) + (p.reading?.targeting?.items?.length ?? 0) + (p.reading?.site_events?.groups?.length ?? 0) > 0)
  if (projects.length === 0) return null

  return (
    <section data-testid="portal-audience" className="mb-6 flex min-w-0 flex-col gap-3">
      <h2 className="text-base font-extrabold text-text-primary">{ar ? 'الجمهور' : 'Audience'}</h2>
      <p className="text-xs text-text-secondary">{ar ? 'كل منصة بمقامها — لا تُجمع المنصات. الاستهداف إعداد وليس تسليمًا، وزوار الموقع ليسوا جمهور الإعلان.' : 'Each platform on its own denominator — never added. Targeting is a setting, not delivery; site visitors are not the ads\' audience.'}</p>
      {projects.map(({ project, reading }) => (
        <div key={project.id} data-testid={`portal-audience-${project.id}`} className="flex min-w-0 flex-col gap-2">
          {projects.length > 1 && <h3 className="text-sm font-bold text-text-primary">{project.name}</h3>}
          <div className="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            {(reading.paid_delivery?.groups ?? []).map((g) => <DeliveryGroupCard key={`${g.provider}-${g.dimension}`} group={g} locale={locale} />)}
            {(reading.targeting?.items?.length ?? 0) > 0 && <TargetingList items={reading.targeting.items} locale={locale} />}
            {(reading.site_events?.groups ?? []).map((g) => <SiteGroupCard key={g.dimension} group={g} locale={locale} />)}
          </div>
        </div>
      ))}
    </section>
  )
}
