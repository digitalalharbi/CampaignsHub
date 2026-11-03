import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { fetchMailPreview, fetchMailPreviews, sendMyTestEmail } from '../api'
import { stageWords } from '../deliveryWords'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

/**
 * §17.4.5 — what each message looks like, for the people who arrange who gets them.
 *
 * Fixture text, never a customer's mail, and the test goes to the signed-in person alone. The server
 * answers 403 without `settings.manage`, and then this section does not exist on the page: a panel
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
  const test = useMutation({ mutationFn: () => sendMyTestEmail(locale, chosen) })

  if (list.isError || (!list.isLoading && !list.data)) return null

  return (
    <section data-testid="notification-templates" className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
      <h2 className="text-xl font-bold text-text-primary">{ar ? 'قوالب الرسائل' : 'Message templates'}</h2>
      <p className="mt-1 max-w-2xl text-sm leading-7 text-text-secondary">
        {ar ? 'شكل كل رسالة بنص تجريبي لا ببيانات عميل. الاختبار يصل إلى بريدك أنت فقط.' : 'What each message looks like, with fixture text and no customer data. A test goes to your own address only.'}
      </p>

      <div className="mt-4 grid gap-4 sm:grid-cols-2">
        <Field label={ar ? 'الرسالة' : 'Message'} htmlFor="tpl-key">
          <Select id="tpl-key" value={chosen} onChange={(e) => setKey(e.target.value)} options={(list.data?.keys ?? []).map((k) => ({ value: k, label: k }))} />
        </Field>
        <Field label={ar ? 'اللغة' : 'Language'} htmlFor="tpl-locale">
          <Select id="tpl-locale" value={locale} onChange={(e) => setLocale(e.target.value as 'ar' | 'en')} options={[{ value: 'ar', label: 'العربية' }, { value: 'en', label: 'English' }]} />
        </Field>
      </div>

      <div className="mt-3 flex flex-wrap items-center gap-3">
        <Button variant="secondary" disabled={chosen === '' || test.isPending} onClick={() => test.mutate()} data-testid="template-test-send">
          {ar ? 'أرسل هذه الرسالة إلى بريدي' : 'Send this message to me'}
        </Button>
        {test.data && (
          <span data-testid="template-test-result" className={`text-[13px] font-semibold ${stageWords(test.data.delivery_state).tone}`}>
            {ar ? stageWords(test.data.delivery_state).ar : stageWords(test.data.delivery_state).en}
          </span>
        )}
        {test.isError && <span role="alert" className="text-[13px] text-danger">{ar ? 'تعذّر تنفيذ الاختبار.' : 'The test could not be run.'}</span>}
      </div>

      {preview.isLoading ? (
        <Skeleton className="mt-4 h-96" />
      ) : preview.data ? (
        <iframe title={`${chosen}.${locale}`} sandbox="" srcDoc={preview.data.html} className="mt-4 h-[640px] w-full rounded-xl border border-border bg-white" />
      ) : null}
    </section>
  )
}
