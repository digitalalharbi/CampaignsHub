import type { MetaAppRegistryState, MetaAppRole } from './api'
import { Num } from '@/components/ui/Num'

/**
 * META-MULTI-APP-001 — every registered Meta app, its role, public App ID and how many connections belong to it.
 *
 * Connections are counted under the app Meta VERIFIED as their issuer; every other connection is counted under its recorded profile
 * and shown separately as «unverified», so an uncertain row is visible rather than silently attributed. Only the public App ID is
 * shown; no secret ever reaches this component.
 */
const COPY = {
  ar: {
    title: 'تطبيقات ميتا المسجّلة',
    note: 'الاتصال يُحسب على التطبيق الذي أكّدته ميتا؛ وغير المؤكَّد يُحسب على تطبيقه المسجّل ويُعرض منفصلًا.',
    app: 'التطبيق', role: 'الدور', appId: 'معرّف التطبيق', configured: 'مُعدّ', connections: 'الاتصالات', unverified: 'غير مؤكَّدة', yes: 'نعم', no: 'لا',
    profiles: { live: 'Live', saas: 'SaaS', candidate: 'Candidate' } as Record<string, string>,
    roles: { primary: 'أساسي', legacy_sync_only: 'قديم — مزامنة فقط', not_primary: 'غير أساسي', test_only: 'اختبار فقط' } as Record<MetaAppRole, string>,
    unattributed: 'اتصالات مؤكَّدة على معرّف تطبيق غير مسجّل',
  },
  en: {
    title: 'Registered Meta apps',
    note: 'A connection is counted under the app Meta verified as its issuer; any other connection is counted under its recorded profile and shown separately as unverified.',
    app: 'App', role: 'Role', appId: 'App ID', configured: 'Configured', connections: 'Connections', unverified: 'Unverified', yes: 'Yes', no: 'No',
    profiles: { live: 'Live', saas: 'SaaS', candidate: 'Candidate' } as Record<string, string>,
    roles: { primary: 'Primary', legacy_sync_only: 'Legacy — sync only', not_primary: 'Not primary', test_only: 'Test only' } as Record<MetaAppRole, string>,
    unattributed: 'Verified connections on an unregistered App ID',
  },
}

export function MetaSaasRegistry({ registry, ar }: { registry: MetaAppRegistryState; ar: boolean }) {
  const c = COPY[ar ? 'ar' : 'en']

  return (
    <section data-testid="meta-registry" className="rounded-2xl border border-border bg-surface p-5">
      <h2 className="font-heading text-[15px] font-bold text-text-primary">{c.title}</h2>
      <p className="mt-1 max-w-3xl text-xs text-text-secondary">{c.note}</p>
      {/* Cards at phone width, a plain list everywhere: no horizontal page scroll. */}
      <ul className="mt-3 grid gap-3 md:grid-cols-3">
        {registry.apps.map((app) => (
          <li key={app.profile} data-testid={`meta-registry-${app.profile}`} data-role={app.role} className="min-w-0 rounded-xl border border-border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <span className="text-sm font-bold text-text-primary">{c.profiles[app.profile] ?? app.profile}</span>
              <span data-testid={`meta-registry-${app.profile}-role`} className="rounded-full bg-surface-secondary px-2.5 py-0.5 text-[11px] font-semibold text-text-secondary">{c.roles[app.role]}</span>
            </div>
            <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
              <dt className="text-text-muted">{c.appId}</dt>
              <dd className="min-w-0 break-all font-semibold text-text-primary"><bdi dir="ltr">{app.app_id ?? '—'}</bdi></dd>
              <dt className="text-text-muted">{c.configured}</dt>
              <dd className="font-semibold text-text-primary">{app.configured ? c.yes : c.no}</dd>
              <dt className="text-text-muted">{c.connections}</dt>
              <dd className="tnum font-semibold text-text-primary" data-testid={`meta-registry-${app.profile}-connections`}><Num>{app.connections}</Num></dd>
              <dt className="text-text-muted">{c.unverified}</dt>
              <dd className="tnum font-semibold text-text-primary" data-testid={`meta-registry-${app.profile}-unverified`}><Num>{app.unverified_connections}</Num></dd>
            </dl>
          </li>
        ))}
      </ul>
      {registry.unattributed > 0 && (
        <p className="mt-2 text-xs text-text-secondary" data-testid="meta-registry-unattributed">{c.unattributed}: <Num>{registry.unattributed}</Num></p>
      )}
    </section>
  )
}
