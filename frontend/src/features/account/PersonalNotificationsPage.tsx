import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell } from 'lucide-react'
import { getData, putData } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * Personal notification preferences — a USER setting (account menu only). Wired to the real
 * GET/PUT /settings/notifications endpoint (channels, quiet hours, per-category delivery).
 */
interface NotifPrefs {
  channels: { in_app: boolean; email: boolean }
  quiet_hours: { enabled: boolean; start: string; end: string }
  categories: Record<string, { in_app: boolean; email: boolean }>
  available_categories?: string[]
}

const COPY = {
  ar: {
    title: 'الإشعارات الشخصية', subtitle: 'اختر كيف ومتى تصلك إشعاراتك أنت — لا تؤثر على بقية الفريق.',
    channels: 'القنوات', in_app: 'داخل التطبيق', email: 'البريد الإلكتروني',
    quiet: 'ساعات الهدوء', quiet_enabled: 'تفعيل ساعات الهدوء', from: 'من', to: 'إلى',
    categories: 'حسب النوع', loading: 'جارٍ التحميل…', error: 'تعذّر تحميل التفضيلات.', saved: 'تم الحفظ',
    honest: 'التسليم صادق: لا تُسجَّل رسالة كـ«مُرسلة» قبل ربط مزوّد حقيقي.',
  },
  en: {
    title: 'Personal notifications', subtitle: 'Choose how and when YOU get notified — this does not affect your teammates.',
    channels: 'Channels', in_app: 'In-app', email: 'Email',
    quiet: 'Quiet hours', quiet_enabled: 'Enable quiet hours', from: 'From', to: 'To',
    categories: 'By category', loading: 'Loading…', error: 'Could not load preferences.', saved: 'Saved',
    honest: 'Honest delivery: nothing is logged as "sent" before a real provider is wired.',
  },
}

export function PersonalNotificationsPage() {
  const locale = useUi((s) => s.locale)
  const c = COPY[locale]
  const qc = useQueryClient()

  const q = useQuery({ queryKey: ['settings', 'notifications'], queryFn: () => getData<NotifPrefs>('/settings/notifications') })
  const saveM = useMutation({
    mutationFn: (p: NotifPrefs) => putData('/settings/notifications', p),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['settings', 'notifications'] }),
  })

  const p = q.data
  const set = (patch: Partial<NotifPrefs>) => p && saveM.mutate({ ...p, ...patch })

  return (
    <div className="flex flex-col gap-5">
      <header className="flex flex-col gap-1">
        <h1 className="flex items-center gap-2 text-2xl font-extrabold tracking-tight text-text-primary">
          <Bell size={20} /> {c.title}
        </h1>
        <p className="text-sm text-text-secondary">{c.subtitle}</p>
      </header>

      {q.isLoading ? (
        <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{c.loading}</p>
      ) : q.isError || !p ? (
        <p className="rounded-xl border border-danger/30 bg-danger/5 p-8 text-center text-sm text-danger">{c.error}</p>
      ) : (
        <>
          <section className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5">
            <h2 className="text-sm font-bold text-text-primary">{c.channels}</h2>
            <label className="flex items-center gap-2 text-sm text-text-secondary">
              <input type="checkbox" checked={p.channels.in_app}
                onChange={(e) => set({ channels: { ...p.channels, in_app: e.target.checked } })} /> {c.in_app}
            </label>
            <label className="flex items-center gap-2 text-sm text-text-secondary">
              <input type="checkbox" checked={p.channels.email}
                onChange={(e) => set({ channels: { ...p.channels, email: e.target.checked } })} /> {c.email}
            </label>
          </section>

          <section className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5">
            <h2 className="text-sm font-bold text-text-primary">{c.quiet}</h2>
            <label className="flex items-center gap-2 text-sm font-semibold text-text-primary">
              <input type="checkbox" checked={p.quiet_hours.enabled}
                onChange={(e) => set({ quiet_hours: { ...p.quiet_hours, enabled: e.target.checked } })} /> {c.quiet_enabled}
            </label>
            <div className="flex flex-wrap items-center gap-3">
              <label className="flex items-center gap-1.5 text-xs text-text-secondary">{c.from}
                <input type="time" lang="en-CA" dir="ltr" value={p.quiet_hours.start}
                  onChange={(e) => set({ quiet_hours: { ...p.quiet_hours, start: e.target.value } })}
                  className="rounded-lg border border-border bg-background px-2 py-1 text-sm text-text-primary" />
              </label>
              <label className="flex items-center gap-1.5 text-xs text-text-secondary">{c.to}
                <input type="time" lang="en-CA" dir="ltr" value={p.quiet_hours.end}
                  onChange={(e) => set({ quiet_hours: { ...p.quiet_hours, end: e.target.value } })}
                  className="rounded-lg border border-border bg-background px-2 py-1 text-sm text-text-primary" />
              </label>
            </div>
          </section>

          {(p.available_categories?.length ?? 0) > 0 && (
            <section className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5">
              <h2 className="text-sm font-bold text-text-primary">{c.categories}</h2>
              <ul className="flex flex-col gap-2">
                {p.available_categories!.map((cat) => {
                  const row = p.categories[cat] ?? { in_app: true, email: false }
                  return (
                    <li key={cat} className="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-2 last:border-0">
                      <span className="text-sm text-text-primary">{cat}</span>
                      <span className="flex items-center gap-3">
                        <label className="flex items-center gap-1.5 text-xs text-text-secondary">
                          <input type="checkbox" checked={row.in_app}
                            onChange={(e) => set({ categories: { ...p.categories, [cat]: { ...row, in_app: e.target.checked } } })} /> {c.in_app}
                        </label>
                        <label className="flex items-center gap-1.5 text-xs text-text-secondary">
                          <input type="checkbox" checked={row.email}
                            onChange={(e) => set({ categories: { ...p.categories, [cat]: { ...row, email: e.target.checked } } })} /> {c.email}
                        </label>
                      </span>
                    </li>
                  )
                })}
              </ul>
            </section>
          )}

          <p className="text-xs text-text-tertiary">{c.honest}</p>
          {saveM.isSuccess && <span className="text-xs font-semibold text-success">{c.saved}</span>}
        </>
      )}
    </div>
  )
}
