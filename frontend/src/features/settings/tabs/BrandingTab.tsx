import { useEffect, useState } from 'react'
import { useBranding, useSaveBranding, type Branding } from '../api'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Button } from '@/components/ui/Button'
import { Alert } from '@/components/ui/Alert'
import { Skeleton } from '@/components/ui/States'

export function BrandingTab() {
  const { data, isLoading } = useBranding()
  const save = useSaveBranding()
  const [b, setB] = useState<Branding | null>(null)
  const [saved, setSaved] = useState(false)

  useEffect(() => { if (data) setB(data.branding) }, [data])
  if (isLoading || !b) return <div className="space-y-3"><Skeleton className="h-10" /><Skeleton className="h-64" /></div>

  const submit = async () => { await save.mutateAsync(b); setSaved(true); setTimeout(() => setSaved(false), 2500) }
  const set = <K extends keyof Branding>(k: K, v: Branding[K]) => setB({ ...b, [k]: v })

  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_320px]">
      <div className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
        <h2 className="mb-4 text-xl font-bold text-text-primary">الهوية والعلامة التجارية</h2>
        {saved && <div className="mb-4"><Alert severity="positive" title="تم حفظ الهوية" /></div>}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="اسم البوابة" htmlFor="pn"><Input id="pn" value={b.portal_name} onChange={(e) => set('portal_name', e.target.value)} /></Field>
          <Field label="رابط الشعار" htmlFor="lg"><Input id="lg" type="url" value={b.logo_url ?? ''} onChange={(e) => set('logo_url', e.target.value || null)} placeholder="https://…" /></Field>
          <Field label="اللون الأساسي" htmlFor="pc">
            <div className="flex items-center gap-2"><input id="pc" type="color" value={b.primary_color} onChange={(e) => set('primary_color', e.target.value)} className="h-10 w-14 cursor-pointer rounded-lg border border-border" /><Input value={b.primary_color} onChange={(e) => set('primary_color', e.target.value)} className="tnum" /></div>
          </Field>
          <Field label="لون التقارير" htmlFor="ra">
            <div className="flex items-center gap-2"><input id="ra" type="color" value={b.report_accent} onChange={(e) => set('report_accent', e.target.value)} className="h-10 w-14 cursor-pointer rounded-lg border border-border" /><Input value={b.report_accent} onChange={(e) => set('report_accent', e.target.value)} className="tnum" /></div>
          </Field>
          <Field label="تذييل التقارير" htmlFor="rf" hint="يظهر أسفل تقارير العملاء"><Input id="rf" value={b.report_footer ?? ''} onChange={(e) => set('report_footer', e.target.value || null)} /></Field>
          <Field label="رابط شعار العميل (اختياري)" htmlFor="cl"><Input id="cl" type="url" value={b.client_logo_url ?? ''} onChange={(e) => set('client_logo_url', e.target.value || null)} placeholder="https://…" /></Field>
        </div>
        <div className="mt-5 flex justify-end border-t border-border pt-4"><Button onClick={submit} disabled={save.isPending}>{save.isPending ? 'جارٍ الحفظ…' : 'حفظ'}</Button></div>
      </div>

      {/* Live preview */}
      <div className="rounded-2xl border border-border bg-surface p-4 shadow-[var(--shadow-small)]">
        <h3 className="mb-3 text-sm font-bold text-text-primary">معاينة مباشرة</h3>
        <div className="overflow-hidden rounded-xl border border-border">
          <div className="flex items-center gap-2 p-3 text-white" style={{ background: b.primary_color }}>
            {b.logo_url ? <img src={b.logo_url} alt="" className="h-6 w-6 rounded object-contain" /> : <span className="grid h-6 w-6 place-items-center rounded bg-white/25 text-xs font-bold">{b.portal_name.slice(0, 1)}</span>}
            <span className="font-bold">{b.portal_name}</span>
          </div>
          <div className="p-4">
            <div className="mb-2 h-2 w-24 rounded-full" style={{ background: b.report_accent }} />
            <div className="rounded-lg p-3 text-sm text-white" style={{ background: b.report_accent }}>تقرير أداء تجريبي</div>
            <p className="mt-3 border-t border-border pt-2 text-[11px] text-text-muted">{b.report_footer || 'تذييل التقرير يظهر هنا'}</p>
          </div>
        </div>
        <p className="mt-2 text-xs text-text-muted">White Label كامل يأتي لاحقًا؛ هذه المعاينة تعكس الألوان والاسم الحاليين.</p>
      </div>
    </div>
  )
}
