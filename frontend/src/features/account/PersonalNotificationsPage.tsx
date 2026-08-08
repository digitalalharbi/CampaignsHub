import { Bell } from 'lucide-react'
import { NotificationsTab } from '@/features/settings/tabs/NotificationsTab'
import { useUi } from '@/stores/ui'

/**
 * Personal notification preferences — the page every email's «إدارة التفضيلات» link opens.
 *
 * ## Why this is now four lines and a header
 *
 * There were two screens editing the same `notification_preferences` row, and they had drifted into
 * a state where each could do something the other could not. This one had the digests, the receiving
 * hour, the timezone and the language; the settings tab had the category switches and the quiet
 * hours — and it PUT a body that omitted this page's fields, so a person who used both surfaces lost
 * their digest by touching a checkbox on the other one.
 *
 * MAIL-011 made the preferences centre complete, so the honest fix is one implementation at both
 * routes rather than a third variant. `MailLinks` sends every unsubscribe link here, which makes this
 * the surface that has to be complete — nobody arriving from an email footer should find a subset.
 */
export function PersonalNotificationsPage() {
  const ar = useUi((s) => s.locale) === 'ar'

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-col gap-1">
        <h1 className="flex items-center gap-2 text-2xl font-extrabold tracking-tight text-text-primary">
          <Bell size={20} /> {ar ? 'الإشعارات الشخصية' : 'Personal notifications'}
        </h1>
        <p className="text-sm text-text-secondary">
          {ar
            ? 'اختر كيف ومتى تصلك إشعاراتك أنت — لا تؤثر على بقية الفريق.'
            : 'Choose how and when YOU get notified — this does not affect your teammates.'}
        </p>
      </header>

      <NotificationsTab />

      <p className="text-xs text-text-tertiary">
        {ar
          ? 'التسليم صادق: لا تُسجَّل رسالة كـ«مُرسلة» قبل ربط مزوّد حقيقي.'
          : 'Honest delivery: nothing is logged as “sent” before a real provider is wired.'}
      </p>
    </div>
  )
}
