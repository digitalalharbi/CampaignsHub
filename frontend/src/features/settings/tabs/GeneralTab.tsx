import { useEffect, useState } from 'react'
import { useOrgSettings, useUpdateOrgSettings, type OrgGeneral } from '../api'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Switch } from '@/components/ui/Switch'
import { Button } from '@/components/ui/Button'
import { Skeleton } from '@/components/ui/States'
import { Alert } from '@/components/ui/Alert'
import { toApiError } from '@/lib/api/client'

const ACCOUNT_LABELS: Record<string, string> = { agency: 'وكالة', freelancer: 'مستقل', in_house: 'فريق داخلي', brand: 'علامة تجارية' }
const NUMBER_LABELS: Record<string, string> = { latin: 'أرقام لاتينية (123)', grouped: 'أرقام مفصولة (1,234)' }
const MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر']
const CURRENCIES = ['SAR', 'AED', 'USD', 'EUR', 'GBP', 'KWD', 'BHD', 'QAR', 'EGP']
const TIMEZONES = ['Asia/Riyadh', 'Asia/Dubai', 'Asia/Kuwait', 'Asia/Qatar', 'Africa/Cairo', 'Europe/London', 'UTC']

export function GeneralTab() {
  const { data, isLoading } = useOrgSettings()
  const save = useUpdateOrgSettings()
  const [name, setName] = useState('')
  const [g, setG] = useState<OrgGeneral | null>(null)
  const [saved, setSaved] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})

  useEffect(() => {
    if (data) {
      setName(data.name)
      setG(data.general)
    }
  }, [data])

  if (isLoading || !g) return <div className="space-y-3"><Skeleton className="h-10" /><Skeleton className="h-64" /></div>

  const set = <K extends keyof OrgGeneral>(k: K, v: OrgGeneral[K]) => setG((prev) => (prev ? { ...prev, [k]: v } : prev))

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setErrors({})
    setSaved(false)
    try {
      await save.mutateAsync({ name, general: g })
      setSaved(true)
      setTimeout(() => setSaved(false), 2500)
    } catch (err) {
      const api = toApiError(err)
      setErrors(api.errors ? Object.fromEntries(Object.entries(api.errors).map(([k, v]) => [k, v[0]])) : {})
    }
  }

  return (
    <form onSubmit={submit} className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
      <div className="mb-5 flex items-center justify-between">
        <h2 className="text-xl font-bold text-text-primary">الإعدادات العامة</h2>
        {data?.general.demo_mode && <span className="rounded-full bg-[var(--warning-background)] px-2 py-0.5 text-xs font-semibold text-warning">وضع تجريبي</span>}
      </div>

      {save.isError && !Object.keys(errors).length && <div className="mb-4"><Alert severity="danger" title="تعذّر الحفظ">تأكد من الصلاحية (settings.manage).</Alert></div>}
      {saved && <div className="mb-4"><Alert severity="positive" title="تم حفظ الإعدادات" /></div>}

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="اسم المؤسسة / الوكالة" htmlFor="org-name" required error={errors['name']}>
          <Input id="org-name" value={name} onChange={(e) => setName(e.target.value)} />
        </Field>
        <Field label="نوع الحساب" htmlFor="acct">
          <Select id="acct" value={g.account_type} onChange={(e) => set('account_type', e.target.value)}
            options={(data?.options.account_types ?? []).map((v) => ({ value: v, label: ACCOUNT_LABELS[v] ?? v }))} />
        </Field>
        <Field label="رابط الشعار" htmlFor="logo" hint="رابط صورة مباشر (https)" error={errors['general.logo_url']}>
          <Input id="logo" type="url" value={g.logo_url ?? ''} onChange={(e) => set('logo_url', e.target.value || null)} placeholder="https://…" />
        </Field>
        <Field label="بريد التواصل" htmlFor="cemail" error={errors['general.contact_email']}>
          <Input id="cemail" type="email" value={g.contact_email ?? ''} onChange={(e) => set('contact_email', e.target.value || null)} />
        </Field>
        <Field label="هاتف التواصل" htmlFor="cphone">
          <Input id="cphone" value={g.contact_phone ?? ''} onChange={(e) => set('contact_phone', e.target.value || null)} />
        </Field>
        <Field label="الدولة" htmlFor="country" hint="رمز ISO من حرفين">
          <Input id="country" value={g.country} maxLength={2} onChange={(e) => set('country', e.target.value.toUpperCase())} />
        </Field>
        <Field label="اللغة الافتراضية" htmlFor="loc">
          <Select id="loc" value={g.default_locale} onChange={(e) => set('default_locale', e.target.value as 'ar' | 'en')}
            options={[{ value: 'ar', label: 'العربية' }, { value: 'en', label: 'English' }]} />
        </Field>
        <Field label="العملة الافتراضية" htmlFor="cur">
          <Select id="cur" value={g.default_currency} onChange={(e) => set('default_currency', e.target.value)} options={CURRENCIES.map((c) => ({ value: c, label: c }))} />
        </Field>
        <Field label="المنطقة الزمنية" htmlFor="tz">
          <Select id="tz" value={g.timezone} onChange={(e) => set('timezone', e.target.value)} options={TIMEZONES.map((c) => ({ value: c, label: c }))} />
        </Field>
        <Field label="تنسيق التاريخ" htmlFor="df">
          <Select id="df" value={g.date_format} onChange={(e) => set('date_format', e.target.value)} options={(data?.options.date_formats ?? []).map((v) => ({ value: v, label: v }))} />
        </Field>
        <Field label="تنسيق الأرقام" htmlFor="nf">
          <Select id="nf" value={g.number_format} onChange={(e) => set('number_format', e.target.value)}
            options={(data?.options.number_formats ?? []).map((v) => ({ value: v, label: NUMBER_LABELS[v] ?? v }))} />
        </Field>
        <Field label="بداية السنة المالية" htmlFor="fy">
          <Select id="fy" value={String(g.fiscal_year_start_month)} onChange={(e) => set('fiscal_year_start_month', Number(e.target.value))}
            options={MONTHS.map((m, i) => ({ value: String(i + 1), label: m }))} />
        </Field>
      </div>

      <div className="mt-5 flex items-center justify-between border-t border-border pt-4">
        <Switch checked={g.demo_mode} onCheckedChange={(v) => set('demo_mode', v)} label="وضع الحساب التجريبي" />
        <Button type="submit" disabled={save.isPending}>{save.isPending ? 'جارٍ الحفظ…' : 'حفظ التغييرات'}</Button>
      </div>
    </form>
  )
}
