import { useMemo, useState } from 'react'
import { Copy, Lock, Pencil, Plus, Trash2 } from 'lucide-react'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Alert } from '@/components/ui/Alert'
import { Switch } from '@/components/ui/Switch'
import { toApiError } from '@/lib/api/client'
import { levelsOf, useAccessActions, type AccessData, type Category, type Level } from './accessApi'
import { AreaChips, AreaToggles, ChipPicker } from './AreaControls'

type Editing = { mode: 'new'; from: string } | { mode: 'edit'; cat: Category }

/**
 * ACCESS-CENTER-001 — «فئات الدعوات»: the ready-made categories and the owner's own.
 *
 * Every card says, in chips, which areas the category opens. Only the owner creates, renames, re-scopes or
 * deletes; a built-in category is duplicated to make a variant, and a category in use cannot be deleted.
 */
export function CategoriesPanel({ data, ar }: { data: AccessData; ar: boolean }) {
  const { deleteCategory } = useAccessActions()
  const [editing, setEditing] = useState<Editing | null>(null)
  const [err, setErr] = useState('')
  const owner = data.me.is_owner

  return (
    <section data-testid="categories-panel" className="rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-base font-bold text-text-primary">{ar ? 'فئات الدعوات' : 'Invitation categories'} <span className="tnum text-sm font-normal text-text-muted">{data.categories.length.toLocaleString('en-US')}</span></h2>
        {owner && <Button size="sm" onClick={() => setEditing({ mode: 'new', from: '' })} data-testid="category-new"><Plus size={14} aria-hidden /> {ar ? 'فئة جديدة' : 'New category'}</Button>}
      </div>
      {err && <div className="mb-2"><Alert severity="danger" title={err} /></div>}
      <ul className="grid gap-2 md:grid-cols-2">
        {data.categories.map((c) => (
          <li key={c.slug} data-testid={`category-${c.slug}`} className="min-w-0 rounded-xl border border-border p-3">
            <div className="mb-2 flex items-center justify-between gap-2">
              <div className="flex min-w-0 items-center gap-1.5">
                {c.kind === 'owner' && <Lock size={13} className="shrink-0 text-text-muted" aria-hidden />}
                <span className="truncate text-sm font-bold text-text-primary">{ar ? c.name_ar : c.name_en}</span>
                <span className="shrink-0 rounded-full bg-surface-secondary px-1.5 text-xs text-text-muted">{c.kind === 'custom' ? (ar ? 'خاصة' : 'Custom') : (ar ? 'جاهزة' : 'Built-in')}</span>
              </div>
              <span className="tnum shrink-0 text-xs text-text-muted">{c.members.toLocaleString('en-US')} {ar ? 'عضو' : 'members'}{c.pending > 0 && ` · ${c.pending.toLocaleString('en-US')} ${ar ? 'دعوة' : 'invites'}`}</span>
            </div>
            {/* Compact: only the areas the category opens; the count says how many of the ten. */}
            <AreaChips areas={data.areas.filter((a) => c.areas[a.key]?.level !== 'none')} total={data.areas.length} levels={levelsOf(c.areas)} ar={ar} />
            <div className="mt-1 text-xs text-text-muted">{c.all_clients ? (ar ? 'كل العملاء' : 'Every client') : (ar ? 'العملاء المختارون فقط' : 'Chosen clients only')}</div>
            {owner && c.kind !== 'owner' && (
              <div className="mt-2 flex gap-3 text-xs font-semibold">
                <button type="button" className="inline-flex items-center gap-1 text-text-secondary hover:text-text-primary" onClick={() => setEditing({ mode: 'new', from: c.slug })} data-testid={`category-duplicate-${c.slug}`}><Copy size={12} aria-hidden /> {ar ? 'نسخ' : 'Duplicate'}</button>
                {c.kind === 'custom' && (
                  <>
                    <button type="button" className="inline-flex items-center gap-1 text-text-secondary hover:text-text-primary" onClick={() => setEditing({ mode: 'edit', cat: c })} data-testid={`category-edit-${c.slug}`}><Pencil size={12} aria-hidden /> {ar ? 'تعديل' : 'Edit'}</button>
                    <button type="button" disabled={c.members + c.pending > 0} title={c.members + c.pending > 0 ? (ar ? 'مستخدمة' : 'In use') : undefined}
                      className="inline-flex items-center gap-1 text-danger hover:underline disabled:cursor-not-allowed disabled:opacity-40"
                      onClick={() => deleteCategory.mutateAsync(c.slug).catch((e) => setErr(toApiError(e).message))} data-testid={`category-delete-${c.slug}`}><Trash2 size={12} aria-hidden /> {ar ? 'حذف' : 'Delete'}</button>
                  </>
                )}
              </div>
            )}
          </li>
        ))}
      </ul>
      {editing && <CategoryEditor editing={editing} data={data} ar={ar} onClose={() => setEditing(null)} />}
    </section>
  )
}

function CategoryEditor({ editing, data, ar, onClose }: { editing: Editing; data: AccessData; ar: boolean; onClose: () => void }) {
  const { createCategory, updateCategory } = useAccessActions()
  const startCat = editing.mode === 'edit' ? editing.cat : data.categories.find((c) => c.slug === editing.from)
  const [from, setFrom] = useState(editing.mode === 'new' ? editing.from : '')
  const fromCat = editing.mode === 'edit' ? editing.cat : data.categories.find((c) => c.slug === from)
  const base = useMemo(() => levelsOf(fromCat?.areas), [fromCat])
  const [levels, setLevels] = useState<Record<string, Level>>(() => levelsOf(startCat?.areas))
  const [name, setName] = useState(editing.mode === 'edit' ? editing.cat.name_ar : '')
  const [allClients, setAllClients] = useState(startCat?.all_clients ?? false)
  const [err, setErr] = useState('')

  const chosen = { ...Object.fromEntries(data.areas.map((a) => [a.key, 'none' as Level])), ...levels }

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setErr('')
    try {
      if (editing.mode === 'new') await createCategory.mutateAsync({ name, ...(from ? { from } : {}), areas: chosen, all_clients: allClients })
      else await updateCategory.mutateAsync({ slug: editing.cat.slug, name, areas: chosen, all_clients: allClients })
      onClose()
    } catch (e2) { setErr(toApiError(e2).message) }
  }

  return (
    <Modal open onClose={onClose} title={editing.mode === 'new' ? (ar ? 'فئة جديدة' : 'New category') : (ar ? 'تعديل الفئة' : 'Edit category')} size="lg">
      <form onSubmit={submit} className="space-y-3" data-testid="category-editor">
        {err && <Alert severity="danger" title={err} />}
        <label className="block text-sm font-bold text-text-primary">
          {ar ? 'الاسم' : 'Name'}
          <Input value={name} onChange={(e) => setName(e.target.value)} required minLength={2} maxLength={60} data-testid="category-name" className="mt-1" />
        </label>
        {editing.mode === 'new' && (
          <div>
            <div className="mb-1 text-sm font-bold text-text-primary">{ar ? 'ابدأ من' : 'Start from'}</div>
            <ChipPicker testId="category-from" label={ar ? 'ابدأ من' : 'Start from'} value={from}
              onChange={(s) => { setFrom(s); const c = data.categories.find((x) => x.slug === s); setLevels(levelsOf(c?.areas)); setAllClients(c?.all_clients ?? false) }}
              options={data.categories.filter((c) => c.kind !== 'owner').map((c) => ({ value: c.slug, label: ar ? c.name_ar : c.name_en }))} />
          </div>
        )}
        <AreaToggles areas={data.areas} levels={chosen} base={base} grantable={data.me.grantable} ar={ar} onChange={(area, lv) => setLevels((l) => ({ ...l, [area]: lv }))} />
        <Switch checked={allClients} onCheckedChange={setAllClients} disabled={!data.me.all_clients} label={ar ? 'كل العملاء' : 'Every client'} testId="category-all-clients" />
        <AreaChips areas={data.areas} levels={chosen} ar={ar} testId="category-preview" />
        <div className="flex justify-end"><Button type="submit" data-testid="category-save">{ar ? 'حفظ' : 'Save'}</Button></div>
      </form>
    </Modal>
  )
}
