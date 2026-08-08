import { useTeamNotifications, type TeamNotificationPerson } from '../api'
import { CATEGORY_LABELS, words } from '../messageLabels'
import { Alert } from '@/components/ui/Alert'
import { Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

/**
 * Which portal somebody belongs to.
 *
 * Found by opening the screen: a client contact appeared under a heading that says «الفريق». They
 * belong on this board — they receive report and billing messages, and «the client has every email
 * switched off» is what it exists to surface — but a row that does not say so reads as an agency
 * colleague nobody recognises.
 */
const PORTALS: Record<string, { ar: string; en: string }> = {
  // The values are the `Portal` enum's, not a guess — `portal` is the client portal and `app` is the
  // advertiser one, which is exactly the pair a guess gets backwards.
  agency: { ar: 'الوكالة', en: 'Agency' },
  app: { ar: 'المعلن', en: 'Advertiser' },
  portal: { ar: 'بوابة العميل', en: 'Client portal' },
  influencers: { ar: 'المؤثرين', en: 'Influencers' },
}

/** The one word for what is happening to this person's email, and what it means. */
const STATES: Record<string, { ar: string; en: string; tone: string }> = {
  sent: { ar: 'وصلت آخر رسالة', en: 'Last message delivered', tone: 'text-success' },
  sandbox: { ar: 'وضع الاختبار', en: 'Sandbox', tone: 'text-warning' },
  awaiting_credentials: { ar: 'بانتظار ربط مزوّد البريد', en: 'Awaiting a mail provider', tone: 'text-warning' },
  failed: { ar: 'تعذّر الإرسال', en: 'Sending failed', tone: 'text-danger' },
  never_sent: { ar: 'لم يُرسل شيء بعد', en: 'Nothing sent yet', tone: 'text-text-muted' },
  silent: { ar: 'لا يصله شيء', en: 'Receives nothing', tone: 'text-warning' },
}

const STATE_NOTES: Record<string, { ar: string; en: string }> = {
  silent: {
    ar: 'أوقف هذا العضو كل أنواع البريد ولم يشترك في أي ملخص، فلن تصله أي رسالة مهما حدث.',
    en: 'This member has every kind of email off and no summary chosen, so nothing will reach them whatever happens.',
  },
  never_sent: {
    ar: 'مشترك فعلًا، ولم يحدث بعد ما يستحق رسالة.',
    en: 'Subscribed, and nothing has happened yet that was worth sending.',
  },
}

/**
 * Who on the team is actually being told anything — MAIL-012.
 *
 * ## The two states that look the same and are not
 *
 * «لا يصله شيء» and «لم يُرسل شيء بعد» both show as an empty inbox, and the two are opposite
 * problems: one is a settings mistake somebody should fix, the other is an ordinary quiet week. A
 * table that printed «—» for both would be read as the first every time, and a manager would go
 * looking for a bug that is not there.
 *
 * ## Why «بانتظار ربط مزوّد البريد» is stated at the top and not in every row
 *
 * With no provider wired, every attempt records that state. Twenty rows of the same warning reads as
 * twenty problems; one sentence above the table reads as the one configuration step it is.
 */
export function TeamNotifications() {
  const ar = useUi((u) => u.locale) === 'ar'
  const { data, isLoading } = useTeamNotifications()

  if (isLoading) return <div className="space-y-3"><Skeleton className="h-10" /><Skeleton className="h-48" /></div>

  const people = data?.people ?? []
  const state = (p: TeamNotificationPerson) => STATES[p.state] ?? { ar: p.state, en: p.state, tone: 'text-text-muted' }

  return (
    <div className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
      <h2 className="text-xl font-bold text-text-primary">{ar ? 'إشعارات الفريق' : 'Team notifications'}</h2>
      <p className="mt-1 max-w-2xl text-sm leading-7 text-text-secondary">
        {ar
          ? 'ما يصل كل عضو فعلًا، وآخر رسالة أُرسلت إليه. تظهر هنا أسماء من تشترك معهم في مشروع واحد على الأقل.'
          : 'What each member actually receives, and the last message sent to them. Only people you share at least one project with are listed.'}
      </p>

      {data?.email_provider_configured === false && (
        <div className="mt-4">
          <Alert severity="warning" title={ar ? 'لا يوجد مزوّد بريد مربوط' : 'No mail provider is wired'}>
            {ar
              ? 'تُسجَّل كل محاولة إرسال بحالة «بانتظار ربط مزوّد البريد» ولا يغادر شيء فعليًا. الإعدادات أدناه صحيحة وتعمل فور ربط المزوّد.'
              : 'Every attempt is recorded as awaiting credentials and nothing actually leaves. The settings below are correct and take effect the moment a provider is wired.'}
          </Alert>
        </div>
      )}

      {people.length === 0 ? (
        <p className="mt-6 rounded-xl bg-surface-subtle px-4 py-6 text-center text-sm text-text-secondary">
          {ar ? 'لا يوجد أعضاء تشترك معهم في مشروع.' : 'Nobody here shares a project with you.'}
        </p>
      ) : (
        <div className="mt-6 overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr className="border-b border-border text-text-muted">
                <th className="p-2 text-start">{ar ? 'العضو' : 'Member'}</th>
                <th className="p-2 text-start">{ar ? 'المشاريع' : 'Projects'}</th>
                <th className="p-2 text-start">{ar ? 'ما يصله' : 'Receives'}</th>
                <th className="p-2 text-start">{ar ? 'الملخصات' : 'Summaries'}</th>
                <th className="p-2 text-start">{ar ? 'آخر رسالة' : 'Last message'}</th>
                <th className="p-2 text-start">{ar ? 'الحالة' : 'Status'}</th>
              </tr>
            </thead>
            <tbody>
              {people.map((p) => (
                <tr key={p.user_id} className="border-b border-border align-top last:border-0">
                  <td className="p-2">
                    <div className="font-medium text-text-primary">{p.name}</div>
                    <div className="text-[13px] text-text-muted" dir="ltr">{p.email}</div>
                    <div className="text-[13px] text-text-muted">
                      {[...p.roles, PORTALS[p.portal] ? (ar ? PORTALS[p.portal].ar : PORTALS[p.portal].en) : null]
                        .filter(Boolean).join(' · ')}
                    </div>
                  </td>

                  <td className="max-w-[220px] p-2 text-[13px] leading-6 text-text-primary">
                    {p.projects.slice(0, 3).join('، ')}
                    {p.projects.length > 3 && (
                      <span className="text-text-muted">
                        {ar ? ` و${p.projects.length - 3} غيرها` : ` and ${p.projects.length - 3} more`}
                      </span>
                    )}
                  </td>

                  <td className="max-w-[200px] p-2 text-[13px] leading-6 text-text-primary">
                    {p.categories.length === 0
                      ? <span className="text-text-muted">{ar ? 'لا شيء' : 'Nothing'}</span>
                      : p.categories.map((c) => words(CATEGORY_LABELS, c, ar)).join('، ')}
                    {p.arranged_by_manager && (
                      <div className="text-[13px] text-text-muted">
                        {ar ? 'مضاف من قائمة المستلمين' : 'On a recipient list'}
                      </div>
                    )}
                  </td>

                  <td className="p-2 text-[13px] leading-6 text-text-primary">
                    {[
                      p.rhythms.daily && (ar ? 'يومي' : 'Daily'),
                      p.rhythms.weekly && (ar ? 'أسبوعي' : 'Weekly'),
                      p.rhythms.alerts && (ar ? 'تنبيهات فورية' : 'Immediate alerts'),
                    ].filter(Boolean).join('، ') || <span className="text-text-muted">—</span>}
                  </td>

                  <td className="p-2 text-[13px] leading-6 text-text-primary">
                    {p.last_message === null ? (
                      <span className="text-text-muted">—</span>
                    ) : (
                      <>
                        <div dir="ltr" className="text-text-primary">{(p.last_message.at ?? '').slice(0, 16).replace('T', ' ')}</div>
                        <div className="text-text-muted">{p.last_message.kind}</div>
                      </>
                    )}
                  </td>

                  <td className="max-w-[240px] p-2">
                    <span className={`text-[13px] font-semibold ${state(p).tone}`}>{ar ? state(p).ar : state(p).en}</span>
                    {STATE_NOTES[p.state] && (
                      <p className="text-[13px] leading-6 text-text-secondary">
                        {ar ? STATE_NOTES[p.state].ar : STATE_NOTES[p.state].en}
                      </p>
                    )}
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
