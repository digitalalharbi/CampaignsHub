import { useEffect, useMemo, useRef, useState, type ComponentType } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  ChevronDown, ChevronUp, GitMerge, MoreVertical, Pencil, Plus, Shuffle, Star, Ban,
} from 'lucide-react'
import * as LucideIcons from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { Switch } from '@/components/ui/Switch'
import { EmptyState, ErrorState, Skeleton } from '@/components/ui/States'
import { OptionDrawer } from '@/components/forms'
import type { Option, OptionDraft } from '@/components/forms'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { TAX_COPY, taxOptionLabel } from './taxonomyCopy'
import { OptionEditDrawer } from './OptionEditDrawer'
import { MergeReassignDialog, type MergeMode } from './MergeReassignDialog'
import {
  createOption, deactivateOption, mergeOption, reassignOption, reorderOptions,
  updateOption, useTaxonomyOptions, type OptionPayload, type TaxonomyDefinition, type TaxonomyOption,
} from './taxonomyApi'

interface Perms {
  canManage: boolean
  canCreate: boolean
  canEdit: boolean
  canMerge: boolean
  canDeactivate: boolean
}

/** Right-hand panel: the selected definition's options as a reorderable, actionable list. */
export function OptionsPanel({ definition }: { definition: TaxonomyDefinition }) {
  const locale = useUi((s) => s.locale)
  const c = TAX_COPY[locale]
  const qc = useQueryClient()
  const hasPermission = useAuth((s) => s.hasPermission)

  const perms: Perms = {
    canManage: hasPermission('taxonomies.manage'),
    canCreate: hasPermission('options.create'),
    canEdit: hasPermission('taxonomies.manage') || hasPermission('options.update'),
    canMerge: hasPermission('taxonomies.manage') || hasPermission('options.merge'),
    canDeactivate: hasPermission('taxonomies.manage') || hasPermission('options.deactivate'),
  }
  const canReorder = perms.canManage

  const q = useTaxonomyOptions(definition.key, undefined, true)
  const rows = useMemo(() => [...(q.data ?? [])].sort((a, b) => a.sort_order - b.sort_order), [q.data])

  // Local order mirror so reordering feels instant; re-synced when the query data changes.
  const [order, setOrder] = useState<TaxonomyOption[]>(rows)
  useEffect(() => setOrder(rows), [rows])

  const invalidate = () => qc.invalidateQueries({ queryKey: ['taxonomies', definition.key] })

  const reorderM = useMutation({
    mutationFn: (ids: string[]) => reorderOptions(ids),
    onSuccess: invalidate,
    onError: () => setOrder(rows), // revert on failure
  })

  const updateM = useMutation({
    mutationFn: ({ id, patch }: { id: string; patch: Partial<OptionPayload> }) => updateOption(id, patch),
    onSuccess: invalidate,
  })
  const deactivateM = useMutation({ mutationFn: (id: string) => deactivateOption(id), onSuccess: invalidate })

  // Drawers / dialogs -----------------------------------------------------------
  const [createOpen, setCreateOpen] = useState(false)
  const [childParent, setChildParent] = useState<TaxonomyOption | null>(null)
  const [editing, setEditing] = useState<TaxonomyOption | null>(null)
  const [mergeState, setMergeState] = useState<{ mode: MergeMode; source: TaxonomyOption } | null>(null)

  const move = (index: number, dir: -1 | 1) => {
    const next = [...order]
    const target = index + dir
    if (target < 0 || target >= next.length) return
    ;[next[index], next[target]] = [next[target], next[index]]
    setOrder(next)
    reorderM.mutate(next.map((o) => o.id))
  }

  const submitCreate = async (draft: OptionDraft, parentId: string | null): Promise<Option> => {
    const payload: OptionPayload = {
      label_ar: draft.label_ar,
      label_en: draft.label_en,
      description: draft.description ?? null,
      color: draft.color ?? null,
      icon: draft.icon ?? null,
      parent_option_id: parentId,
    }
    const created = await createOption(definition.key, payload)
    invalidate()
    return { value: created.id, label_ar: created.label_ar, label_en: created.label_en }
  }

  const isHierarchical = definition.field_type === 'hierarchical'
  const parents: Option[] = isHierarchical
    ? order.filter((o) => !o.parent_option_id).map((o) => ({ value: o.id, label_ar: o.label_ar, label_en: o.label_en }))
    : []

  // ---- states -----------------------------------------------------------------
  if (q.isPending) {
    return (
      <div className="flex flex-col gap-2">
        {[0, 1, 2].map((i) => <Skeleton key={i} className="h-16 w-full" />)}
      </div>
    )
  }
  if (q.isError) {
    return <ErrorState title={c.loadError} onRetry={() => q.refetch()} />
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between gap-3">
        <div className="min-w-0">
          <h2 className="truncate text-lg font-bold text-text-primary">
            {locale === 'ar' ? definition.label_ar : definition.label_en}
          </h2>
          <p className="text-xs text-text-tertiary" dir="ltr">{definition.key}</p>
        </div>
        {perms.canCreate && definition.allows_custom_options && (
          <Button size="sm" onClick={() => setCreateOpen(true)}>
            <Plus size={15} /> {c.addOption}
          </Button>
        )}
      </div>

      {!perms.canManage && !perms.canCreate && !perms.canEdit && (
        <p className="rounded-lg bg-surface-hover px-3 py-2 text-xs text-text-secondary">{c.readOnly}</p>
      )}

      {order.length === 0 ? (
        <EmptyState title={c.noOptions} description={c.noOptionsHint} />
      ) : (
        <ul className="flex flex-col gap-2">
          {order.map((opt, i) => (
            <OptionRow
              key={opt.id}
              opt={opt}
              index={i}
              total={order.length}
              indented={Boolean(opt.parent_option_id)}
              perms={perms}
              canReorder={canReorder}
              allowChildren={isHierarchical && perms.canCreate && definition.allows_custom_options}
              onMove={move}
              onEdit={() => setEditing(opt)}
              onAddChild={() => setChildParent(opt)}
              onSetDefault={() => updateM.mutate({ id: opt.id, patch: { is_default: true } })}
              onToggleActive={(active) =>
                active ? updateM.mutate({ id: opt.id, patch: { is_active: true } }) : deactivateM.mutate(opt.id)
              }
              onMerge={() => setMergeState({ mode: 'merge', source: opt })}
              onReassign={() => setMergeState({ mode: 'reassign', source: opt })}
            />
          ))}
        </ul>
      )}

      {/* Create (root) — hierarchical defs get a parent picker. */}
      <OptionDrawer
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        title={c.addOption}
        parents={parents.length > 0 ? parents : undefined}
        onSubmit={(draft) => submitCreate(draft, draft.parentValue ?? null)}
        onCreated={() => {}}
      />

      {/* Create child — parent is preset to the clicked option (picker hidden). */}
      <OptionDrawer
        open={Boolean(childParent)}
        onClose={() => setChildParent(null)}
        title={`${c.addChild}${childParent ? ` — ${taxOptionLabel(childParent, locale)}` : ''}`}
        onSubmit={(draft) => submitCreate(draft, childParent?.id ?? null)}
        onCreated={() => {}}
      />

      <OptionEditDrawer
        open={Boolean(editing)}
        option={editing}
        onClose={() => setEditing(null)}
        onSubmit={(id, patch) => updateOption(id, patch)}
        onSaved={invalidate}
      />

      <MergeReassignDialog
        open={Boolean(mergeState)}
        mode={mergeState?.mode ?? 'merge'}
        source={mergeState?.source ?? null}
        candidates={order}
        onClose={() => setMergeState(null)}
        onConfirm={async (sourceId, targetId) => {
          if (mergeState?.mode === 'merge') await mergeOption(sourceId, targetId)
          else await reassignOption(sourceId, targetId)
          invalidate()
        }}
      />
    </div>
  )
}

// ---- a single option row ------------------------------------------------------
function OptionRow({
  opt, index, total, indented, perms, canReorder, allowChildren,
  onMove, onEdit, onAddChild, onSetDefault, onToggleActive, onMerge, onReassign,
}: {
  opt: TaxonomyOption
  index: number
  total: number
  indented: boolean
  perms: Perms
  canReorder: boolean
  allowChildren: boolean
  onMove: (index: number, dir: -1 | 1) => void
  onEdit: () => void
  onAddChild: () => void
  onSetDefault: () => void
  onToggleActive: (active: boolean) => void
  onMerge: () => void
  onReassign: () => void
}) {
  const locale = useUi((s) => s.locale)
  const c = TAX_COPY[locale]
  const usage = opt.usage_count ?? 0
  const anyAction = perms.canEdit || perms.canMerge || perms.canDeactivate || allowChildren

  return (
    <li
      className={`flex items-center gap-3 rounded-2xl border border-border bg-surface p-3 ${indented ? 'ms-6' : ''} ${
        opt.is_active ? '' : 'opacity-70'
      }`}
    >
      {canReorder && (
        <div className="flex flex-col">
          <button
            type="button"
            aria-label={c.moveUp}
            disabled={index === 0}
            onClick={() => onMove(index, -1)}
            className="rounded p-0.5 text-text-muted hover:bg-surface-hover disabled:opacity-30"
          >
            <ChevronUp size={14} />
          </button>
          <button
            type="button"
            aria-label={c.moveDown}
            disabled={index === total - 1}
            onClick={() => onMove(index, 1)}
            className="rounded p-0.5 text-text-muted hover:bg-surface-hover disabled:opacity-30"
          >
            <ChevronDown size={14} />
          </button>
        </div>
      )}

      <span
        className="grid h-7 w-7 shrink-0 place-items-center overflow-hidden rounded-full text-[13px]"
        style={{ backgroundColor: (opt.color ?? '#94a3b8') + '22', color: opt.color ?? undefined }}
        aria-hidden
      >
        <OptionIcon name={opt.icon} />
      </span>

      <div className="flex min-w-0 flex-1 flex-col">
        <div className="flex flex-wrap items-center gap-2">
          <span className="truncate font-semibold text-text-primary">{taxOptionLabel(opt, locale)}</span>
          {opt.is_default && <Badge tone="info">{c.default}</Badge>}
          {opt.is_system && <Badge tone="neutral">{c.system}</Badge>}
          {!opt.is_active && <Badge tone="warning">{c.inactive}</Badge>}
        </div>
        <span className="text-xs text-text-tertiary" dir="ltr">
          {opt.label_en} · {c.usage}: <span className="tnum">{usage}</span>
        </span>
      </div>

      {perms.canDeactivate && (
        <Switch
          checked={opt.is_active}
          onCheckedChange={onToggleActive}
          aria-label={opt.is_active ? c.deactivate : c.activate}
        />
      )}

      {anyAction && (
        <RowMenu
          opt={opt}
          perms={perms}
          allowChildren={allowChildren}
          onEdit={onEdit}
          onAddChild={onAddChild}
          onSetDefault={onSetDefault}
          onMerge={onMerge}
          onReassign={onReassign}
        />
      )}
    </li>
  )
}

function RowMenu({
  opt, perms, allowChildren, onEdit, onAddChild, onSetDefault, onMerge, onReassign,
}: {
  opt: TaxonomyOption
  perms: Perms
  allowChildren: boolean
  onEdit: () => void
  onAddChild: () => void
  onSetDefault: () => void
  onMerge: () => void
  onReassign: () => void
}) {
  const locale = useUi((s) => s.locale)
  const c = TAX_COPY[locale]
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onDown)
    return () => document.removeEventListener('mousedown', onDown)
  }, [open])

  const item = (label: string, icon: React.ReactNode, onClick: () => void, tone: 'default' | 'danger' = 'default') => (
    <button
      type="button"
      onClick={() => { setOpen(false); onClick() }}
      className={`flex w-full items-center gap-2 px-3 py-1.5 text-start text-sm hover:bg-surface-hover ${
        tone === 'danger' ? 'text-danger' : 'text-text-secondary hover:text-text-primary'
      }`}
    >
      {icon} {label}
    </button>
  )

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        aria-label={c.actions}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
        className="rounded-lg p-1.5 text-text-muted hover:bg-surface-hover hover:text-text-primary"
      >
        <MoreVertical size={16} />
      </button>
      {open && (
        <div
          role="menu"
          className="absolute end-0 z-20 mt-1 flex w-52 flex-col overflow-hidden rounded-xl border border-border bg-surface py-1 shadow-[var(--shadow-large)]"
        >
          {perms.canEdit && item(c.edit, <Pencil size={14} />, onEdit)}
          {perms.canEdit && !opt.is_default && opt.is_active && item(c.setDefault, <Star size={14} />, onSetDefault)}
          {allowChildren && item(c.addChild, <Plus size={14} />, onAddChild)}
          {perms.canMerge && item(c.merge, <GitMerge size={14} />, onMerge)}
          {perms.canMerge && item(c.reassign, <Shuffle size={14} />, onReassign)}
          {opt.is_system && (
            <span className="flex items-center gap-2 px-3 py-1.5 text-[11px] text-text-tertiary">
              <Ban size={12} /> {c.systemLockRow}
            </span>
          )}
        </div>
      )}
    </div>
  )
}

/**
 * An option's `icon` stores a lucide icon NAME (e.g. "wallet", "trending-up"). Rendering that string raw
 * overflowed the 28px badge with visible text; resolve it to the real icon and fall back to a neutral dot
 * for unknown/empty names.
 */
function OptionIcon({ name }: { name?: string | null }) {
  if (!name) return <span>●</span>
  const pascal = name.split(/[-_ ]/).filter(Boolean).map((p) => p[0].toUpperCase() + p.slice(1)).join('')
  const Icon = (LucideIcons as unknown as Record<string, unknown>)[pascal]
  // Lucide icons are forwardRef objects, not plain functions.
  const isComponent =
    typeof Icon === 'function' || (typeof Icon === 'object' && Icon !== null && '$$typeof' in (Icon as object))
  if (!isComponent) return <span>●</span>
  const C = Icon as ComponentType<{ size?: number }>
  return <C size={15} />
}
