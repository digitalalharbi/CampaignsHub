/**
 * ROLE-LABEL-001 — a system role reads in the reader's language; a customer's own role does not.
 *
 * The team page's role dropdown offered «Tenant Owner» and «Team Member» — English, inside an
 * Arabic page — because it rendered `r.name` straight from the roles table. Those names are seeded
 * by the product and belong to it.
 *
 * A role a tenant CREATED carries the name they typed. Translating that would rename their work, and
 * a lookup that fell back to «unknown role» would erase it. So the payload says which kind it is
 * (`is_system`), and only the product's own names are translated — a custom role keeps its name
 * exactly as entered, in either language.
 *
 * Keys are the slugs of the roles seeded with `is_system => true`; `roleLabels.test.ts` asserts the
 * list matches, so a system role added in PHP fails a test rather than reaching a customer in the
 * wrong language.
 */
export const SYSTEM_ROLE_LABELS: Record<string, { ar: string; en: string }> = {
  'tenant-owner': { ar: 'مالك مساحة العمل', en: 'Tenant Owner' },
  'member': { ar: 'عضو فريق', en: 'Team Member' },
  'analyst': { ar: 'محلّل', en: 'Analyst' },
  'account-manager': { ar: 'مدير حساب', en: 'Account Manager' },
  'client-viewer': { ar: 'مُطّلع (عميل)', en: 'Client Viewer' },
  'client-portal': { ar: 'بوابة العميل', en: 'Client Portal' },
  'talent-manager': { ar: 'مدير المواهب', en: 'Talent Manager' },
}

export type RoleRef = { slug: string; name: string; is_system?: boolean }

export function roleLabel(role: RoleRef, ar: boolean): string {
  // Only the product's own roles are translated. A tenant's role is theirs, and keeps its name.
  if (role.is_system === false) return role.name

  const label = SYSTEM_ROLE_LABELS[role.slug]

  /*
   * An unknown system slug falls back to the stored name rather than to the slug or to «unknown».
   * The stored name is at least a human-readable English label somebody wrote; a slug is not, and
   * «unknown» destroys information the reader could otherwise act on.
   */
  return label ? (ar ? label.ar : label.en) : role.name
}
