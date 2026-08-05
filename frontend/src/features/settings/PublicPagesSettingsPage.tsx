import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, Eye, RotateCcw, Save, Upload } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { fmtDateTime } from '@/lib/datetime'
import {
  listPublicPages, publishPublicPage, revertPublicPageDraft, savePublicPageDraft,
  type PageContent, type PageSection, type PublicPageKey, type PublicPageRow,
} from './publicPagesApi'

const COPY = {
  ar: {
    title: 'الواجهة الرئيسية والبوابات',
    subtitle: 'حرّر نصوص وأقسام وأزرار الصفحات العامة وبواباتها — تُحفظ كمسودة، وتظهر للزوار بعد النشر فقط.',
    pages: { home: 'الصفحة الرئيسية', portal_paid: 'بوابة الحملات المدفوعة', portal_influencer: 'بوابة المؤثرين وUGC', portal_tracking: 'بوابة متابعة الطلبات' },
    sections: 'الأقسام', enabled: 'مفعّل', disabled: 'معطّل', order: 'الترتيب',
    fieldTitle: 'العنوان', fieldSubtitle: 'العنوان الفرعي', fieldEyebrow: 'النص العلوي', fieldDesc: 'الوصف', fieldTagline: 'العبارة',
    ctaPrimary: 'الزر الأساسي', ctaSecondary: 'الزر الثانوي', ctaLabel: 'نص الزر', ctaTo: 'الوجهة',
    save: 'حفظ المسودة', saving: 'جارٍ الحفظ…', publish: 'نشر', publishing: 'جارٍ النشر…',
    revert: 'استرجاع المنشور', preview: 'معاينة المسودة',
    unpublished: 'تغييرات غير منشورة', published_v: 'منشور — إصدار', never: 'لم يُنشر بعد',
    loading: 'جارٍ التحميل…', error: 'تعذّر تحميل إعدادات الصفحات.', saved: 'حُفظت المسودة', publishedOk: 'تم النشر — ظهرت للزوار',
    noPerm: 'تحرير الموقع العام يخص مشغّل المنصة وحده.',
    previewTitle: 'معاينة المسودة قبل النشر', close: 'إغلاق', previewNote: 'هذه معاينة للمسودة — لم تظهر للزوار بعد.',
    hidden: 'مخفي من الصفحة العامة',
  },
  en: {
    title: 'Homepage & portals',
    subtitle: 'Edit the public pages’ texts, sections and buttons — saved as a draft, visible to visitors only after publishing.',
    pages: { home: 'Homepage', portal_paid: 'Paid campaigns portal', portal_influencer: 'Influencer & UGC portal', portal_tracking: 'Request tracking portal' },
    sections: 'Sections', enabled: 'Enabled', disabled: 'Disabled', order: 'Order',
    fieldTitle: 'Title', fieldSubtitle: 'Subtitle', fieldEyebrow: 'Eyebrow', fieldDesc: 'Description', fieldTagline: 'Tagline',
    ctaPrimary: 'Primary button', ctaSecondary: 'Secondary button', ctaLabel: 'Label', ctaTo: 'Destination',
    save: 'Save draft', saving: 'Saving…', publish: 'Publish', publishing: 'Publishing…',
    revert: 'Revert to published', preview: 'Preview draft',
    unpublished: 'Unpublished changes', published_v: 'Published — version', never: 'Never published',
    loading: 'Loading…', error: 'Could not load page settings.', saved: 'Draft saved', publishedOk: 'Published — now live for visitors',
    noPerm: 'Editing the public site belongs to the platform operator alone.',
    previewTitle: 'Preview the draft before publishing', close: 'Close', previewNote: 'This is a draft preview — visitors do not see it yet.',
    hidden: 'Hidden from the public page',
  },
}

const isSection = (v: unknown): v is PageSection => typeof v === 'object' && v !== null && !Array.isArray(v)

/** Sections sorted by their `order`, so the editor list matches what the public page renders. */
function orderedSections(content: PageContent): [string, PageSection][] {
  return Object.entries(content)
    .filter((e): e is [string, PageSection] => isSection(e[1]))
    .sort((a, b) => (a[1].order ?? 99) - (b[1].order ?? 99))
}

export function PublicPagesSettingsPage() {
  const locale = useUi((s) => s.locale)
  const c = COPY[locale]
  /*
   * PAGES-001 — platform operators, not holders of a tenant permission.
   *
   * This asked for `settings.manage`, a permission granted through a tenant ROLE. The platform
   * operator holds no membership and therefore no role, so the honest answer to «may this person edit
   * the platform's homepage?» is «are they the platform operator?». `hasPermission` happened to return
   * true for them by short-circuit, which is why the screen rendered and then failed at the request
   * instead of saying plainly who this is for.
   */
  const canManage = useAuth((s) => s.user?.is_platform_admin === true)
  const qc = useQueryClient()

  const [page, setPage] = useState<PublicPageKey>('home')
  const [draft, setDraft] = useState<PageContent | null>(null)
  const [previewing, setPreviewing] = useState(false)

  const q = useQuery({ queryKey: ['settings', 'public-pages'], queryFn: listPublicPages, enabled: canManage })
  const row: PublicPageRow | undefined = useMemo(() => q.data?.find((r) => r.page === page), [q.data, page])

  // Load the server draft into local edit state whenever the selected page (or its server copy) changes.
  useEffect(() => { if (row) setDraft(row.draft) }, [row?.page, row?.version, row?.has_unpublished_changes]) // eslint-disable-line react-hooks/exhaustive-deps

  const invalidate = () => qc.invalidateQueries({ queryKey: ['settings', 'public-pages'] })
  const saveM = useMutation({ mutationFn: () => savePublicPageDraft(page, draft ?? {}), onSuccess: invalidate })
  const publishM = useMutation({ mutationFn: () => publishPublicPage(page), onSuccess: invalidate })
  const revertM = useMutation({ mutationFn: () => revertPublicPageDraft(page), onSuccess: (r) => { setDraft(r.draft); invalidate() } })

  if (!canManage) {
    return <p className="rounded-xl border border-dashed border-border p-10 text-center text-sm text-text-secondary">{c.noPerm}</p>
  }

  const patchSection = (key: string, patch: Partial<PageSection>) =>
    setDraft((d) => (d ? { ...d, [key]: { ...(d[key] as PageSection), ...patch } } : d))

  const moveSection = (key: string, dir: -1 | 1) => {
    if (!draft) return
    const list = orderedSections(draft)
    const i = list.findIndex(([k]) => k === key)
    const j = i + dir
    if (i < 0 || j < 0 || j >= list.length) return
    const next = { ...draft }
    // Swap the two sections' order values — the public page renders by `order`.
    next[key] = { ...(draft[key] as PageSection), order: list[j][1].order ?? j + 1 }
    next[list[j][0]] = { ...(draft[list[j][0]] as PageSection), order: list[i][1].order ?? i + 1 }
    setDraft(next)
  }

  const sections = draft ? orderedSections(draft) : []

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{c.title}</h1>
          <p className="text-sm text-text-secondary">{c.subtitle}</p>
        </div>
      </header>

      {/* Page selector — the four editable public surfaces. */}
      <div className="flex flex-wrap gap-1 border-b border-border">
        {(Object.keys(c.pages) as PublicPageKey[]).map((k) => (
          <button key={k} onClick={() => setPage(k)}
            className={`rounded-t-lg px-3 py-2 text-sm font-semibold transition-colors ${
              page === k ? 'border-b-2 border-brand-600 text-brand-600' : 'text-text-secondary hover:text-text-primary'
            }`}>
            {c.pages[k]}
          </button>
        ))}
      </div>

      {q.isLoading ? (
        <p className="rounded-xl border border-dashed border-border p-10 text-center text-sm text-text-secondary">{c.loading}</p>
      ) : q.isError ? (
        <p className="rounded-xl border border-danger/30 bg-danger/5 p-10 text-center text-sm text-danger">{c.error}</p>
      ) : !draft || !row ? null : (
        <>
          {/* Status + actions */}
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-border bg-surface p-3">
            <div className="flex flex-wrap items-center gap-2 text-xs">
              {row.has_unpublished_changes && (
                <span className="rounded-full bg-warning/15 px-2.5 py-1 font-semibold text-warning">{c.unpublished}</span>
              )}
              <span className="rounded-full bg-surface-hover px-2.5 py-1 font-semibold text-text-secondary">
                {row.is_published ? `${c.published_v} ${row.version} · ${fmtDateTime(row.published_at)}` : c.never}
              </span>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <button onClick={() => setPreviewing(true)}
                className="flex items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm font-semibold text-text-secondary hover:border-brand-500 hover:text-brand-600">
                <Eye size={15} /> {c.preview}
              </button>
              <button onClick={() => revertM.mutate()} disabled={!row.is_published || revertM.isPending}
                className="flex items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm font-semibold text-text-secondary hover:border-danger hover:text-danger disabled:opacity-40">
                <RotateCcw size={15} /> {c.revert}
              </button>
              <button onClick={() => saveM.mutate()} disabled={saveM.isPending}
                className="flex items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm font-semibold text-text-secondary hover:border-brand-500 hover:text-brand-600 disabled:opacity-50">
                <Save size={15} /> {saveM.isPending ? c.saving : c.save}
              </button>
              <button onClick={() => publishM.mutate()} disabled={publishM.isPending}
                className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50">
                <Upload size={15} /> {publishM.isPending ? c.publishing : c.publish}
              </button>
            </div>
          </div>
          {saveM.isSuccess && <span className="text-xs font-semibold text-success">{c.saved}</span>}
          {publishM.isSuccess && <span className="text-xs font-semibold text-success">{c.publishedOk}</span>}

          {/* Section editors */}
          <div className="flex flex-col gap-3">
            <h2 className="text-sm font-bold text-text-primary">{c.sections}</h2>
            {sections.map(([key, s], i) => (
              <section key={key} className={`flex flex-col gap-3 rounded-2xl border border-border bg-surface p-4 ${s.enabled === false ? 'opacity-70' : ''}`}>
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <span className="font-mono text-xs font-semibold text-brand-600" dir="ltr">{key}</span>
                    {s.enabled === false && (
                      <span className="rounded-full bg-surface-hover px-2 py-0.5 text-[11px] font-semibold text-text-tertiary">{c.hidden}</span>
                    )}
                  </div>
                  <div className="flex items-center gap-1.5">
                    <button aria-label={`${c.order} ↑`} onClick={() => moveSection(key, -1)} disabled={i === 0}
                      className="rounded-lg border border-border p-1.5 text-text-secondary hover:text-text-primary disabled:opacity-30"><ArrowUp size={13} /></button>
                    <button aria-label={`${c.order} ↓`} onClick={() => moveSection(key, 1)} disabled={i === sections.length - 1}
                      className="rounded-lg border border-border p-1.5 text-text-secondary hover:text-text-primary disabled:opacity-30"><ArrowDown size={13} /></button>
                    <label className="ms-1 flex items-center gap-1.5 text-xs font-semibold text-text-secondary">
                      <input type="checkbox" checked={s.enabled !== false} onChange={(e) => patchSection(key, { enabled: e.target.checked })} />
                      {s.enabled !== false ? c.enabled : c.disabled}
                    </label>
                  </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                  {'eyebrow' in s && <Text label={c.fieldEyebrow} value={String(s.eyebrow ?? '')} onChange={(v) => patchSection(key, { eyebrow: v })} />}
                  {'title' in s && <Text label={c.fieldTitle} value={String(s.title ?? '')} onChange={(v) => patchSection(key, { title: v })} />}
                  {'subtitle' in s && <Text label={c.fieldSubtitle} value={String(s.subtitle ?? '')} onChange={(v) => patchSection(key, { subtitle: v })} />}
                  {'tagline' in s && <Text label={c.fieldTagline} value={String(s.tagline ?? '')} onChange={(v) => patchSection(key, { tagline: v })} />}
                </div>
                {'desc' in s && (
                  <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
                    {c.fieldDesc}
                    <textarea rows={2} value={String(s.desc ?? '')} onChange={(e) => patchSection(key, { desc: e.target.value })}
                      className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary" />
                  </label>
                )}

                {(['primary_cta', 'secondary_cta'] as const).map((ctaKey) =>
                  ctaKey in s ? (
                    <fieldset key={ctaKey} className="grid gap-3 rounded-xl border border-border p-3 sm:grid-cols-2">
                      <legend className="px-1 text-xs font-bold text-text-secondary">{ctaKey === 'primary_cta' ? c.ctaPrimary : c.ctaSecondary}</legend>
                      <Text label={c.ctaLabel} value={(s[ctaKey] as { label?: string })?.label ?? ''}
                        onChange={(v) => patchSection(key, { [ctaKey]: { ...(s[ctaKey] as object), label: v } } as Partial<PageSection>)} />
                      <Text label={c.ctaTo} ltr value={(s[ctaKey] as { to?: string })?.to ?? ''}
                        onChange={(v) => patchSection(key, { [ctaKey]: { ...(s[ctaKey] as object), to: v } } as Partial<PageSection>)} />
                    </fieldset>
                  ) : null,
                )}
              </section>
            ))}
          </div>
        </>
      )}

      {previewing && draft && (
        <div className="fixed inset-0 z-40 flex justify-end bg-black/30" onClick={() => setPreviewing(false)}>
          <div className="flex h-full w-full max-w-lg flex-col gap-4 overflow-y-auto bg-surface p-5 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-start justify-between gap-3">
              <h2 className="text-lg font-extrabold text-text-primary">{c.previewTitle}</h2>
              <button onClick={() => setPreviewing(false)} className="rounded-lg px-2 py-1 text-sm text-text-secondary hover:bg-surface-hover">{c.close}</button>
            </div>
            <p className="rounded-xl bg-warning/10 px-3 py-2 text-xs font-semibold text-warning">{c.previewNote}</p>
            <div className="flex flex-col gap-3">
              {orderedSections(draft).filter(([, s]) => s.enabled !== false).map(([key, s]) => (
                <div key={key} className="rounded-xl border border-border p-3">
                  <span className="font-mono text-[10px] text-text-tertiary" dir="ltr">{key}</span>
                  {s.eyebrow ? <div className="text-[11px] font-semibold text-brand-600">{String(s.eyebrow)}</div> : null}
                  {s.title ? <div className="text-base font-bold text-text-primary">{String(s.title)}</div> : null}
                  {s.subtitle ? <div className="text-sm text-text-secondary">{String(s.subtitle)}</div> : null}
                  {s.desc ? <p className="mt-1 text-sm text-text-secondary">{String(s.desc)}</p> : null}
                  {s.tagline ? <div className="text-sm text-text-secondary">{String(s.tagline)}</div> : null}
                  <div className="mt-2 flex flex-wrap gap-2">
                    {(['primary_cta', 'secondary_cta'] as const).map((k) => {
                      const cta = s[k] as { label?: string; to?: string } | undefined
                      return cta?.label ? (
                        <span key={k} className={`rounded-lg px-2.5 py-1 text-xs font-bold ${k === 'primary_cta' ? 'bg-brand-600 text-white' : 'border border-border text-text-secondary'}`}>
                          {cta.label}
                        </span>
                      ) : null
                    })}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

function Text({ label, value, onChange, ltr }: { label: string; value: string; onChange: (v: string) => void; ltr?: boolean }) {
  return (
    <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
      {label}
      <input value={value} onChange={(e) => onChange(e.target.value)} dir={ltr ? 'ltr' : undefined}
        className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary" />
    </label>
  )
}
