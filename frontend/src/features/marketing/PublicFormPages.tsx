import { useState } from 'react'
import { PublicForm, ReferenceSuccess, type PublicFormField } from './PublicForm'
import { openSupportTicket, sendContact, submitDataRequest, type DataRequestType } from './publicFormsApi'
import { CheckCircle2, ShieldAlert } from 'lucide-react'
import { useUi } from '@/stores/ui'

/**
 * LEGAL-002 — the three working forms, mounted inside their policy pages.
 *
 * Each sits on the page that explains it, rather than on a form page of its own: somebody reads what
 * a data request means and submits one without navigating away, and the explanation stays visible
 * while they fill it in.
 */

export function ContactForm() {
  const ar = useUi().locale === 'ar'

  const fields: PublicFormField[] = [
    { name: 'name', label: ar ? 'الاسم' : 'Name', required: true },
    { name: 'email', label: ar ? 'البريد الإلكتروني' : 'Email', type: 'email', required: true },
    { name: 'phone', label: ar ? 'الجوال (اختياري)' : 'Phone (optional)', type: 'tel' },
    { name: 'company', label: ar ? 'الجهة (اختياري)' : 'Company (optional)' },
    { name: 'subject', label: ar ? 'الموضوع' : 'Subject', required: true },
    { name: 'message', label: ar ? 'الرسالة' : 'Message', type: 'textarea', required: true },
  ]

  return (
    <PublicForm
      testId="contact-form"
      ar={ar}
      fields={fields}
      cta={ar ? 'إرسال' : 'Send'}
      sending={ar ? 'يُرسل…' : 'Sending…'}
      submit={(v) => sendContact({
        name: v.name ?? '', email: v.email ?? '', phone: v.phone, company: v.company,
        subject: v.subject ?? '', message: v.message ?? '', website: v.website,
      })}
      /*
       * No reference here, deliberately. There is no queue for the sender to chase — somebody reads
       * the message and replies by email — and handing back a code would promise otherwise.
       */
      renderSuccess={() => (
        <div className="rounded-2xl border border-border bg-surface p-6 text-center">
          <CheckCircle2 size={28} className="mx-auto text-success" />
          <h3 className="mt-3 font-heading text-lg font-extrabold text-text-primary">
            {ar ? 'وصلتنا رسالتك' : 'Your message reached us'}
          </h3>
          <p className="mt-1.5 text-sm text-text-secondary">
            {ar ? 'سنرد على بريدك خلال يوم عمل.' : 'We will reply to your email within one business day.'}
          </p>
        </div>
      )}
    />
  )
}

export function SupportForm() {
  const ar = useUi().locale === 'ar'

  const fields: PublicFormField[] = [
    { name: 'name', label: ar ? 'الاسم' : 'Name', required: true },
    { name: 'email', label: ar ? 'البريد الإلكتروني' : 'Email', type: 'email', required: true },
    {
      name: 'category', label: ar ? 'نوع المشكلة' : 'Type of issue', type: 'select',
      options: [
        { value: 'general', label: ar ? 'عام' : 'General' },
        { value: 'account', label: ar ? 'الحساب والدخول' : 'Account & sign-in' },
        { value: 'billing', label: ar ? 'الفوترة والاشتراك' : 'Billing & subscription' },
        { value: 'integrations', label: ar ? 'الربط والمزامنة' : 'Connections & syncing' },
        { value: 'reports', label: ar ? 'التقارير' : 'Reports' },
        { value: 'bug', label: ar ? 'خلل في المنتج' : 'Product defect' },
      ],
    },
    { name: 'subject', label: ar ? 'الموضوع' : 'Subject', required: true },
    {
      name: 'message', label: ar ? 'التفاصيل' : 'Details', type: 'textarea', required: true,
      hint: ar
        ? 'اذكر ما حاولت فعله، وما حدث، ومتى — هذا يختصر رحلة ذهاب وإياب كاملة.'
        : 'What you tried, what happened, and when — this saves an entire round trip.',
    },
  ]

  return (
    <PublicForm
      testId="support-form"
      ar={ar}
      fields={fields}
      cta={ar ? 'إنشاء تذكرة' : 'Open a ticket'}
      sending={ar ? 'يُنشأ…' : 'Opening…'}
      submit={(v) => openSupportTicket({
        name: v.name ?? '', email: v.email ?? '', subject: v.subject ?? '',
        message: v.message ?? '', category: v.category || 'general', website: v.website,
      })}
      renderSuccess={(r) => (
        <ReferenceSuccess
          ar={ar}
          reference={r.reference}
          title={ar ? 'أُنشئت تذكرتك' : 'Your ticket is open'}
          note={ar ? 'احتفظ بهذا الرقم لمتابعة التذكرة.' : 'Keep this reference to follow the ticket up.'}
        />
      )}
    />
  )
}

export function DataRequestForm() {
  const ar = useUi().locale === 'ar'
  const [type, setType] = useState<DataRequestType>('export')

  const fields: PublicFormField[] = [
    {
      name: 'type', label: ar ? 'نوع الطلب' : 'Request type', type: 'select',
      options: [
        { value: 'export', label: ar ? 'نسخة من بياناتي' : 'A copy of my data' },
        { value: 'correction', label: ar ? 'تصحيح بيانات' : 'Correct my data' },
        { value: 'delete_data', label: ar ? 'حذف بيانات محددة' : 'Delete specific data' },
        { value: 'delete_account', label: ar ? 'حذف الحساب بالكامل' : 'Delete my whole account' },
      ],
    },
    { name: 'name', label: ar ? 'الاسم' : 'Name', required: true },
    {
      name: 'email', label: ar ? 'البريد المسجَّل على الحساب' : 'The email registered on the account',
      type: 'email', required: true,
      hint: ar
        ? 'يجب أن يطابق البريد المسجَّل — لا نمنح وصولًا لمجرد تطابق بريد دون تحقق.'
        : 'It must match the registered address — we never grant access on a matching email alone.',
    },
    { name: 'details', label: ar ? 'تفاصيل (اختياري)' : 'Details (optional)', type: 'textarea', rows: 4 },
  ]

  return (
    <PublicForm
      testId="data-request-form"
      ar={ar}
      fields={fields}
      cta={ar ? 'إرسال الطلب' : 'Submit the request'}
      sending={ar ? 'يُرسل…' : 'Submitting…'}
      submit={(v) => {
        const t = (v.type || 'export') as DataRequestType
        setType(t)
        return submitDataRequest({
          type: t, name: v.name ?? '', email: v.email ?? '',
          details: v.details, website: v.website,
        })
      }}
      /*
       * The blockers are shown, not swallowed.
       *
       * A deletion the operator cannot execute yet is the case this form most has to handle well:
       * telling somebody «submitted» and leaving them to discover weeks later that an unpaid invoice
       * stopped it is the failure. Each reason arrives from the server in the reader's own language.
       */
      renderSuccess={(r) => (
        <div className="space-y-4">
          <ReferenceSuccess
            ar={ar}
            reference={r.reference}
            title={ar ? 'سُجّل طلبك' : 'Your request is recorded'}
            note={ar ? 'احتفظ بهذا الرقم لمتابعة الطلب.' : 'Keep this reference to follow the request up.'}
          />

          {r.blockers.length > 0 && (
            <div data-testid="data-request-blockers" className="rounded-2xl border border-border bg-[var(--warning-background)] p-5">
              <h4 className="flex items-center gap-2 font-heading text-base font-extrabold text-text-primary">
                <ShieldAlert size={17} />
                {ar ? 'ما يمنع التنفيذ الآن' : 'What is standing in the way'}
              </h4>
              <p className="mt-1.5 text-[13px] text-text-secondary">
                {ar
                  ? 'الطلب مسجَّل ولن يُهمل. لا يمكن تنفيذ الحذف قبل تسوية ما يلي:'
                  : 'The request is recorded and will not be dropped. Deletion cannot proceed until the following is settled:'}
              </p>
              <ul className="mt-3 space-y-2">
                {r.blockers.map((b) => (
                  <li key={b.code} className="text-[13.5px] leading-relaxed text-text-secondary">• {ar ? b.ar : b.en}</li>
                ))}
              </ul>
            </div>
          )}

          {r.blockers.length === 0 && type.startsWith('delete') && (
            <p className="text-[13px] text-text-secondary">
              {ar
                ? 'لا يوجد ما يمنع التنفيذ. سيُراجع الطلب ويُنفَّذ بعد التحقق من الهوية.'
                : 'Nothing is standing in the way. The request will be reviewed and carried out after identity is verified.'}
            </p>
          )}
        </div>
      )}
    />
  )
}
