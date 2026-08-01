import { useEffect, useState } from 'react'
import { useOrgSettings, useUpdateOrgSettings, type OrgGeneral } from '../api'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Switch } from '@/components/ui/Switch'
import { Button } from '@/components/ui/Button'
import { Skeleton } from '@/components/ui/States'
import { Alert } from '@/components/ui/Alert'
import { ErrorSummary, type FieldError } from '@/components/forms'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/** Server error key → input id, so the ErrorSummary can focus the offending control. */
const GENERAL_FIELD_IDS: Record<string, string> = { name: 'org-name', 'general.logo_url': 'logo', 'general.contact_email': 'cemail' }

const ACCOUNT_LABELS: Record<string, { ar: string; en: string }> = {
  agency: { ar: 'وكالة', en: 'Agency' },
  freelancer: { ar: 'مستقل', en: 'Freelancer' },
  in_house: { ar: 'فريق داخلي', en: 'In-house team' },
  brand: { ar: 'علامة تجارية', en: 'Brand' },
}
const NUMBER_LABELS: Record<string, { ar: string; en: string }> = {
  latin: { ar: 'أرقام لاتينية (123)', en: 'Latin digits (123)' },
  grouped: { ar: 'أرقام مفصولة (1,234)', en: 'Grouped digits (1,234)' },
}
const MONTHS_AR = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر']
const MONTHS_EN = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const CURRENCIES = ['SAR', 'AED', 'USD', 'EUR', 'GBP', 'KWD', 'BHD', 'QAR', 'EGP']
const TIMEZONES = ['Asia/Riyadh', 'Asia/Dubai', 'Asia/Kuwait', 'Asia/Qatar', 'Africa/Cairo', 'Europe/London', 'UTC']

export function GeneralTab() {
  const ar = useUi((u) => u.locale) === 'ar'
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
        <h2 className="text-xl font-bold text-text-primary">{ar ? 'الإعدادات العامة' : 'General settings'}</h2>
        {data?.general.demo_mode && <span className="rounded-full bg-[var(--warning-background)] px-2 py-0.5 text-xs font-semibold text-warning">{ar ? 'وضع تجريبي' : 'Demo mode'}</span>}
      </div>

      {save.isError && !Object.keys(errors).length && <div className="mb-4"><Alert severity="danger" title={ar ? 'تعذّر الحفظ' : 'Could not save'}>{ar ? 'تأكد من الصلاحية (settings.manage).' : 'Check that you hold settings.manage.'}</Alert></div>}
      {Object.keys(errors).length > 0 && (
        <div className="mb-4">
          <ErrorSummary
            title={ar ? 'يرجى تصحيح الأخطاء التالية' : 'Please correct the following'}
            errors={Object.entries(errors).map<FieldError>(([k, message]) => ({ field: GENERAL_FIELD_IDS[k] ?? k, message }))}
          />
        </div>
      )}
      {saved && <div className="mb-4"><Alert severity="positive" title={ar ? 'تم حفظ الإعدادات' : 'Settings saved'} /></div>}

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={ar ? 'اسم المؤسسة / الوكالة' : 'Organisation / agency name'} htmlFor="org-name" required error={errors['name']}>
          <Input id="org-name" value={name} onChange={(e) => setName(e.target.value)} />
        </Field>
        <Field label={ar ? 'نوع الحساب' : 'Account type'} htmlFor="acct">
          <Select id="acct" value={g.account_type} onChange={(e) => set('account_type', e.target.value)}
            options={(data?.options.account_types ?? []).map((v) => ({ value: v, label: ACCOUNT_LABELS[v] ? (ar ? ACCOUNT_LABELS[v].ar : ACCOUNT_LABELS[v].en) : v }))} />
        </Field>
        <Field label={ar ? 'رابط الشعار' : 'Logo URL'} htmlFor="logo" hint={ar ? 'رابط صورة مباشر (https)' : 'A direct image link (https)'} error={errors['general.logo_url']}>
          <Input id="logo" type="url" value={g.logo_url ?? ''} onChange={(e) => set('logo_url', e.target.value || null)} placeholder="https://…" />
        </Field>
        <Field label={ar ? 'بريد التواصل' : 'Contact email'} htmlFor="cemail" error={errors['general.contact_email']}>
          <Input id="cemail" type="email" value={g.contact_email ?? ''} onChange={(e) => set('contact_email', e.target.value || null)} />
        </Field>
        <Field label={ar ? 'هاتف التواصل' : 'Contact phone'} htmlFor="cphone">
          <Input id="cphone" value={g.contact_phone ?? ''} onChange={(e) => set('contact_phone', e.target.value || null)} />
        </Field>
        <Field label={ar ? 'الدولة' : 'Country'} htmlFor="country" hint={ar ? 'رمز ISO من حرفين' : 'A two-letter ISO code'}>
          <Input id="country" value={g.country} maxLength={2} onChange={(e) => set('country', e.target.value.toUpperCase())} />
        </Field>
        <Field label={ar ? 'اللغة الافتراضية' : 'Default language'} htmlFor="loc">
          <Select id="loc" value={g.default_locale} onChange={(e) => set('default_locale', e.target.value as 'ar' | 'en')}
            options={[{ value: 'ar', label: ar ? 'العربية' : 'Arabic' }, { value: 'en', label: 'English' }]} />
        </Field>
        <Field label={ar ? 'العملة الافتراضية' : 'Default currency'} htmlFor="cur">
          <Select id="cur" value={g.default_currency} onChange={(e) => set('default_currency', e.target.value)} options={CURRENCIES.map((c) => ({ value: c, label: c }))} />
        </Field>
        <Field label={ar ? 'المنطقة الزمنية' : 'Time zone'} htmlFor="tz">
          <Select id="tz" value={g.timezone} onChange={(e) => set('timezone', e.target.value)} options={TIMEZONES.map((c) => ({ value: c, label: c }))} />
        </Field>
        <Field label={ar ? 'تنسيق التاريخ' : 'Date format'} htmlFor="df">
          <Select id="df" value={g.date_format} onChange={(e) => set('date_format', e.target.value)} options={(data?.options.date_formats ?? []).map((v) => ({ value: v, label: v }))} />
        </Field>
        <Field label={ar ? 'تنسيق الأرقام' : 'Number format'} htmlFor="nf">
          <Select id="nf" value={g.number_format} onChange={(e) => set('number_format', e.target.value)}
            options={(data?.options.number_formats ?? []).map((v) => ({ value: v, label: NUMBER_LABELS[v] ? (ar ? NUMBER_LABELS[v].ar : NUMBER_LABELS[v].en) : v }))} />
        </Field>
        <Field label={ar ? 'بداية السنة المالية' : 'Financial year starts'} htmlFor="fy">
          <Select id="fy" value={String(g.fiscal_year_start_month)} onChange={(e) => set('fiscal_year_start_month', Number(e.target.value))}
            options={(ar ? MONTHS_AR : MONTHS_EN).map((m, i) => ({ value: String(i + 1), label: m }))} />
        </Field>
      </div>

      <div className="mt-5 flex items-center justify-between border-t border-border pt-4">
        <Switch checked={g.demo_mode} onCheckedChange={(v) => set('demo_mode', v)} label={ar ? 'وضع الحساب التجريبي' : 'Demo account mode'} />
        <Button type="submit" disabled={save.isPending}>{save.isPending ? (ar ? 'جارٍ الحفظ…' : 'Saving…') : (ar ? 'حفظ التغييرات' : 'Save changes')}</Button>
      </div>
    </form>
  )
}
