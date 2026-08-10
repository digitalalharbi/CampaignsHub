import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { EmailInput, TextInput, TextareaField } from '@/components/ui/form'
import { Select } from '@/components/ui/Select'
import { PhoneField, phoneFieldValue, DEFAULT_DIAL_CODE } from '@/components/ui/PhoneField'
import { sendContact, type ContactTopic } from '@/features/marketing/publicFormsApi'
import { toApiError } from '@/lib/api/client'

/**
 * LOGIN-HELP-001 — «تحتاج مساعدة في البدء؟», answered without leaving the sign-in page.
 *
 * ## What this is NOT
 *
 * It is not a second way to sign up, and nothing about sending it creates an account, a workspace or
 * a subscription. Starter and Growth stay self-service; this exists for the enquiries self-service
 * genuinely does not answer — an agency weighing whether the plan covers eleven clients, somebody
 * who cannot tell which platforms they can connect, somebody who simply cannot get in.
 *
 * ## Why a panel and not a page
 *
 * Sending it is a detour, not a journey. Navigating away from `/login` to ask a question costs the
 * URL somebody arrived on — including the `?redirect=` that was going to take them back to the page
 * they wanted — and asking a question should not throw away where they were going.
 *
 * ## Why a closed list of needs
 *
 * A free-text subject cannot be routed. «Choosing a plan» and «connecting accounts» are answered by
 * different people, and the operator queue can only group by an answer that came from a fixed list.
 * The details stay optional and free — the list is for us, the box is for them.
 */

const COPY = {
  ar: {
    trigger: 'تواصل معنا',
    prompt: 'تحتاج مساعدة في البدء؟',
    title: 'كيف يمكننا مساعدتك؟',
    description: 'أرسل لنا تفاصيل احتياجك، وسيتواصل معك فريقنا للمساعدة في اختيار الباقة وتجهيز حسابك.',
    name: 'الاسم',
    email: 'البريد الإلكتروني للعمل',
    phone: 'رقم الجوال',
    phoneOptional: 'اختياري',
    topic: 'نوع الاحتياج',
    details: 'التفاصيل',
    detailsOptional: 'اختياري',
    submit: 'إرسال الطلب',
    cancel: 'إغلاق',
    done: 'تم استلام طلبك، وسيتواصل معك فريقنا.',
    phoneInvalid: 'أدخل رقم جوال صحيحًا.',
    topics: {
      own_campaigns: 'إدارة حملاتي',
      multi_client_campaigns: 'إدارة حملات عدة عملاء',
      plan_choice: 'مساعدة في اختيار الباقة',
      connect_accounts: 'ربط الحسابات والمنصات',
      other: 'استفسار آخر',
    },
  },
  en: {
    trigger: 'Contact us',
    prompt: 'Need help getting started?',
    title: 'How can we help?',
    description: 'Tell us what you need and our team will help you choose a plan and set your account up.',
    name: 'Name',
    email: 'Work email address',
    phone: 'Mobile number',
    phoneOptional: 'optional',
    topic: 'What do you need?',
    details: 'Details',
    detailsOptional: 'optional',
    submit: 'Send request',
    cancel: 'Close',
    done: 'We have your request — our team will be in touch.',
    phoneInvalid: 'Enter a valid mobile number.',
    topics: {
      own_campaigns: 'Managing my own campaigns',
      multi_client_campaigns: 'Managing campaigns for several clients',
      plan_choice: 'Help choosing a plan',
      connect_accounts: 'Connecting accounts and platforms',
      other: 'Something else',
    },
  },
} as const

const TOPICS: ContactTopic[] = ['own_campaigns', 'multi_client_campaigns', 'plan_choice', 'connect_accounts', 'other']

export function HelpRequestModal({ locale, ar }: { locale: 'ar' | 'en'; ar: boolean }) {
  const c = COPY[locale]
  const [open, setOpen] = useState(false)

  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [phone, setPhone] = useState('')
  const [dialCode, setDialCode] = useState(DEFAULT_DIAL_CODE)
  const [phoneError, setPhoneError] = useState<string | null>(null)
  const [topic, setTopic] = useState<ContactTopic>('own_campaigns')
  const [details, setDetails] = useState('')
  /* The honeypot. Real people never fill a field they cannot see; bots fill every field they find. */
  const [website, setWebsite] = useState('')

  const send = useMutation({
    mutationFn: () =>
      sendContact({
        name: name.trim(),
        email: email.trim(),
        // A blank optional field is ABSENT, not an empty string — the server treats it as unanswered.
        phone: phone.trim() === '' ? undefined : (phoneFieldValue(phone, dialCode) ?? phone.trim()),
        topic,
        source: 'login',
        message: details.trim() === '' ? undefined : details.trim(),
        website: website || undefined,
      }),
  })

  const close = () => {
    setOpen(false)
    /*
     * Reset only AFTER a success.
     *
     * A failed send that emptied the form would make the person retype everything to try again, and
     * the most likely reason for a failure is a network they are about to get back.
     */
    if (send.isSuccess) {
      setName(''); setEmail(''); setPhone(''); setDetails(''); setTopic('own_campaigns')
      send.reset()
    }
  }

  const submit = () => {
    if (phone.trim() !== '' && phoneFieldValue(phone, dialCode) === null) {
      setPhoneError(c.phoneInvalid)
      return
    }
    setPhoneError(null)
    send.mutate()
  }

  const error = send.isError ? toApiError(send.error) : null

  return (
    <>
      {/*
        A secondary route, and it has to LOOK secondary.

        Rendered as text beside a link rather than as a second button: two buttons of similar weight
        under a sign-in form make somebody stop and choose, and the overwhelming majority of people
        on this page already have an account and simply want in.
      */}
      <p className="mt-5 text-center text-sm text-text-secondary">
        {c.prompt}{' '}
        <button
          type="button"
          data-testid="login-help-open"
          onClick={() => setOpen(true)}
          className="font-semibold text-brand-600 hover:underline"
        >
          {c.trigger}
        </button>
      </p>

      <Modal open={open} onClose={close} title={c.title} size="md">
        {send.isSuccess ? (
          <p data-testid="login-help-success" role="status" className="rounded-xl bg-[var(--positive-background)] px-4 py-3 text-sm text-text-primary">
            {c.done}
          </p>
        ) : (
          <form
            data-testid="login-help-form"
            className="space-y-4"
            onSubmit={(e) => { e.preventDefault(); submit() }}
          >
            <p className="text-sm leading-relaxed text-text-secondary">{c.description}</p>

            <TextInput
              id="help-name"
              label={c.name}
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
              error={error?.errors?.name?.[0]}
            />
            <EmailInput
              id="help-email"
              label={c.email}
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              error={error?.errors?.email?.[0]}
            />
            <PhoneField
              id="help-phone"
              label={`${c.phone} — ${c.phoneOptional}`}
              value={phone}
              onChange={(v) => { setPhone(v); setPhoneError(null) }}
              dialCode={dialCode}
              onDialCodeChange={setDialCode}
              ar={ar}
              error={phoneError ?? error?.errors?.phone?.[0]}
            />

            <label className="block">
              <span className="mb-1.5 block text-sm font-semibold text-text-secondary">{c.topic}</span>
              <Select
                id="help-topic"
                value={topic}
                onChange={(e) => setTopic(e.target.value as ContactTopic)}
                options={TOPICS.map((key) => ({ value: key, label: c.topics[key] }))}
              />
            </label>

            <TextareaField
              id="help-details"
              label={`${c.details} — ${c.detailsOptional}`}
              rows={3}
              value={details}
              onChange={(e) => setDetails(e.target.value)}
              error={error?.errors?.message?.[0]}
            />

            {/* Off-screen rather than `display:none`: some bots skip what is not laid out at all. */}
            <input
              type="text"
              tabIndex={-1}
              autoComplete="off"
              aria-hidden="true"
              className="absolute h-px w-px overflow-hidden opacity-0"
              value={website}
              onChange={(e) => setWebsite(e.target.value)}
            />

            {error && !error.errors && (
              <p data-testid="login-help-error" role="alert" className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">
                {error.message}
              </p>
            )}

            <div className="flex items-center justify-end gap-2 pt-1">
              <Button type="button" variant="ghost" onClick={close}>{c.cancel}</Button>
              <Button type="submit" loading={send.isPending}>{c.submit}</Button>
            </div>
          </form>
        )}
      </Modal>
    </>
  )
}
