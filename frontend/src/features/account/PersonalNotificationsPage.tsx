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
  /**
   * MAIL-004 — the digests, the clock they arrive on, and the language they arrive in.
   *
   * Both digests default to FALSE on the server. A summary that arrives because nobody turned it
   * off is a mailing list, not a preference.
   */
  /** Which rhythms may reach this inbox. `alerts` is not a rhythm — it is «when something needs a decision». */
  digests: { daily: boolean; weekly: boolean; alerts: boolean }
  timezone: string
  locale: 'ar' | 'en'
  digest_hour: number
  available_timezones?: string[]
}

const COPY = {
  ar: {
    title: 'الإشعارات الشخصية', subtitle: 'اختر كيف ومتى تصلك إشعاراتك أنت — لا تؤثر على بقية الفريق.',
    channels: 'القنوات', in_app: 'داخل التطبيق', email: 'البريد الإلكتروني',
    quiet: 'ساعات الهدوء', quiet_enabled: 'تفعيل ساعات الهدوء', from: 'من', to: 'إلى',
    categories: 'حسب النوع', loading: 'جارٍ التحميل…', error: 'تعذّر تحميل التفضيلات.', saved: 'تم الحفظ',
    honest: 'التسليم صادق: لا تُسجَّل رسالة كـ«مُرسلة» قبل ربط مزوّد حقيقي.',
    digests: 'الملخصات الدورية',
    digests_hint: 'ملخص يصلك بالبريد دون الحاجة لفتح النظام — أهم ما حدث وما يستحق قرارًا.',
    daily: 'الملخص اليومي',
    daily_hint: 'إنفاق أمس ونتائجه، وأفضل وأضعف منصة، والميزانيات التي تحتاج انتباهًا.',
    weekly: 'الملخص التنفيذي الأسبوعي',
    weekly_hint: 'أداء الأسبوع عبر كل المشاريع والمنصات والاتجاهات.',
    alerts: 'التنبيهات الفورية',
    alerts_hint: 'ما لا يحتمل الانتظار حتى الغد: تجاوز الميزانية، ارتفاع التكلفة، تراجع الأداء، أو توقف المزامنة. لا يتكرر التنبيه نفسه خلال ثلاثة أيام.',
    when: 'وقت الوصول',
    hour: 'الساعة (بتوقيتك)',
    timezone: 'المنطقة الزمنية',
    language: 'لغة الرسائل',
    scope_note: 'تصلك أرقام المشاريع التي تملك صلاحية الوصول إليها فقط — ولا يضيف اختيارك مشروعًا خارج صلاحيتك.',
  },
  en: {
    title: 'Personal notifications', subtitle: 'Choose how and when YOU get notified — this does not affect your teammates.',
    channels: 'Channels', in_app: 'In-app', email: 'Email',
    quiet: 'Quiet hours', quiet_enabled: 'Enable quiet hours', from: 'From', to: 'To',
    categories: 'By category', loading: 'Loading…', error: 'Could not load preferences.', saved: 'Saved',
    honest: 'Honest delivery: nothing is logged as "sent" before a real provider is wired.',
    digests: 'Digests',
    digests_hint: 'A summary by email so you do not have to open the product — what happened, and what is worth a decision.',
    daily: 'Daily digest',
    daily_hint: 'Yesterday’s spend and results, the best and weakest platform, and any budget that needs attention.',
    weekly: 'Weekly executive digest',
    weekly_hint: 'The week across every project, platform and trend.',
    alerts: 'Immediate alerts',
    alerts_hint: 'What should not wait until tomorrow: a budget running ahead, a cost climbing, performance slipping, or a source that stopped syncing. The same finding is not repeated within three days.',
    when: 'When it arrives',
    hour: 'Hour (your local time)',
    timezone: 'Timezone',
    language: 'Email language',
    scope_note: 'You only ever receive figures for projects you may already reach — choosing one you cannot reach adds nothing.',
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
          {/*
            MAIL-004 — the digests, and the clock they arrive on.

            Placed FIRST because it is the setting that changes whether somebody has to open the
            product at all, which is the promise the rest of this feature exists to keep.
          */}
          <section className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5" data-testid="digest-preferences">
            <div>
              <h2 className="text-sm font-bold text-text-primary">{c.digests}</h2>
              <p className="mt-0.5 text-xs text-text-secondary">{c.digests_hint}</p>
            </div>

            <label className="flex items-start gap-2 text-sm text-text-secondary">
              <input
                type="checkbox"
                data-testid="digest-daily"
                className="mt-1"
                checked={p.digests?.daily ?? false}
                onChange={(e) => set({ digests: { ...p.digests, daily: e.target.checked } })}
              />
              <span>
                <span className="font-semibold text-text-primary">{c.daily}</span>
                <span className="block text-xs">{c.daily_hint}</span>
              </span>
            </label>

            <label className="flex items-start gap-2 text-sm text-text-secondary">
              <input
                type="checkbox"
                data-testid="digest-weekly"
                className="mt-1"
                checked={p.digests?.weekly ?? false}
                onChange={(e) => set({ digests: { ...p.digests, weekly: e.target.checked } })}
              />
              <span>
                <span className="font-semibold text-text-primary">{c.weekly}</span>
                <span className="block text-xs">{c.weekly_hint}</span>
              </span>
            </label>

            {/*
              MAIL-006 — the one rhythm that is not a rhythm.

              An alert arrives when something needs a decision rather than at a chosen hour, so it
              sits with the digests (same question: what may reach my inbox on its own?) and says
              plainly that it is throttled — otherwise «immediate» reads as «constant».
            */}
            <label className="flex items-start gap-2 text-sm text-text-secondary">
              <input
                type="checkbox"
                data-testid="digest-alerts"
                className="mt-1"
                checked={p.digests?.alerts ?? false}
                onChange={(e) => set({ digests: { ...p.digests, alerts: e.target.checked } })}
              />
              <span>
                <span className="font-semibold text-text-primary">{c.alerts}</span>
                <span className="block text-xs">{c.alerts_hint}</span>
              </span>
            </label>

            <div className="grid gap-3 border-t border-border pt-3 sm:grid-cols-3">
              <label className="flex flex-col gap-1 text-xs">
                <span className="font-semibold text-text-secondary">{c.hour}</span>
                <select
                  data-testid="digest-hour"
                  value={p.digest_hour ?? 8}
                  onChange={(e) => set({ digest_hour: Number(e.target.value) })}
                  className="h-9 rounded-xl border border-border bg-surface px-2 text-sm text-text-primary"
                  dir="ltr"
                >
                  {Array.from({ length: 24 }, (_, h) => (
                    <option key={h} value={h}>{String(h).padStart(2, '0')}:00</option>
                  ))}
                </select>
              </label>

              <label className="flex flex-col gap-1 text-xs">
                <span className="font-semibold text-text-secondary">{c.timezone}</span>
                <select
                  data-testid="digest-timezone"
                  value={p.timezone ?? 'Asia/Riyadh'}
                  onChange={(e) => set({ timezone: e.target.value })}
                  className="h-9 rounded-xl border border-border bg-surface px-2 text-sm text-text-primary"
                  dir="ltr"
                >
                  {(p.available_timezones ?? [p.timezone ?? 'Asia/Riyadh']).map((tz) => (
                    <option key={tz} value={tz}>{tz}</option>
                  ))}
                </select>
              </label>

              <label className="flex flex-col gap-1 text-xs">
                <span className="font-semibold text-text-secondary">{c.language}</span>
                <select
                  data-testid="digest-locale"
                  value={p.locale ?? 'ar'}
                  onChange={(e) => set({ locale: e.target.value as 'ar' | 'en' })}
                  className="h-9 rounded-xl border border-border bg-surface px-2 text-sm text-text-primary"
                >
                  <option value="ar">العربية</option>
                  <option value="en">English</option>
                </select>
              </label>
            </div>

            {/*
              Stated on the page, not only enforced in the code.

              The project picker below can only NARROW what a person receives — the ceiling is their
              membership. Saying so is what stops somebody assuming they can subscribe their way into
              a client they cannot otherwise see.
            */}
            <p className="rounded-xl border border-border bg-surface-secondary px-3 py-2 text-xs text-text-secondary">
              {c.scope_note}
            </p>
          </section>

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
