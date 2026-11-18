import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { deleteData, getData, postData, putData } from '@/lib/api/client'

/**
 * ACCESS-CENTER-001 — «الفريق والنطاقات», as the API answers it.
 *
 * The areas and the categories come from the server's one catalogue (`AccessCatalogue`); this screen draws them and
 * decides nothing the server does not check again.
 */
export type Level = 'none' | 'view' | 'manage'
export const LEVELS: Level[] = ['none', 'view', 'manage']

export interface AreaLevel { level: Level; held: number; total: number }
export type AreaLevels = Record<string, AreaLevel>

export interface Area { key: string; ar: string; en: string }

/** ACCESS-SECTIONS-001 — the seven sections switched per member (layer three); on/off per section. */
export type SectionStates = Record<string, boolean>

export interface Category {
  slug: string
  name_ar: string
  name_en: string
  kind: 'owner' | 'builtin' | 'custom'
  all_clients: boolean
  admin: boolean
  areas: AreaLevels
  members: number
  pending: number
}

export interface Scope { all: boolean; clients: { id: string; name: string }[] }

export interface AccessMember {
  id: string
  membership_id: string
  name: string
  email: string
  audience: 'team' | 'client'
  category: string | null
  category_name_ar: string | null
  category_name_en: string | null
  is_owner: boolean
  is_self: boolean
  areas: AreaLevels | null
  sections?: SectionStates | null
  overrides: { allow: number; deny: number }
  override_areas: string[]
  scope: Scope
  status: 'active' | 'disabled'
  last_login_at: string | null
}

export interface AccessInvitation {
  id: string
  email: string
  audience: 'team' | 'client'
  category: string
  category_name_ar: string | null
  category_name_en: string | null
  areas: AreaLevels | null
  sections?: SectionStates | null
  overrides: { allow: number; deny: number }
  override_areas: string[]
  scope: Scope
  status: 'pending' | 'expired'
  delivery_status: string
  expires_at: string
  created_at: string
}

export interface AccessData {
  areas: Area[]
  sections?: Area[]
  categories: Category[]
  members: AccessMember[]
  invitations: AccessInvitation[]
  revoked: { id: string; email: string; at: string }[]
  clients: { id: string; name: string }[]
  portal_roles: string[]
  agency: boolean
  me: { is_owner: boolean; grantable: Record<string, Level>; all_clients: boolean }
}

export interface HistoryEntry { action: string; by: string | null; at: string; reason: string | null }

/** One field of a logged change: a scalar as from → to, a list as added / removed. */
export interface AuditChange { field: string; from?: string | number | boolean | null; to?: string | number | boolean | null; added?: string[]; removed?: string[] }
export interface AuditEntry { action: string; by: string | null; target: string | null; at: string; reason: string | null; changes: AuditChange[] }

const KEY = ['settings', 'access'] as const

export function useAccess() {
  return useQuery({ queryKey: KEY, queryFn: () => getData<AccessData>('/settings/access') })
}

export function useAccessHistory(type: 'member' | 'invitation' | 'category', id: string | null) {
  return useQuery({
    queryKey: [...KEY, 'history', type, id],
    queryFn: () => getData<{ entries: HistoryEntry[] }>(`/settings/access/history?type=${type}&id=${encodeURIComponent(id ?? '')}`),
    enabled: id !== null,
  })
}

/** ACCESS-SECTIONS-001 — the workspace's recent permission changes: who, whom, when, what. */
export function useAccessAudit() {
  return useQuery({ queryKey: [...KEY, 'audit'], queryFn: () => getData<{ entries: AuditEntry[] }>('/settings/access/audit?limit=30') })
}

export function useAccessActions() {
  const qc = useQueryClient()
  const inv = () => {
    void qc.invalidateQueries({ queryKey: KEY })
    void qc.invalidateQueries({ queryKey: ['settings', 'team'] })
    void qc.invalidateQueries({ queryKey: ['settings', 'roles'] })
  }
  return {
    invite: useMutation({
      mutationFn: (b: { email: string; category: string; areas?: Record<string, Level>; sections?: SectionStates; client_ids?: string[] }) =>
        postData<{ id: string; delivery_status: string; dev_link: string | null }>('/settings/access/invitations', b),
      onSuccess: inv,
    }),
    invitePortal: useMutation({
      mutationFn: (b: { client: string; email: string; role: string }) =>
        postData<{ state: string; dev_link: string | null }>(`/app/clients/${b.client}/portal-users`, { email: b.email, role: b.role }),
      onSuccess: inv,
    }),
    updateInvitation: useMutation({
      mutationFn: (b: { id: string; category?: string; areas?: Record<string, Level>; sections?: SectionStates; client_ids?: string[] }) => putData(`/settings/access/invitations/${b.id}`, b),
      onSuccess: inv,
    }),
    resend: useMutation({ mutationFn: (id: string) => postData(`/settings/access/invitations/${id}/resend`), onSuccess: inv }),
    revoke: useMutation({ mutationFn: (id: string) => deleteData(`/settings/team/invitations/${id}`), onSuccess: inv }),
    setCategory: useMutation({ mutationFn: (b: { id: string; role: string }) => putData(`/settings/team/${b.id}/role`, { role: b.role }), onSuccess: inv }),
    setOverrides: useMutation({ mutationFn: (b: { id: string; areas: Record<string, Level> }) => putData(`/settings/access/members/${b.id}/overrides`, { areas: b.areas }), onSuccess: inv }),
    /** ACCESS-SECTIONS-001 — one save for a member after the invitation: category, areas, sections, clients. */
    updateMember: useMutation({
      mutationFn: (b: { id: string; category?: string; areas?: Record<string, Level>; sections?: SectionStates; client_ids?: string[] }) =>
        putData(`/settings/access/members/${b.id}`, { category: b.category, areas: b.areas, sections: b.sections, client_ids: b.client_ids }),
      onSuccess: () => { inv(); void qc.invalidateQueries({ queryKey: ['me', 'access'] }) },
    }),
    setScope: useMutation({ mutationFn: (b: { membership: string; client_ids: string[] }) => putData(`/agency/team/${b.membership}/scopes`, { client_ids: b.client_ids }), onSuccess: inv }),
    toggle: useMutation({ mutationFn: (id: string) => postData(`/settings/team/${id}/toggle`), onSuccess: inv }),
    remove: useMutation({ mutationFn: (id: string) => deleteData(`/settings/team/${id}`), onSuccess: inv }),
    createCategory: useMutation({
      mutationFn: (b: { name: string; from?: string; areas: Record<string, Level>; all_clients?: boolean }) => postData<{ slug: string }>('/settings/access/categories', b),
      onSuccess: inv,
    }),
    updateCategory: useMutation({
      mutationFn: (b: { slug: string; name?: string; areas?: Record<string, Level>; all_clients?: boolean }) => putData(`/settings/access/categories/${b.slug}`, b),
      onSuccess: inv,
    }),
    deleteCategory: useMutation({ mutationFn: (slug: string) => deleteData(`/settings/access/categories/${slug}`), onSuccess: inv }),
  }
}

/** Only the sections that differ from where the person stands now. */
export function diffSections(base: SectionStates, chosen: SectionStates): SectionStates {
  const out: SectionStates = {}
  for (const [k, v] of Object.entries(chosen)) if ((base[k] ?? false) !== v) out[k] = v
  return out
}

/** The area levels a category starts from, flattened to what the toggles edit. */
export function levelsOf(areas: AreaLevels | null | undefined): Record<string, Level> {
  const out: Record<string, Level> = {}
  for (const [k, v] of Object.entries(areas ?? {})) out[k] = v.level
  return out
}

/** Only the areas that differ from the category: the overrides sent for ONE person. */
export function diffLevels(base: Record<string, Level>, chosen: Record<string, Level>): Record<string, Level> {
  const out: Record<string, Level> = {}
  for (const [k, v] of Object.entries(chosen)) if (base[k] !== v) out[k] = v
  return out
}

const RANK: Record<Level, number> = { none: 0, view: 1, manage: 2 }

/** Whether the reader may switch an area up to a level for somebody else (the server checks again). */
export function canGrant(grantable: Record<string, Level>, area: string, level: Level, current: Level): boolean {
  if (RANK[level] <= RANK[current]) return true // narrowing, or keeping, needs nothing
  return RANK[level] <= RANK[grantable[area] ?? 'none']
}

/** Whether the reader may hand out a whole category: every area within reach, and the team only by the owner. */
export function canAssign(cat: Category, me: AccessData['me']): boolean {
  if (cat.kind === 'owner') return me.is_owner
  if (cat.admin && !me.is_owner) return false
  if (cat.all_clients && !me.all_clients) return false
  return Object.entries(cat.areas).every(([k, v]) => RANK[v.level] <= RANK[me.grantable[k] ?? 'none'])
}
