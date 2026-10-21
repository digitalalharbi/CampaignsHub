import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { LayoutList } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { createBioPage, updateBioPage, type BioButton, type ShortLink } from './api'

/**
 * BIO-PAGE-001 — a link-in-bio page built from the links already in the library.
 *
 * Nothing new is minted for a button: each is one of the workspace's own short links, so a tap on
 * the page is a follow of that link, counted where it always was. The page itself is a short link of
 * kind `bio` — the same hop, the same «copy this address» — which is why it lives on this screen
 * rather than on one of its own.
 */
const COPY = {
  ar: {
    title: 'صفحة الروابط (البايو)',
    purpose: 'صفحة واحدة تضعها في البايو، أزرارها روابطك المختصرة نفسها — كل نقرة تُحتسب على رابطها.',
    page_title: 'عنوان الصفحة', subtitle: 'سطر تحت العنوان (اختياري)', buttons: 'الأزرار', label: 'نص الزر (اختياري)',
    slug: 'الاسم المختصر (اختياري)', create: 'إنشاء الصفحة', save: 'حفظ', cancel: 'إلغاء', new_page: 'صفحة جديدة', edit: 'تعديل',
    none: 'لا صفحات بعد.', no_links: 'أنشئ رابطًا مختصرًا أولًا — أزرار الصفحة تُختار من روابطك.', pick: 'اختر رابطًا واحدًا على الأقل.',
  },
  en: {
    title: 'Bio page',
    purpose: 'One page for your bio whose buttons are your own short links — every tap is counted on its link.',
    page_title: 'Page title', subtitle: 'Line under the title (optional)', buttons: 'Buttons', label: 'Button text (optional)',
    slug: 'Custom slug (optional)', create: 'Create the page', save: 'Save', cancel: 'Cancel', new_page: 'New page', edit: 'Edit',
    none: 'No pages yet.', no_links: 'Create a short link first — the page’s buttons are chosen from your links.', pick: 'Choose at least one link.',
  },
}

export function BioPageEditor({ links, ar }: { links: ShortLink[]; ar: boolean }) {
  const t = ar ? COPY.ar : COPY.en
  const client = useQueryClient()
  const pages = links.filter((l) => l.kind === 'bio')
  const candidates = links.filter((l) => l.kind !== 'bio' && l.is_active)
  const [editing, setEditing] = useState<ShortLink | 'new' | null>(null)
  const [title, setTitle] = useState('')
  const [subtitle, setSubtitle] = useState('')
  const [slug, setSlug] = useState('')
  const [buttons, setButtons] = useState<BioButton[]>([])
  const [error, setError] = useState<string | null>(null)

  const open = (page: ShortLink | 'new') => {
    setEditing(page)
    setError(null)
    setTitle(page === 'new' ? '' : page.bio?.title ?? '')
    setSubtitle(page === 'new' ? '' : page.bio?.subtitle ?? '')
    setSlug('')
    setButtons(page === 'new' ? [] : page.bio?.buttons ?? [])
  }
  const toggle = (id: string) => setButtons((b) => (b.some((x) => x.short_link_id === id) ? b.filter((x) => x.short_link_id !== id) : [...b, { short_link_id: id }]))
  const relabel = (id: string, label: string) => setButtons((b) => b.map((x) => (x.short_link_id === id ? { ...x, label } : x)))

  const save = useMutation({
    mutationFn: () => {
      const body = { title: title.trim(), subtitle: subtitle.trim() || null, buttons }
      return editing === 'new' || editing === null
        ? createBioPage({ ...body, ...(slug.trim() ? { slug: slug.trim().toLowerCase() } : {}) })
        : updateBioPage(editing.id, body)
    },
    onSuccess: () => { setEditing(null); void client.invalidateQueries({ queryKey: ['short-links'] }) },
    onError: (e: { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }) => {
      const errors = e.response?.data?.errors
      setError(errors?.buttons?.[0] ?? errors?.slug?.[0] ?? errors?.title?.[0] ?? e.response?.data?.message ?? null)
    },
  })

  return (
    <Card>
      <div className="flex flex-col gap-3" data-testid="bio-editor">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 className="flex items-center gap-1.5 text-sm font-bold text-text-primary"><LayoutList size={15} aria-hidden /> {t.title}</h2>
            <p className="text-xs text-text-muted">{t.purpose}</p>
          </div>
          {editing === null && <Button variant="secondary" onClick={() => open('new')} disabled={candidates.length === 0} data-testid="bio-new">{t.new_page}</Button>}
        </div>

        {candidates.length === 0 && editing === null && <p className="text-xs text-text-muted" data-testid="bio-no-links">{t.no_links}</p>}

        {editing !== null && (
          <div className="flex flex-col gap-3 rounded-xl border border-border p-3" data-testid="bio-form">
            <Field label={t.page_title} htmlFor="bio-title" required error={error ?? undefined}>
              <input id="bio-title" value={title} maxLength={80} onChange={(e) => setTitle(e.target.value)} data-testid="bio-title" className="w-full rounded-xl border border-border bg-surface p-2.5 text-sm" />
            </Field>
            <Field label={t.subtitle} htmlFor="bio-subtitle">
              <input id="bio-subtitle" value={subtitle} maxLength={160} onChange={(e) => setSubtitle(e.target.value)} className="w-full rounded-xl border border-border bg-surface p-2.5 text-sm" />
            </Field>
            {editing === 'new' && (
              <Field label={t.slug} htmlFor="bio-slug">
                <input id="bio-slug" dir="ltr" value={slug} maxLength={32} onChange={(e) => setSlug(e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))} placeholder="my-links" data-testid="bio-slug" className="w-full rounded-xl border border-border bg-surface p-2.5 text-sm" />
              </Field>
            )}
            <fieldset className="flex flex-col gap-2">
              <legend className="text-xs font-bold text-text-secondary">{t.buttons}</legend>
              {candidates.map((l) => {
                const chosen = buttons.find((b) => b.short_link_id === l.id)
                return (
                  <div key={l.id} className="flex flex-wrap items-center gap-2 rounded-lg border border-border p-2">
                    <label className="flex min-w-0 flex-1 items-center gap-2 text-sm">
                      <input type="checkbox" checked={!!chosen} onChange={() => toggle(l.id)} data-testid={`bio-pick-${l.id}`} />
                      <code dir="ltr" className="truncate text-xs">{l.short_url}</code>
                      <span className="truncate text-xs text-text-muted">{l.shows}</span>
                    </label>
                    {chosen && <input value={chosen.label ?? ''} maxLength={60} placeholder={t.label} onChange={(e) => relabel(l.id, e.target.value)} data-testid={`bio-label-${l.id}`} className="w-40 rounded-lg border border-border bg-surface p-1.5 text-xs" />}
                  </div>
                )
              })}
            </fieldset>
            <div className="flex gap-2">
              <Button onClick={() => { if (buttons.length === 0) { setError(t.pick); return } save.mutate() }} disabled={save.isPending || title.trim() === ''} data-testid="bio-save">{editing === 'new' ? t.create : t.save}</Button>
              <Button variant="secondary" onClick={() => setEditing(null)}>{t.cancel}</Button>
            </div>
          </div>
        )}

        {pages.length === 0 && candidates.length > 0 && editing === null && <p className="text-xs text-text-muted">{t.none}</p>}
        {pages.map((p) => (
          <div key={p.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border p-3" data-testid={`bio-page-${p.slug}`}>
            <div className="min-w-0">
              <span className="block truncate text-sm font-bold text-text-primary">{p.bio?.title ?? p.shows}</span>
              <code dir="ltr" className="block truncate text-xs text-text-muted">{p.short_url}</code>
            </div>
            <div className="flex items-center gap-2 text-xs text-text-secondary">
              <span className="tnum">{p.bio?.buttons.length ?? 0} ⟶ {p.clicks}</span>
              <Button variant="secondary" onClick={() => open(p)} data-testid={`bio-edit-${p.id}`}>{t.edit}</Button>
            </div>
          </div>
        ))}
      </div>
    </Card>
  )
}
