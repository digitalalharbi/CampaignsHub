import { useMemo, useState } from 'react'
import { Lock, RotateCcw, ShieldCheck } from 'lucide-react'
import { useRoleActions, useRoles, type PresetRole, type RoleSection, type SavedRole } from '../api'
import { Button } from '@/components/ui/Button'
import { Checkbox } from '@/components/ui/Checkbox'
import { Skeleton } from '@/components/ui/States'
import { QueryFailure } from '@/components/ui/QueryFailure'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * TEAM-ROLE-PRESETS-001 — «الأدوار والصلاحيات» (Owner, MASTER_FINAL Appendix A task 7).
 *
 * Ready-made roles, each edited with boxes: which page shows, and which action is allowed. The
 * screen decides nothing the server does not check again — every box is a permission key the routes
 * already enforce, and the server refuses a box the reader does not hold themselves. Those boxes are
 * drawn disabled rather than offered and then refused.
 */
export function RolesPanel() {
  const ar = useUi((u) => u.locale) === 'ar'
  const roles = useRoles()
  const [slug, setSlug] = useState<string>('project-manager')

  if (roles.isLoading) return <Skeleton className="h-64" />
  if (roles.isError || !roles.data) {
    return <QueryFailure error={roles.error} ar={ar} testId="settings-roles-failure"
      fallbackTitle={ar ? 'تعذّر تحميل الأدوار.' : 'The roles could not be loaded.'} />
  }

  const role = roles.data.roles.find((r) => r.slug === slug) ?? roles.data.roles[0]

  return (
    <section data-testid="roles-panel" className="rounded-2xl border border-border bg-surface p-5 shadow-[var(--shadow-small)]">
      <h2 className="mb-3 flex items-center gap-2 text-lg font-bold text-text-primary"><ShieldCheck size={18} /> {ar ? 'الأدوار والصلاحيات' : 'Roles & permissions'}</h2>
      <div role="tablist" aria-label={ar ? 'الأدوار' : 'Roles'} className="mb-4 flex flex-wrap gap-2">
        {roles.data.roles.map((r) => (
          <button
            key={r.slug}
            type="button"
            role="tab"
            aria-selected={r.slug === role.slug}
            data-testid={`role-chip-${r.slug}`}
            onClick={() => setSlug(r.slug)}
            className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold ${r.slug === role.slug ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-border text-text-secondary hover:text-text-primary'}`}
          >
            {r.locked && <Lock size={12} aria-hidden />}
            {ar ? r.name_ar : r.name_en}
            <span className="tnum rounded-full bg-surface-secondary px-1.5 text-xs text-text-muted">{r.members.toLocaleString('en-US')}</span>
          </button>
        ))}
      </div>
      <RoleEditor key={role.slug} role={role} sections={roles.data.sections} grantable={roles.data.grantable} ar={ar} />
    </section>
  )
}

function RoleEditor({ role, sections, grantable, ar }: { role: PresetRole; sections: RoleSection[]; grantable: string[]; ar: boolean }) {
  const { save, reset } = useRoleActions()
  const [ticked, setTicked] = useState<Set<string>>(() => new Set(role.permissions))
  const [saved, setSaved] = useState(false)

  // What the server last committed: the baseline «dirty» is measured against, replaced by each save's answer.
  const [committed, setCommitted] = useState<string[]>(role.permissions)
  const dirty = useMemo(() => [...ticked].sort().join(',') !== [...committed].sort().join(','), [ticked, committed])
  const done = (res: SavedRole) => { setCommitted(res.permissions); setTicked(new Set(res.permissions)); setSaved(true) }

  if (role.locked) {
    return (
      <p data-testid="role-locked" className="flex items-center gap-2 rounded-xl bg-surface-secondary px-4 py-3 text-sm text-text-secondary">
        <Lock size={15} aria-hidden /> {ar ? 'كل الصفحات وكل الإجراءات. لا يُعدّل.' : 'Every page and every action. Not editable.'}
      </p>
    )
  }

  const can = (key: string) => ticked.has(key) || grantable.includes(key)
  const flip = (key: string, on: boolean, section?: RoleSection) => {
    setSaved(false)
    setTicked((prev) => {
      const next = new Set(prev)
      if (on) next.add(key)
      else {
        next.delete(key)
        // Hiding the page takes its actions with it: an action on a page nobody can open is noise.
        if (section && key === section.page) section.actions.forEach((a) => next.delete(a.key))
      }
      return next
    })
  }

  const error = save.isError ? toApiError(save.error).message : reset.isError ? toApiError(reset.error).message : ''

  return (
    <div>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {sections.map((s) => {
          const pageOn = ticked.has(s.page)
          return (
            <div key={s.key} role="group" aria-label={ar ? s.ar : s.en} data-testid={`role-section-${s.key}`} className={`min-w-0 rounded-xl border p-3 ${pageOn ? 'border-brand-200 bg-brand-50/40' : 'border-border'}`}>
              <Checkbox
                id={`perm-${role.slug}-${s.page}`}
                data-testid={`perm-${s.page}`}
                label={ar ? s.ar : s.en}
                checked={pageOn}
                disabled={!can(s.page)}
                onChange={(e) => flip(s.page, e.target.checked, s)}
                className="font-semibold"
              />
              {s.actions.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1.5 ps-6">
                  {s.actions.map((a) => (
                    <Checkbox
                      key={a.key}
                      id={`perm-${role.slug}-${a.key}`}
                      data-testid={`perm-${a.key}`}
                      label={ar ? a.ar : a.en}
                      checked={ticked.has(a.key)}
                      disabled={!pageOn || !can(a.key)}
                      onChange={(e) => flip(a.key, e.target.checked)}
                    />
                  ))}
                </div>
              )}
            </div>
          )
        })}
      </div>
      <div className="mt-4 flex flex-wrap items-center gap-3">
        <Button
          type="button"
          data-testid="role-save"
          disabled={!dirty || save.isPending}
          onClick={() => save.mutate({ slug: role.slug, permissions: [...ticked] }, { onSuccess: done })}
        >
          {save.isPending ? (ar ? 'جارٍ الحفظ…' : 'Saving…') : (ar ? 'احفظ' : 'Save')}
        </Button>
        <Button type="button" variant="secondary" data-testid="role-reset" disabled={reset.isPending}
          onClick={() => reset.mutate(role.slug, { onSuccess: done })}>
          <RotateCcw size={14} aria-hidden /> {ar ? 'الافتراضي' : 'Default'}
        </Button>
        {saved && !dirty && <span role="status" data-testid="role-saved" className="text-xs text-success">{ar ? 'تم الحفظ' : 'Saved'}</span>}
        {error && <span role="alert" className="text-xs text-danger">{error}</span>}
      </div>
    </div>
  )
}
