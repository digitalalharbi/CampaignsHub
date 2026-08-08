import { useState } from 'react'
import { Trash2 } from 'lucide-react'
import {
  useAssignableRecipients, useNotifRecipientActions, useNotifRecipients,
} from '../api'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Select } from '@/components/ui/Select'
import { Skeleton } from '@/components/ui/States'
import { Alert } from '@/components/ui/Alert'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
// The same words the preferences centre uses — one vocabulary, or a manager arranges «المزامنة»
// while the recipient's own screen calls it «التكاملات» (MAIL-011).
import { CATEGORY_LABELS, words } from '../messageLabels'


/**
 * Why an arrangement is doing nothing, in words a manager can act on.
 *
 * Never «غير مؤهل». The reason decides what they do next, and the three are different jobs: grant
 * access in the team screen, ask the person to turn the category back on, or fix the account.
 */
const REASONS: Record<string, { ar: string; en: string }> = {
  outside_their_access: {
    ar: 'لا يصل هذا العضو إلى هذا المشروع، لذلك لن تصله هذه الرسائل. امنحه الوصول من صفحة الفريق أولًا.',
    en: 'This member cannot reach this project, so these messages will not arrive. Grant them access from the team page first.',
  },
  switched_off_by_recipient: {
    ar: 'أوقف هذا العضو هذا النوع من رسائل البريد في إعداداته، ولا يمكن تجاوز ذلك من هنا.',
    en: 'This member has switched this kind of email off in their own settings, and that cannot be overridden here.',
  },
  no_email: { ar: 'لا يوجد بريد إلكتروني لهذا العضو.', en: 'This member has no email address.' },
  no_such_user: { ar: 'لم يعد هذا الحساب موجودًا.', en: 'This account no longer exists.' },
}

/**
 * Arranging who is told — MAIL-010.
 *
 * ## The sentence at the top is the feature
 *
 * Everything on this screen is a REQUEST, not a permission, and a manager who does not know that
 * will use it as one — adding a colleague to a client's alerts and assuming they now have access.
 * They do not: the server resolves every recipient against their own live access when the message is
 * sent. Saying so once, plainly, above the table is cheaper than the support conversation.
 *
 * ## Inert rows are shown as inert
 *
 * A row whose recipient has since been moved off the client stays in the list — the manager's intent
 * survives a temporary revocation — and is marked, with the reason. A list that looks correct and
 * mails nobody is worse than no list at all.
 */
export function NotificationRecipients() {
  const ar = useUi((u) => u.locale) === 'ar'
  const { data, isLoading } = useNotifRecipients()
  const { data: assignable } = useAssignableRecipients()
  const actions = useNotifRecipientActions()

  const [userId, setUserId] = useState('')
  const [projectId, setProjectId] = useState('')
  const [category, setCategory] = useState('')

  const error = actions.add.isError ? toApiError(actions.add.error) : null

  if (isLoading) return <div className="space-y-3"><Skeleton className="h-10" /><Skeleton className="h-48" /></div>

  const rows = data?.recipients ?? []
  const people = assignable?.people ?? []
  const projects = assignable?.projects ?? []

  /*
   * The project list narrows to the chosen person.
   *
   * The server refuses a project the recipient cannot reach, and a picker that offers the choice
   * anyway is one that teaches people to expect a refusal. `project_ids` already carries the overlap
   * of the actor's reach and theirs.
   */
  const chosen = people.find((p) => String(p.user_id) === userId)
  const offerable = chosen ? projects.filter((p) => chosen.project_ids.includes(p.id)) : projects

  const submit = async () => {
    if (userId === '') return
    await actions.add.mutateAsync({
      user_id: Number(userId),
      project_id: projectId === '' ? null : projectId,
      category: category === '' ? null : category,
    })
    setProjectId(''); setCategory('')
  }

  const label = (c: string) => words(CATEGORY_LABELS, c, ar)

  return (
    <div className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
      <h2 className="text-xl font-bold text-text-primary">{ar ? 'من يصله التنبيه' : 'Who receives alerts'}</h2>
      <p className="mt-1 max-w-2xl text-sm leading-7 text-text-secondary">
        {ar
          ? 'هنا تحدد من في فريقك يصله تنبيه عن مشروع معيّن. هذا ترتيب للإشعارات وليس منحًا للصلاحيات: لا يصل أي عضو إلى بيانات مشروع لا يملك صلاحية الاطلاع عليه، حتى لو أُضيف هنا.'
          : 'This is where you choose who on your team is told about a project. It arranges notifications and does not grant access: nobody receives figures for a project they cannot already see, even if they are added here.'}
      </p>
      {/*
        Stated, because the omission would otherwise read as a bug.

        This screen arranges ALERTS — «something happened, and it needs a decision». The daily and
        weekly summaries stay opt-in, chosen by each person above: a rhythm somebody never asked for
        is a mailing list, and being added to one by a colleague is exactly how a useful digest turns
        into a spam report.
      */}
      <p className="mt-2 max-w-2xl text-[13px] leading-6 text-text-muted">
        {ar
          ? 'يشمل هذا الترتيب التنبيهات الفورية. أما الملخص اليومي والأسبوعي فيبقى اختيار كل عضو من إعداداته أعلاه.'
          : 'This covers immediate alerts. The daily and weekly summaries stay each person’s own choice, in their settings above.'}
      </p>

      <div className="mt-5 grid gap-4 sm:grid-cols-4">
        <Field label={ar ? 'العضو' : 'Member'} htmlFor="rec-user">
          <Select
            id="rec-user" value={userId} onChange={(e) => { setUserId(e.target.value); setProjectId('') }}
            options={[
              { value: '', label: ar ? 'اختر عضوًا' : 'Choose a member' },
              ...people.map((p) => ({ value: String(p.user_id), label: `${p.name} — ${p.email}` })),
            ]}
          />
        </Field>
        <Field label={ar ? 'المشروع' : 'Project'} htmlFor="rec-project">
          <Select
            id="rec-project" value={projectId} onChange={(e) => setProjectId(e.target.value)}
            options={[
              { value: '', label: ar ? 'كل ما يصل إليه' : 'Everything they can see' },
              // Qualified by client: three clients each have a «Q3 Launch», and an unqualified
              // list offers the same words three times.
              ...offerable.map((p) => ({ value: p.id, label: p.client_name ? `${p.client_name} · ${p.name}` : p.name })),
            ]}
          />
        </Field>
        <Field label={ar ? 'النوع' : 'Kind'} htmlFor="rec-category">
          <Select
            id="rec-category" value={category} onChange={(e) => setCategory(e.target.value)}
            options={[
              { value: '', label: ar ? 'كل الأنواع' : 'Every kind' },
              ...(data?.available_categories ?? []).map((c) => ({ value: c, label: label(c) })),
            ]}
          />
        </Field>
        <div className="flex items-end">
          <Button onClick={submit} disabled={userId === '' || actions.add.isPending} className="w-full">
            {actions.add.isPending ? (ar ? 'جارٍ الإضافة…' : 'Adding…') : (ar ? 'إضافة' : 'Add')}
          </Button>
        </div>
      </div>

      {error && (
        <div className="mt-4">
          <Alert severity="danger" title={error.message} />
        </div>
      )}

      {rows.length === 0 ? (
        <p className="mt-6 rounded-xl bg-surface-subtle px-4 py-6 text-center text-sm text-text-secondary">
          {ar
            ? 'لا يوجد ترتيب بعد. يتلقى كل عضو ما اشترك فيه بنفسه من صفحة إشعاراته.'
            : 'Nothing arranged yet. Each member still receives whatever they subscribed to themselves.'}
        </p>
      ) : (
        <div className="mt-6 overflow-x-auto">
          <table className="w-full min-w-[560px] text-sm">
            <thead>
              <tr className="border-b border-border text-text-muted">
                <th className="p-2 text-start">{ar ? 'العضو' : 'Member'}</th>
                <th className="p-2 text-start">{ar ? 'المشروع' : 'Project'}</th>
                <th className="p-2 text-start">{ar ? 'النوع' : 'Kind'}</th>
                <th className="p-2 text-start">{ar ? 'الحالة' : 'Status'}</th>
                <th className="p-2" />
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id} className="border-b border-border last:border-0 align-top">
                  <td className="p-2">
                    <div className="font-medium text-text-primary">{r.name}</div>
                    <div className="text-[13px] text-text-muted" dir="ltr">{r.email}</div>
                  </td>
                  <td className="p-2 text-text-primary">{r.project_name ?? (ar ? 'كل ما يصل إليه' : 'Everything they can see')}</td>
                  <td className="p-2 text-text-primary">{r.category === null ? (ar ? 'كل الأنواع' : 'Every kind') : label(r.category)}</td>
                  <td className="p-2">
                    {r.status.eligible ? (
                      <span className="text-[13px] font-semibold text-success">{ar ? 'يستقبل' : 'Receiving'}</span>
                    ) : (
                      <div className="max-w-sm">
                        <span className="text-[13px] font-semibold text-warning">{ar ? 'لا يستقبل' : 'Not receiving'}</span>
                        <p className="text-[13px] leading-6 text-text-secondary">
                          {r.status.reason && REASONS[r.status.reason]
                            ? (ar ? REASONS[r.status.reason].ar : REASONS[r.status.reason].en)
                            : r.status.reason}
                        </p>
                      </div>
                    )}
                  </td>
                  <td className="p-2 text-end">
                    <button
                      type="button"
                      onClick={() => actions.remove.mutate(r.id)}
                      aria-label={ar ? `إزالة ${r.name}` : `Remove ${r.name}`}
                      className="rounded-lg p-2 text-text-muted hover:bg-surface-hover hover:text-danger"
                    >
                      <Trash2 size={16} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
