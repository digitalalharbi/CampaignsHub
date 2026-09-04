import { useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Image as ImageIcon, Palette, Trash2, Upload } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { toApiError } from '@/lib/api/client'
import {
  BRANDING_KINDS, BRANDING_SCOPES, BRANDING_SPEC, deleteBrandingAsset, getBrandingSettings,
  kindSupportsThemes, listBrandingAssets, saveBrandingSettings, uploadBrandingAsset,
  validateBrandingUpload, type BrandingAsset, type BrandingKind, type BrandingScope,
  type BrandingSettings, type BrandingTheme,
} from './api'

/** Bilingual copy — self-contained to this feature (Arabic-first). */
const COPY = {
  ar: {
    title: 'مركز الهوية', subtitle: 'ارفع أصول العلامة لكل نطاق، وأدِر الألوان والخطوط وإعدادات العلامة البيضاء.',
    tab_assets: 'الأصول', tab_settings: 'الألوان والخطوط',
    scope: 'النطاق', scope_id: 'معرّف النطاق (اختياري)', scope_id_hint: 'UUID للعميل/المشروع/التقرير عند اختيار نطاق مخصّص.',
    required_sizes: 'المقاسات المطلوبة', accepted: 'الصيغ المقبولة', max_size: 'الحد الأقصى', max_2mb: '2 ميجابايت',
    theme_light: 'فاتح', theme_dark: 'داكن', theme_any: 'موحّد',
    upload: 'رفع', uploading: 'جارٍ الرفع…', choose_file: 'اختر ملفًا (SVG أو PNG)', delete: 'حذف',
    no_asset: 'لا يوجد أصل بعد', preview: 'معاينة', dimensions: 'الأبعاد', size: 'الحجم',
    empty_assets: 'لم تُرفع أي أصول لهذا النطاق بعد.', loading: 'جارٍ التحميل…',
    no_permission: 'لا تملك صلاحية عرض مركز الهوية.',
    themed_note: 'هذا النوع يدعم نسختين: فاتحة وداكنة.', single_note: 'أصل واحد موحّد لكل الأسطح.',
    colors: 'الألوان', fonts: 'الخطوط', white_label: 'العلامة البيضاء', white_label_hint: 'إخفاء علامة المنصّة على الأسطح المصدَّرة.',
    color_primary: 'اللون الأساسي', color_accent: 'اللون الثانوي', color_bg: 'الخلفية', color_text: 'النص',
    font_heading: 'خط العناوين', font_body: 'خط المتن', save: 'حفظ', saving: 'جارٍ الحفظ…', saved: 'تم الحفظ',
    manage_note: 'الرفع والحذف والحفظ تتطلّب صلاحية branding.manage.',
  },
  en: {
    title: 'Branding Center', subtitle: 'Upload brand assets per scope, and manage colors, fonts, and white-label settings.',
    tab_assets: 'Assets', tab_settings: 'Colors & fonts',
    scope: 'Scope', scope_id: 'Scope id (optional)', scope_id_hint: 'The client/project/report UUID when a scoped target is used.',
    required_sizes: 'Required sizes', accepted: 'Accepted formats', max_size: 'Max size', max_2mb: '2 MB',
    theme_light: 'Light', theme_dark: 'Dark', theme_any: 'Any',
    upload: 'Upload', uploading: 'Uploading…', choose_file: 'Choose a file (SVG or PNG)', delete: 'Delete',
    no_asset: 'No asset yet', preview: 'Preview', dimensions: 'Dimensions', size: 'Size',
    empty_assets: 'No assets uploaded for this scope yet.', loading: 'Loading…',
    no_permission: 'You do not have permission to view the Branding Center.',
    themed_note: 'This kind supports a light + dark pair.', single_note: 'A single theme-agnostic asset for all surfaces.',
    colors: 'Colors', fonts: 'Fonts', white_label: 'White-label', white_label_hint: 'Hide the platform mark on exported surfaces.',
    color_primary: 'Primary', color_accent: 'Accent', color_bg: 'Background', color_text: 'Text',
    font_heading: 'Heading font', font_body: 'Body font', save: 'Save', saving: 'Saving…', saved: 'Saved',
    manage_note: 'Upload, delete, and save require the branding.manage permission.',
  },
}
type Copy = (typeof COPY)['ar']

const KIND_LABEL: Record<BrandingKind, { ar: string; en: string }> = {
  primary_horizontal: { ar: 'الشعار الأفقي الأساسي', en: 'Primary horizontal logo' },
  report_logo: { ar: 'شعار التقارير', en: 'Report logo' },
  square_icon: { ar: 'الأيقونة المربّعة', en: 'Square app icon' },
  favicon: { ar: 'أيقونة المتصفّح', en: 'Favicon' },
  email_header: { ar: 'ترويسة البريد', en: 'Email header' },
  client_logo: { ar: 'شعار العميل', en: 'Client logo' },
}

const SCOPE_LABEL: Record<BrandingScope, { ar: string; en: string }> = {
  platform: { ar: 'المنصّة', en: 'Platform' },
  tenant: { ar: 'المستأجر', en: 'Tenant' },
  client: { ar: 'العميل', en: 'Client' },
  project: { ar: 'المشروع', en: 'Project' },
  report: { ar: 'التقرير', en: 'Report' },
  portal: { ar: 'البوابة', en: 'Portal' },
  email: { ar: 'البريد', en: 'Email' },
}

type Tab = 'assets' | 'settings'

export function BrandingCenterPage() {
  const locale = useUi((s) => s.locale)
  const c = COPY[locale]
  const canView = useAuth((s) => s.hasPermission('branding.view'))
  const canManage = useAuth((s) => s.hasPermission('branding.manage'))
  const [tab, setTab] = useState<Tab>('assets')
  const [scope, setScope] = useState<BrandingScope>('tenant')
  const [scopeId, setScopeId] = useState('')

  if (!canView) {
    return (
      <div className="mx-auto w-full max-w-5xl p-4 md:p-6">
        <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{c.no_permission}</p>
      </div>
    )
  }

  const tabs: { id: Tab; label: string; icon: typeof ImageIcon }[] = [
    { id: 'assets', label: c.tab_assets, icon: ImageIcon },
    { id: 'settings', label: c.tab_settings, icon: Palette },
  ]

  return (
    <div className="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 md:p-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{c.title}</h1>
        <p className="text-sm text-text-secondary">{c.subtitle}</p>
      </header>

      <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-4 sm:flex-row sm:items-end">
        <label className="flex flex-1 flex-col gap-1 text-xs font-semibold text-text-secondary">
          {c.scope}
          <select value={scope} onChange={(e) => setScope(e.target.value as BrandingScope)}
            className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary">
            {BRANDING_SCOPES.map((s) => <option key={s} value={s}>{SCOPE_LABEL[s][locale]}</option>)}
          </select>
        </label>
        <label className="flex flex-[2] flex-col gap-1 text-xs font-semibold text-text-secondary">
          {c.scope_id}
          <input value={scopeId} onChange={(e) => setScopeId(e.target.value)} placeholder="—"
            className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary" />
          <span className="text-[11px] font-normal text-text-muted">{c.scope_id_hint}</span>
        </label>
      </div>

      <div className="flex flex-wrap gap-1 border-b border-border">
        {tabs.map((tb) => (
          <button
            key={tb.id}
            onClick={() => setTab(tb.id)}
            className={`flex items-center gap-2 rounded-t-lg px-3 py-2 text-sm font-semibold transition-colors ${
              tab === tb.id ? 'border-b-2 border-brand-600 text-brand-600' : 'text-text-secondary hover:text-text-primary'
            }`}
          >
            <tb.icon size={16} /> {tb.label}
          </button>
        ))}
      </div>

      {!canManage && (
        <p className="flex items-center gap-2 rounded-lg bg-surface-hover px-3 py-2 text-xs text-text-secondary">
          <AlertTriangle size={14} className="text-warning" /> {c.manage_note}
        </p>
      )}

      {tab === 'assets'
        ? <AssetsTab c={c} locale={locale} scope={scope} scopeId={scopeId.trim() || null} canManage={canManage} />
        : <SettingsTab c={c} scope={scope} scopeId={scopeId.trim() || null} canManage={canManage} />}
    </div>
  )
}

function AssetsTab({ c, locale, scope, scopeId, canManage }: {
  c: Copy; locale: 'ar' | 'en'; scope: BrandingScope; scopeId: string | null; canManage: boolean
}) {
  const q = useQuery({
    queryKey: ['branding-assets', scope, scopeId],
    queryFn: () => listBrandingAssets({ scope, scopeId }),
  })
  const assets = q.data ?? []
  const byKey = useMemo(() => {
    const map = new Map<string, BrandingAsset>()
    for (const a of assets) map.set(`${a.kind}:${a.theme}`, a)
    return map
  }, [assets])

  if (q.isLoading) return <p className="p-8 text-center text-sm text-text-secondary">{c.loading}</p>

  return (
    <div className="flex flex-col gap-4">
      {assets.length === 0 && (
        <p className="rounded-xl border border-dashed border-border p-6 text-center text-sm text-text-secondary">{c.empty_assets}</p>
      )}
      <div className="grid gap-4 md:grid-cols-2">
        {BRANDING_KINDS.map((kind) => (
          <KindCard key={kind} c={c} locale={locale} kind={kind} scope={scope} scopeId={scopeId} canManage={canManage} byKey={byKey} />
        ))}
      </div>
    </div>
  )
}

function KindCard({ c, locale, kind, scope, scopeId, canManage, byKey }: {
  c: Copy; locale: 'ar' | 'en'; kind: BrandingKind; scope: BrandingScope; scopeId: string | null
  canManage: boolean; byKey: Map<string, BrandingAsset>
}) {
  const spec = BRANDING_SPEC[kind]
  const themes: BrandingTheme[] = kindSupportsThemes(kind) ? ['light', 'dark'] : ['any']
  const themeLabel = (t: BrandingTheme) => (t === 'light' ? c.theme_light : t === 'dark' ? c.theme_dark : c.theme_any)

  return (
    <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-4">
      <div className="flex flex-col gap-1">
        <h3 className="text-sm font-bold text-text-primary">{KIND_LABEL[kind][locale]}</h3>
        <div className="flex flex-wrap gap-1.5 text-[11px] text-text-secondary">
          <span className="rounded-md bg-surface-hover px-1.5 py-0.5 tnum">
            {c.required_sizes}: {spec.sizes.map((s) => `${s.w}×${s.h}`).join('، ')}
          </span>
          <span className="rounded-md bg-surface-hover px-1.5 py-0.5">
            {c.accepted}: {Object.entries(spec.formats).map(([f, role]) => `${f.toUpperCase()} (${role})`).join('، ')}
          </span>
          <span className="rounded-md bg-surface-hover px-1.5 py-0.5">{c.max_size}: {c.max_2mb}</span>
        </div>
        <span className="text-[11px] text-text-muted">{spec.themed ? c.themed_note : c.single_note}</span>
      </div>
      <div className={`grid gap-3 ${themes.length > 1 ? 'grid-cols-2' : 'grid-cols-1'}`}>
        {themes.map((theme) => (
          <ThemeSlot
            key={theme} c={c} kind={kind} theme={theme} themeLabel={themeLabel(theme)}
            scope={scope} scopeId={scopeId} canManage={canManage} asset={byKey.get(`${kind}:${theme}`) ?? null}
          />
        ))}
      </div>
    </div>
  )
}

function ThemeSlot({ c, kind, theme, themeLabel, scope, scopeId, canManage, asset }: {
  c: Copy; kind: BrandingKind; theme: BrandingTheme; themeLabel: string
  scope: BrandingScope; scopeId: string | null; canManage: boolean; asset: BrandingAsset | null
}) {
  const qc = useQueryClient()
  const fileRef = useRef<HTMLInputElement>(null)
  const [error, setError] = useState<string | null>(null)
  const invalidate = () => qc.invalidateQueries({ queryKey: ['branding-assets'] })

  const uploadM = useMutation({
    mutationFn: (file: File) => uploadBrandingAsset({ scope, scopeId, kind, theme, file }),
    onSuccess: () => { setError(null); invalidate() },
    onError: (e) => setError(toApiError(e).message),
  })
  const deleteM = useMutation({ mutationFn: (id: string) => deleteBrandingAsset(id), onSuccess: invalidate })

  const onPick = (file: File | undefined) => {
    if (!file) return
    const verdict = validateBrandingUpload(kind, file.type, file.size)
    if (!verdict.ok) { setError(verdict.error ?? 'Invalid file.'); return }
    setError(null)
    uploadM.mutate(file)
  }

  return (
    <div className="flex flex-col gap-2 rounded-xl border border-border bg-background p-2.5">
      <span className="text-[11px] font-semibold text-text-secondary">{themeLabel}</span>
      <div className={`flex aspect-video items-center justify-center overflow-hidden rounded-lg border border-dashed border-border ${theme === 'dark' ? 'bg-[#111]' : 'bg-white'}`}>
        {asset
          ? <img src={asset.url} alt={themeLabel} className="max-h-full max-w-full object-contain p-2" />
          : <span className="text-[11px] text-text-muted">{c.no_asset}</span>}
      </div>
      {asset && (
        <span className="text-[10px] text-text-muted tnum">
          {asset.mime.replace('image/', '').toUpperCase()}
          {asset.width && asset.height ? ` · ${asset.width}×${asset.height}` : ''}
          {asset.bytes ? ` · ${fmtBytes(asset.bytes)}` : ''}
        </span>
      )}
      {canManage && (
        <div className="flex items-center gap-1.5">
          <input
            ref={fileRef} type="file" accept="image/png,image/svg+xml" className="hidden"
            onChange={(e) => { onPick(e.target.files?.[0]); e.target.value = '' }}
          />
          <button
            onClick={() => fileRef.current?.click()} disabled={uploadM.isPending}
            className="flex flex-1 items-center justify-center gap-1 rounded-lg border border-border px-2 py-1 text-[11px] font-semibold text-text-secondary hover:border-brand-500 hover:text-brand-600 disabled:opacity-50"
          >
            <Upload size={12} /> {uploadM.isPending ? c.uploading : c.upload}
          </button>
          {asset && (
            <button
              onClick={() => deleteM.mutate(asset.id)} disabled={deleteM.isPending}
              aria-label={c.delete}
              className="flex items-center justify-center rounded-lg border border-border px-2 py-1 text-text-secondary hover:border-danger hover:text-danger disabled:opacity-50"
            >
              <Trash2 size={12} />
            </button>
          )}
        </div>
      )}
      {error && <span className="text-[10px] font-semibold text-danger">{error}</span>}
    </div>
  )
}

const COLOR_KEYS: { key: string; label: keyof Copy }[] = [
  { key: 'primary', label: 'color_primary' }, { key: 'accent', label: 'color_accent' },
  { key: 'background', label: 'color_bg' }, { key: 'text', label: 'color_text' },
]
const FONT_KEYS: { key: string; label: keyof Copy }[] = [
  { key: 'heading', label: 'font_heading' }, { key: 'body', label: 'font_body' },
]

function SettingsTab({ c, scope, scopeId, canManage }: {
  c: Copy; scope: BrandingScope; scopeId: string | null; canManage: boolean
}) {
  const qc = useQueryClient()
  const q = useQuery({
    queryKey: ['branding-settings', scope, scopeId],
    queryFn: () => getBrandingSettings({ scope, scopeId }),
  })
  const saveM = useMutation({
    mutationFn: (patch: Partial<BrandingSettings>) => saveBrandingSettings({
      scope, scopeId,
      colors: patch.colors ?? q.data?.colors ?? null,
      fonts: patch.fonts ?? q.data?.fonts ?? null,
      white_label: patch.white_label ?? q.data?.white_label ?? false,
    }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['branding-settings'] }),
  })

  if (q.isLoading || !q.data) return <p className="p-8 text-center text-sm text-text-secondary">{c.loading}</p>
  const s = q.data
  const colors = s.colors ?? {}
  const fonts = s.fonts ?? {}

  return (
    <form
      onSubmit={(e) => { e.preventDefault(); if (canManage) saveM.mutate({}) }}
      className="flex max-w-2xl flex-col gap-5 rounded-2xl border border-border bg-surface p-5"
    >
      <div className="flex flex-col gap-3">
        <h3 className="text-sm font-bold text-text-primary">{c.colors}</h3>
        <div className="grid gap-3 sm:grid-cols-2">
          {COLOR_KEYS.map(({ key, label }) => (
            <label key={key} className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
              {c[label]}
              <div className="flex items-center gap-2">
                <input
                  type="color" disabled={!canManage} value={colors[key] ?? '#000000'}
                  onChange={(e) => saveM.mutate({ colors: { ...colors, [key]: e.target.value } })}
                  className="h-8 w-10 rounded border border-border bg-background disabled:opacity-50"
                />
                <input
                  type="text" disabled={!canManage} value={colors[key] ?? ''} placeholder="#000000"
                  onChange={(e) => saveM.mutate({ colors: { ...colors, [key]: e.target.value } })}
                  className="w-28 rounded-lg border border-border bg-background px-2 py-1 text-sm text-text-primary tnum disabled:opacity-50"
                />
              </div>
            </label>
          ))}
        </div>
      </div>

      <div className="flex flex-col gap-3 border-t border-border pt-4">
        <h3 className="text-sm font-bold text-text-primary">{c.fonts}</h3>
        <div className="grid gap-3 sm:grid-cols-2">
          {FONT_KEYS.map(({ key, label }) => (
            <label key={key} className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
              {c[label]}
              <input
                type="text" disabled={!canManage} value={fonts[key] ?? ''} placeholder="Inter"
                onChange={(e) => saveM.mutate({ fonts: { ...fonts, [key]: e.target.value } })}
                className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary disabled:opacity-50"
              />
            </label>
          ))}
        </div>
      </div>

      <div className="flex flex-col gap-1.5 border-t border-border pt-4">
        <label className="flex items-center gap-2 text-sm font-semibold text-text-primary">
          <input
            type="checkbox" disabled={!canManage} checked={s.white_label}
            onChange={(e) => saveM.mutate({ white_label: e.target.checked })}
          />
          {c.white_label}
        </label>
        <span className="text-[11px] text-text-muted">{c.white_label_hint}</span>
      </div>

      {saveM.isSuccess && <span className="text-xs font-semibold text-success">{c.saved}</span>}
    </form>
  )
}

function fmtBytes(n: number): string {
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${Math.round(n / 102.4) / 10} KB`
  return `${Math.round(n / (1024 * 104.8576)) / 10} MB`
}
