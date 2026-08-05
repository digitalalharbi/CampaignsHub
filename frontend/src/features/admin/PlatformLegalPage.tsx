import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Check, ShieldCheck } from 'lucide-react'
import { getPlatformSettings, savePlatformSettings, type PlatformSettingsPayload } from './legalApi'
import { Button } from '@/components/ui/Button'
import { TextInput } from '@/components/ui/form'
import { EmptyState, Skeleton } from '@/components/ui/States'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * LEGAL-001 — who operates this platform, as printed on the public policies.
 *
 * ## Why the empty fields stay empty
 *
 * A legal name, a commercial registration, a tax number and a jurisdiction are facts about the
 * operator's company. They are not derivable from anything in this system, and a plausible-looking
 * default for any of them would be published on a privacy policy and relied upon by a reader — which
 * is a considerably worse outcome than a blank. So this screen ships them empty, states which ones
 * are still missing, and fills in nothing on the operator's behalf.
 *
 * The contact address is the single exception, and only because the product already publishes
 * `info@CampaignsHub.io` on its own marketing page: that is ours to state, not a guess about anyone.
 *
 * ## Why this is not in workspace settings
 *
 * There is one operator and one privacy policy. A tenant administrator able to edit the named data
 * controller would be able to change who is legally responsible for every customer's data, so this
 * lives behind the platform gate with the rest of `/admin`.
 */
export function PlatformLegalPage() {
  const { locale } = useUi()
  const ar = locale === 'ar'
  const qc = useQueryClient()

  const query = useQuery({ queryKey: ['admin', 'platform-settings'], queryFn: getPlatformSettings })
  const [form, setForm] = useState<Record<string, string>>({})
  const [saved, setSaved] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Seed the form once the record arrives; nulls become empty strings for the inputs, and are sent
  // back as empty strings, which the API stores as null again.
  useEffect(() => {
    if (!query.data) return
    const d = query.data as PlatformSettingsPayload
    setForm({
      legal_name_ar: d.legal_name_ar ?? '', legal_name_en: d.legal_name_en ?? '',
      trading_name: d.trading_name ?? '', registration_number: d.registration_number ?? '',
      tax_number: d.tax_number ?? '', jurisdiction: d.jurisdiction ?? '',
      address_ar: d.address_ar ?? '', address_en: d.address_en ?? '',
      contact_email: d.contact_email ?? '', support_email: d.support_email ?? '',
      security_email: d.security_email ?? '', privacy_email: d.privacy_email ?? '',
      phone: d.phone ?? '', dpo_name: d.dpo_name ?? '', dpo_email: d.dpo_email ?? '',
    })
  }, [query.data])

  const save = useMutation({
    mutationFn: () => savePlatformSettings(form),
    onSuccess: () => {
      setSaved(true)
      setError(null)
      qc.invalidateQueries({ queryKey: ['admin', 'platform-settings'] })
      window.setTimeout(() => setSaved(false), 4000)
    },
    onError: (e) => setError(toApiError(e).message),
  })

  const set = (k: string) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [k]: e.target.value }))

  if (query.isLoading) return <div className="space-y-3"><Skeleton className="h-24" /><Skeleton className="h-64" /></div>
  if (query.isError) {
    return <EmptyState title={ar ? 'تعذّر تحميل بيانات المنصة' : 'Could not load the platform details'} />
  }

  const published = query.data?.published ?? false

  const group = (title: string, fields: { key: string; label: string; type?: string; hint?: string }[]) => (
    <section className="rounded-2xl border border-border bg-surface p-5">
      <h2 className="text-base font-bold text-text-primary">{title}</h2>
      <div className="mt-4 grid gap-4 sm:grid-cols-2">
        {fields.map((f) => (
          <TextInput
            key={f.key}
            label={f.label}
            hint={f.hint}
            value={form[f.key] ?? ''}
            onChange={set(f.key)}
            type={f.type ?? 'text'}
            data-testid={`platform-${f.key}`}
          />
        ))}
      </div>
    </section>
  )

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-2xl font-extrabold tracking-tight text-text-primary">
          {ar ? 'بيانات مشغّل المنصة' : 'Platform operator details'}
        </h1>
        <p className="mt-1 text-sm text-text-secondary">
          {ar
            ? 'تظهر هذه البيانات على صفحات السياسات العامة. ما لا تُدخله يبقى غير معلن — ولا يُختلق.'
            : 'These appear on the public policy pages. Anything you leave empty stays unpublished — nothing is invented for you.'}
        </p>
      </div>

      {/*
        The state of the published identity, said plainly.

        The operator's real question here is «can my privacy policy name a controller yet», and
        answering it by making them compare this form against a policy page is how a field stays
        empty for a year.
      */}
      {published ? (
        <p data-testid="platform-published" className="flex items-center gap-2 rounded-xl border border-border bg-[var(--success-background)] px-3 py-2 text-sm text-text-secondary">
          <ShieldCheck size={16} />
          {ar ? 'الهوية القانونية معلنة على صفحات السياسات.' : 'The legal identity is published on the policy pages.'}
        </p>
      ) : (
        <p data-testid="platform-unpublished" className="flex items-start gap-2 rounded-xl border border-border bg-[var(--warning-background)] px-3 py-2 text-sm text-text-secondary">
          <AlertTriangle size={16} className="mt-0.5 shrink-0" />
          {ar
            ? 'لم يُدخل اسم قانوني بعد، لذلك تعرض صفحات السياسات أن المشغّل لم ينشر بياناته. أدخل الاسم بالعربية أو الإنجليزية على الأقل.'
            : 'No legal name has been entered, so the policy pages state that the operator has not published its details. Enter the name in Arabic or English at minimum.'}
        </p>
      )}

      {group(ar ? 'الهوية القانونية' : 'Legal identity', [
        { key: 'legal_name_ar', label: ar ? 'الاسم القانوني (عربي)' : 'Legal name (Arabic)' },
        { key: 'legal_name_en', label: ar ? 'الاسم القانوني (إنجليزي)' : 'Legal name (English)' },
        { key: 'trading_name', label: ar ? 'الاسم التجاري' : 'Trading name' },
        { key: 'registration_number', label: ar ? 'رقم السجل التجاري' : 'Commercial registration number' },
        { key: 'tax_number', label: ar ? 'الرقم الضريبي' : 'Tax number' },
        { key: 'jurisdiction', label: ar ? 'الجهة والاختصاص القضائي' : 'Jurisdiction' },
      ])}

      {group(ar ? 'العنوان' : 'Address', [
        { key: 'address_ar', label: ar ? 'العنوان (عربي)' : 'Address (Arabic)' },
        { key: 'address_en', label: ar ? 'العنوان (إنجليزي)' : 'Address (English)' },
      ])}

      {group(ar ? 'وسائل التواصل' : 'Contacts', [
        {
          key: 'contact_email', label: ar ? 'البريد العام' : 'General email', type: 'email',
          hint: ar ? 'مطلوب — يظهر على كل صفحة سياسة كوسيلة للتواصل مع المشغّل.' : 'Required — printed on every policy page as the way to reach the operator.',
        },
        { key: 'support_email', label: ar ? 'بريد الدعم' : 'Support email', type: 'email', hint: ar ? 'يعود إلى البريد العام إن تُرك فارغًا.' : 'Falls back to the general email when empty.' },
        { key: 'security_email', label: ar ? 'بريد الأمان' : 'Security email', type: 'email', hint: ar ? 'للإبلاغ عن الثغرات.' : 'For vulnerability reports.' },
        { key: 'privacy_email', label: ar ? 'بريد الخصوصية' : 'Privacy email', type: 'email', hint: ar ? 'لطلبات أصحاب البيانات.' : 'For data-subject requests.' },
        { key: 'phone', label: ar ? 'الهاتف' : 'Phone' },
      ])}

      {group(ar ? 'مسؤول حماية البيانات' : 'Data protection officer', [
        {
          key: 'dpo_name', label: ar ? 'الاسم' : 'Name',
          hint: ar ? 'اتركه فارغًا إن لم يُعيَّن أحد — لا يُفترض وجوده.' : 'Leave empty if nobody is appointed — one is not assumed.',
        },
        { key: 'dpo_email', label: ar ? 'البريد' : 'Email', type: 'email' },
      ])}

      {error && <p data-testid="platform-error" className="rounded-xl border border-border bg-[var(--danger-background)] px-3 py-2 text-sm text-danger">{error}</p>}

      <div className="flex items-center gap-3">
        <Button onClick={() => save.mutate()} disabled={save.isPending} data-testid="platform-save">
          {save.isPending ? (ar ? 'يُحفظ…' : 'Saving…') : (ar ? 'حفظ' : 'Save')}
        </Button>
        {saved && (
          <span data-testid="platform-saved" className="flex items-center gap-1.5 text-sm font-semibold text-success">
            <Check size={15} /> {ar ? 'حُفظ' : 'Saved'}
          </span>
        )}
      </div>
    </div>
  )
}
