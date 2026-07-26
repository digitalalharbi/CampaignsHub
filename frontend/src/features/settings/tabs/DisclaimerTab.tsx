import { useEffect, useMemo, useState } from 'react'
import { RotateCcw } from 'lucide-react'
import { useDisclaimerSettings, useSaveDisclaimer } from '../api'
import { PerformanceNotice } from '@/features/disclaimers/PerformanceNotice'
import type { ResolvedDisclaimer } from '@/features/disclaimers/api'
import { Field } from '@/components/ui/Field'
import { Textarea } from '@/components/ui/Textarea'
import { Button } from '@/components/ui/Button'
import { Alert } from '@/components/ui/Alert'
import { Skeleton } from '@/components/ui/States'

type Section = 'short' | 'full' | 'freshness' | 'methodology'
const SECTION_LABELS: Record<Section, string> = { short: 'المختصر', full: 'الكامل', freshness: 'تحديث البيانات', methodology: 'المنهجية' }
const SECTIONS: Section[] = ['short', 'full', 'freshness', 'methodology']

/** Organization-level disclaimer editor: edit bilingual sections, preview, and restore defaults. */
export function DisclaimerTab() {
  const { data, isLoading } = useDisclaimerSettings()
  const save = useSaveDisclaimer()
  const [draft, setDraft] = useState<Record<Section, { ar: string; en: string }>>()
  const [saved, setSaved] = useState(false)

  const orgOverride = data?.overrides.find((o) => o.scope === 'organization')

  useEffect(() => {
    if (!data) return
    const seed = {} as Record<Section, { ar: string; en: string }>
    for (const s of SECTIONS) {
      const ov = (orgOverride?.payload?.sections as ResolvedDisclaimer['sections'] | undefined)?.[s]
      const def = data.defaults.sections[s]
      seed[s] = { ar: ov?.ar ?? def?.ar ?? '', en: ov?.en ?? def?.en ?? '' }
    }
    setDraft(seed)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data])

  // Live preview built from the current draft (falls back to defaults for untouched pieces).
  const preview = useMemo<ResolvedDisclaimer | null>(() => {
    if (!data || !draft) return null
    const sections: ResolvedDisclaimer['sections'] = { ...data.defaults.sections }
    for (const s of SECTIONS) sections[s] = { ar: draft[s].ar, en: draft[s].en }
    return { ...data.defaults, sections }
  }, [data, draft])

  if (isLoading || !draft || !data) return <div className="space-y-3"><Skeleton className="h-10" /><Skeleton className="h-72" /></div>

  const setText = (s: Section, locale: 'ar' | 'en', v: string) => setDraft((p) => (p ? { ...p, [s]: { ...p[s], [locale]: v } } : p))

  const submit = async () => {
    setSaved(false)
    const sections: Record<string, { ar: string; en: string }> = {}
    for (const s of SECTIONS) sections[s] = draft[s]
    await save.mutateAsync({ scope: 'organization', payload: { sections } })
    setSaved(true)
    setTimeout(() => setSaved(false), 2500)
  }

  const restore = () => {
    const seed = {} as Record<Section, { ar: string; en: string }>
    for (const s of SECTIONS) seed[s] = { ar: data.defaults.sections[s]?.ar ?? '', en: data.defaults.sections[s]?.en ?? '' }
    setDraft(seed)
  }

  return (
    <div className="space-y-5">
      <div className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
        <div className="mb-1 flex items-center justify-between">
          <h2 className="text-xl font-bold text-text-primary">الملاحظات والمنهجية</h2>
          {orgOverride && <span className="rounded-full bg-surface-secondary px-2 py-0.5 text-xs text-text-muted">إصدار {orgOverride.version}</span>}
        </div>
        <p className="mb-4 text-sm text-text-secondary">
          نص المؤسسة يتجاوز الافتراضي للنظام، ويمكن للمشروع أو العميل تجاوزه لاحقًا. الأولوية: مشروع ← عميل ← مؤسسة ← افتراضي.
        </p>

        {save.isError && <div className="mb-4"><Alert severity="danger" title="تعذّر الحفظ">تأكد من صلاحية settings.manage.</Alert></div>}
        {saved && <div className="mb-4"><Alert severity="positive" title="تم حفظ الملاحظات" /></div>}

        <div className="space-y-5">
          {SECTIONS.map((s) => (
            <div key={s}>
              <h3 className="mb-2 text-sm font-bold text-text-primary">النص {SECTION_LABELS[s]}</h3>
              <div className="grid gap-3 md:grid-cols-2">
                <Field label="العربية" htmlFor={`${s}-ar`}>
                  <Textarea id={`${s}-ar`} dir="rtl" rows={s === 'full' ? 4 : 2} value={draft[s].ar} onChange={(e) => setText(s, 'ar', e.target.value)} />
                </Field>
                <Field label="English" htmlFor={`${s}-en`}>
                  <Textarea id={`${s}-en`} dir="ltr" rows={s === 'full' ? 4 : 2} value={draft[s].en} onChange={(e) => setText(s, 'en', e.target.value)} />
                </Field>
              </div>
            </div>
          ))}
        </div>

        <div className="mt-5 flex items-center justify-between border-t border-border pt-4">
          <Button type="button" variant="ghost" onClick={restore}><RotateCcw size={15} className="inline" /> استعادة الافتراضي</Button>
          <Button type="button" onClick={submit} disabled={save.isPending}>{save.isPending ? 'جارٍ الحفظ…' : 'حفظ'}</Button>
        </div>
      </div>

      <div className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
        <h3 className="mb-3 text-sm font-bold text-text-primary">معاينة مباشرة</h3>
        <div className="space-y-4">
          <PerformanceNotice data={preview} variant="compact" />
          <PerformanceNotice data={preview} variant="methodology" objective="sales" />
        </div>
      </div>
    </div>
  )
}
