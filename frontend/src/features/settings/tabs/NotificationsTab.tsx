import { useEffect, useState } from 'react'
import { useNotifPrefs, useSaveNotifPrefs, type NotifPrefs } from '../api'
import { Switch } from '@/components/ui/Switch'
import { Select } from '@/components/ui/Select'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Button } from '@/components/ui/Button'
import { Alert } from '@/components/ui/Alert'
import { Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

const CAT_LABELS: Record<string, { ar: string; en: string }> = {
  budget: { ar: 'الميزانية', en: 'Budget' },
  performance: { ar: 'الأداء', en: 'Performance' },
  sync: { ar: 'المزامنة', en: 'Syncing' },
  token: { ar: 'انتهاء الصلاحيات/Token', en: 'Token expiry' },
  reports: { ar: 'التقارير', en: 'Reports' },
  security: { ar: 'الأمان', en: 'Security' },
}
const FREQ = [
  { value: 'realtime', ar: 'فوري', en: 'Immediately' },
  { value: 'hourly', ar: 'كل ساعة', en: 'Hourly' },
  { value: 'daily', ar: 'يومي', en: 'Daily' },
]

export function NotificationsTab() {
  const ar = useUi((u) => u.locale) === 'ar'
  const { data, isLoading } = useNotifPrefs()
  const save = useSaveNotifPrefs()
  const [p, setP] = useState<NotifPrefs | null>(null)
  const [saved, setSaved] = useState(false)

  useEffect(() => { if (data) setP(data) }, [data])
  if (isLoading || !p) return <div className="space-y-3"><Skeleton className="h-10" /><Skeleton className="h-72" /></div>

  const submit = async () => {
    const { available_categories, ...body } = p
    void available_categories
    await save.mutateAsync(body)
    setSaved(true)
    setTimeout(() => setSaved(false), 2500)
  }
  const setCat = (c: string, k: 'in_app' | 'email', v: boolean) =>
    setP({ ...p, categories: { ...p.categories, [c]: { ...(p.categories[c] ?? { in_app: true, email: true }), [k]: v } } })

  return (
    <div className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
      <h2 className="mb-4 text-xl font-bold text-text-primary">{ar ? 'تفضيلات الإشعارات' : 'Notification preferences'}</h2>
      {saved && <div className="mb-4"><Alert severity="positive" title={ar ? 'تم حفظ التفضيلات' : 'Preferences saved'} /></div>}

      <div className="mb-5 flex flex-wrap gap-6">
        <Switch checked={p.channels.in_app} onCheckedChange={(v) => setP({ ...p, channels: { ...p.channels, in_app: v } })} label={ar ? 'إشعارات داخل النظام' : 'In-app notifications'} />
        <Switch checked={p.channels.email} onCheckedChange={(v) => setP({ ...p, channels: { ...p.channels, email: v } })} label={ar ? 'البريد الإلكتروني' : 'Email'} />
      </div>

      <div className="overflow-x-auto">
        <table className="w-full min-w-[420px] text-sm">
          <thead><tr className="border-b border-border text-text-muted"><th className="p-2 text-start">{ar ? 'النوع' : 'Type'}</th><th className="p-2">{ar ? 'داخل النظام' : 'In-app'}</th><th className="p-2">{ar ? 'بريد' : 'Email'}</th></tr></thead>
          <tbody>
            {(p.available_categories ?? Object.keys(p.categories)).map((c) => (
              <tr key={c} className="border-b border-border last:border-0">
                <td className="p-2 font-medium text-text-primary">{CAT_LABELS[c] ? (ar ? CAT_LABELS[c].ar : CAT_LABELS[c].en) : c}</td>
                <td className="p-2 text-center"><input type="checkbox" checked={p.categories[c]?.in_app ?? true} onChange={(e) => setCat(c, 'in_app', e.target.checked)} className="h-4 w-4 accent-[var(--brand-600)]" /></td>
                <td className="p-2 text-center"><input type="checkbox" checked={p.categories[c]?.email ?? false} onChange={(e) => setCat(c, 'email', e.target.checked)} className="h-4 w-4 accent-[var(--brand-600)]" /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="mt-5 grid gap-4 sm:grid-cols-3">
        <Field label={ar ? 'التكرار' : 'Frequency'} htmlFor="freq"><Select id="freq" value={p.frequency} onChange={(e) => setP({ ...p, frequency: e.target.value as NotifPrefs['frequency'] })} options={FREQ.map((f) => ({ value: f.value, label: ar ? f.ar : f.en }))} /></Field>
        <Field label={ar ? 'ساعات الهدوء — من' : 'Quiet hours — from'} htmlFor="qs"><Input id="qs" type="time" value={p.quiet_hours.start} onChange={(e) => setP({ ...p, quiet_hours: { ...p.quiet_hours, start: e.target.value } })} /></Field>
        <Field label={ar ? 'إلى' : 'To'} htmlFor="qe"><Input id="qe" type="time" value={p.quiet_hours.end} onChange={(e) => setP({ ...p, quiet_hours: { ...p.quiet_hours, end: e.target.value } })} /></Field>
      </div>
      <div className="mt-2"><Switch checked={p.quiet_hours.enabled} onCheckedChange={(v) => setP({ ...p, quiet_hours: { ...p.quiet_hours, enabled: v } })} label={ar ? 'تفعيل ساعات الهدوء' : 'Enable quiet hours'} /></div>

      <div className="mt-5 flex justify-end border-t border-border pt-4"><Button onClick={submit} disabled={save.isPending}>{save.isPending ? (ar ? 'جارٍ الحفظ…' : 'Saving…') : (ar ? 'حفظ التفضيلات' : 'Save preferences')}</Button></div>
    </div>
  )
}
