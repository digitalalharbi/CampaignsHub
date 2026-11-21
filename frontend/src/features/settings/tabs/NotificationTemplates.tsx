import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { fetchMailPreview, fetchMailPreviews } from '../api'
import { mailTemplateLabel } from '../mailTemplateLabels'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

/**
 * §17.4.5 — what each message looks like, for the people who arrange who gets them.
 *
 * Fixture text, never a customer's mail. Reading only: a test message is the platform admin's alone
 * (EMAIL-TEST-SEND-001), so this has no button that sends. The server answers 403 without `settings.manage`, and then this section does not exist on the page: a panel
 * explaining a refusal to somebody who never asked for it is noise. The frame is sandboxed with nothing
 * allowed, as the operator's gallery is.
 */
export function NotificationTemplates() {
  const ar = useUi((u) => u.locale) === 'ar'
  const [key, setKey] = useState('')
  const [locale, setLocale] = useState<'ar' | 'en'>(ar ? 'ar' : 'en')

  const list = useQuery({ queryKey: ['settings', 'mail-previews'], queryFn: fetchMailPreviews, retry: false })
  const chosen = key !== '' ? key : (list.data?.keys[0] ?? '')
  const preview = useQuery({
    queryKey: ['settings', 'mail-preview', chosen, locale],
    queryFn: () => fetchMailPreview(chosen, locale),
    enabled: chosen !== '',
  })

  if (list.isError || (!list.isLoading && !list.data)) return null

  return (
    <section data-testid="notification-templates" className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
      <h2 className="text-xl font-bold text-text-primary">{ar ? 'قوالب الرسائل' : 'Message templates'}</h2>
      <p className="mt-1 max-w-2xl text-sm leading-7 text-text-secondary">
        {ar ? 'شكل كل رسالة بنص تجريبي لا ببيانات عميل.' : 'What each message looks like, with fixture text and no customer data.'}
      </p>

      <div className="mt-4 grid gap-4 sm:grid-cols-2">
        <Field label={ar ? 'الرسالة' : 'Message'} htmlFor="tpl-key">
          <Select id="tpl-key" value={chosen} onChange={(e) => setKey(e.target.value)} options={(list.data?.keys ?? []).map((k) => ({ value: k, label: mailTemplateLabel(k, ar) }))} />
        </Field>
        <Field label={ar ? 'اللغة' : 'Language'} htmlFor="tpl-locale">
          <Select id="tpl-locale" value={locale} onChange={(e) => setLocale(e.target.value as 'ar' | 'en')} options={[{ value: 'ar', label: 'العربية' }, { value: 'en', label: 'English' }]} />
        </Field>
      </div>

      {preview.isLoading ? (
        <Skeleton className="mt-4 h-96" />
      ) : preview.data ? (
        <iframe title={`${chosen}.${locale}`} sandbox="" srcDoc={preview.data.html} className="mt-4 h-[640px] w-full rounded-xl border border-border bg-white" />
      ) : null}
    </section>
  )
}
