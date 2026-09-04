import { useEffect, useState } from 'react'
import { useNotifPrefs, useSaveNotifPrefs, type CatalogueType, type NotifPrefs, type Rhythm } from '../api'
import { CATEGORY_LABELS, CATEGORY_NOTES, TYPE_LABELS, TYPE_NOTES, words } from '../messageLabels'
import { Switch } from '@/components/ui/Switch'
import { Select } from '@/components/ui/Select'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Checkbox } from '@/components/ui/Checkbox'
import { Button } from '@/components/ui/Button'
import { Alert } from '@/components/ui/Alert'
import { Skeleton } from '@/components/ui/States'
import { useUi } from '@/stores/ui'

const FREQ = [
  { value: 'realtime', ar: 'فوري', en: 'Immediately' },
  { value: 'hourly', ar: 'كل ساعة', en: 'Hourly' },
  { value: 'daily', ar: 'يومي', en: 'Daily' },
]

const RHYTHM_LABELS: Record<Rhythm, { ar: string; en: string }> = {
  immediate: { ar: 'فور حدوثه', en: 'As it happens' },
  daily: { ar: 'مع الملخص اليومي', en: 'With the daily summary' },
  weekly: { ar: 'مع الملخص الأسبوعي', en: 'With the weekly summary' },
  monthly: { ar: 'مع الملخص الشهري', en: 'With the monthly summary' },
}

/**
 * The preferences centre — MAIL-011.
 *
 * ## What this replaced, and why it was not merely incomplete
 *
 * Six category checkboxes and two channel switches. The digest opt-ins, the receiving hour, the
 * timezone and the language lived on a SECOND screen — `/account/notifications`, the page every
 * email's unsubscribe link opens — and the project scope had no control anywhere at all.
 *
 * Two screens editing one row is not merely untidy. This one PUT a fixed body that omitted the
 * other's fields, and the server wrote every column regardless, so ticking a checkbox here silently
 * cleared a digest somebody had switched on there. Both routes now render this component.
 *
 * ## Every switch answers «what will I actually receive, and when»
 *
 * The server sends EFFECTIVE values — what would happen today, after mandatory types, the master
 * channel switch, the person's own choices and the defaults have all been applied. Re-deriving that
 * here would put a second copy of the resolution order in TypeScript, and the two would disagree the
 * first time either changed.
 *
 * Three things are therefore stated rather than implied:
 *
 * - **Account messages have no switch**, and the reason is written beside them instead of a disabled
 *   checkbox that looks like a bug.
 * - **A rhythm is only offered where one exists.** An invoice cannot be «weekly» — nothing batches
 *   it — so those rows show no select at all rather than one whose options do nothing.
 * - **Choosing a rhythm whose digest is switched off says so.** Otherwise a person moves a message
 *   into a summary they do not receive and it silently stops arriving.
 */
export function NotificationsTab() {
  const ar = useUi((u) => u.locale) === 'ar'
  const { data, isLoading } = useNotifPrefs()
  const save = useSaveNotifPrefs()
  const [p, setP] = useState<NotifPrefs | null>(null)
  const [saved, setSaved] = useState(false)

  useEffect(() => { if (data) setP(data) }, [data])
  if (isLoading || !p) return <div className="space-y-3"><Skeleton className="h-10" /><Skeleton className="h-72" /></div>

  const label = (map: Record<string, { ar: string; en: string }>, key: string) => words(map, key, ar)

  const submit = async () => {
    /*
     * Only the settings this screen actually controls.
     *
     * `categories` is deliberately NOT sent: the older six-key map is what somebody's row already
     * says, per-type choices outrank it, and rewriting it from a screen that no longer renders it
     * would overwrite a stored choice with a derived one.
     */
    const types: NotifPrefs['types'] = {}
    for (const group of p.catalogue) {
      for (const t of group.types) {
        if (t.mandatory || t.digest_switch !== null) continue
        types[t.key] = p.types[t.key]
      }
    }

    await save.mutateAsync({
      channels: p.channels,
      types,
      quiet_hours: p.quiet_hours,
      frequency: p.frequency,
      project_ids: p.project_ids,
      digests: p.digests,
      timezone: p.timezone,
      locale: p.locale,
      digest_hour: p.digest_hour,
    })
    setSaved(true)
    setTimeout(() => setSaved(false), 2500)
  }

  const setType = (key: string, patch: Partial<{ email: boolean; in_app: boolean; rhythm: Rhythm }>) =>
    setP({ ...p, types: { ...p.types, [key]: { ...p.types[key], ...patch } } })

  const chosenProjects = p.project_ids ?? []
  const allProjects = chosenProjects.length === 0

  const toggleProject = (id: string, on: boolean) => {
    const next = on ? [...chosenProjects, id] : chosenProjects.filter((x) => x !== id)
    setP({ ...p, project_ids: next.length === 0 ? null : next })
  }

  /** A rhythm pointing at a summary this person does not receive. */
  const strandedBy = (t: CatalogueType): Rhythm | null => {
    const r = p.types[t.key]?.rhythm
    if (r === 'daily' && !p.digests.daily) return 'daily'
    if (r === 'weekly' && !p.digests.weekly) return 'weekly'
    if (r === 'monthly' && !p.digests.monthly) return 'monthly'
    return null
  }

  return (
    /*
      SETTINGS-MOBILE-OVERFLOW-001 — `min-w-0` so the table below can actually scroll itself.

      This sits inside a flex column, and a flex item's `min-width` defaults to `auto` — meaning it
      refuses to shrink below its widest child. Its widest child is a `min-w-[560px]` table, so on a
      375px phone this block was 461px and took the page sideways with it. The table already had an
      `overflow-x-auto` wrapper; that wrapper could not clip anything while its ancestor was being
      sized by the very content it was meant to contain.
    */
    <div className="min-w-0 space-y-6">
      <div className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
        <h2 className="text-xl font-bold text-text-primary">{ar ? 'تفضيلات الإشعارات' : 'Notification preferences'}</h2>
        <p className="mt-1 max-w-2xl text-sm leading-7 text-text-secondary">
          {ar
            ? 'اختر ما يصلك، وعلى أي قناة، ومتى. ما تختاره هنا يخصّك وحدك ولا يغيّر ما يصل زملاءك.'
            : 'Choose what reaches you, on which channel, and when. These are yours alone and change nothing for your colleagues.'}
        </p>
        {saved && <div className="mt-4"><Alert severity="positive" title={ar ? 'تم حفظ التفضيلات' : 'Preferences saved'} /></div>}

        <div className="mt-5 flex flex-wrap gap-6 border-t border-border pt-5">
          <Switch
            checked={p.channels.in_app}
            onCheckedChange={(v) => setP({ ...p, channels: { ...p.channels, in_app: v } })}
            label={ar ? 'إشعارات داخل النظام' : 'In-app notifications'}
          />
          <Switch
            checked={p.channels.email}
            onCheckedChange={(v) => setP({ ...p, channels: { ...p.channels, email: v } })}
            label={ar ? 'البريد الإلكتروني' : 'Email'}
          />
        </div>
        {!p.channels.email && (
          <p className="mt-3 text-[13px] leading-6 text-warning">
            {ar
              ? 'البريد مغلق كليًا، لذلك لن تصلك أي رسالة بريد مهما كان المحدد أدناه — عدا رسائل الحساب والأمان.'
              : 'Email is off entirely, so nothing below will reach your inbox — apart from account and security messages.'}
          </p>
        )}
      </div>

      {/* ── الرسائل، مصنّفة ───────────────────────────────────────────────────────────────── */}
      {p.catalogue.map((group) => (
        <div key={group.key} className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
          <h3 className="text-base font-bold text-text-primary">{label(CATEGORY_LABELS, group.key)}</h3>
          <p className="mt-1 max-w-2xl text-[13px] leading-6 text-text-secondary">{label(CATEGORY_NOTES, group.key)}</p>

          <div className="mt-4 overflow-x-auto">
            <table className="w-full min-w-[560px] text-sm">
              <thead>
                <tr className="border-b border-border text-text-muted">
                  <th className="p-2 text-start">{ar ? 'الرسالة' : 'Message'}</th>
                  <th className="p-2 text-center">{ar ? 'داخل النظام' : 'In-app'}</th>
                  <th className="p-2 text-center">{ar ? 'بريد' : 'Email'}</th>
                  <th className="p-2 text-start">{ar ? 'التوقيت' : 'When'}</th>
                </tr>
              </thead>
              <tbody>
                {group.types.map((t) => {
                  const value = p.types[t.key] ?? { email: false, in_app: true, rhythm: 'immediate' as Rhythm }
                  const stranded = strandedBy(t)
                  const digest = t.digest_switch

                  return (
                    <tr key={t.key} className="border-b border-border align-top last:border-0">
                      <td className="p-2">
                        <div className="font-medium text-text-primary">{label(TYPE_LABELS, t.key)}</div>
                        {TYPE_NOTES[t.key] && (
                          <p className="mt-0.5 max-w-md text-[13px] leading-6 text-text-muted">{label(TYPE_NOTES, t.key)}</p>
                        )}
                      </td>

                      {t.mandatory ? (
                        <td colSpan={3} className="p-2 text-[13px] leading-6 text-text-secondary">
                          {ar ? 'تُرسل دائمًا عند الحاجة إليها.' : 'Always sent whenever it applies.'}
                        </td>
                      ) : digest !== null ? (
                        <>
                          <td className="p-2 text-center text-[13px] text-text-muted">—</td>
                          <td className="p-2 text-center">
                            <Checkbox
                              id={`digest-${digest}`}
                              aria-label={label(TYPE_LABELS, t.key)}
                              checked={p.digests[digest]}
                              onChange={(e) => setP({ ...p, digests: { ...p.digests, [digest]: e.target.checked } })}
                            />
                          </td>
                          <td className="p-2 text-[13px] text-text-muted">
                            {/*
                              Three rhythms, not two. This was `daily ? … : weekly`, so the MONTHLY
                              row — which the sender has dispatched since EMAIL-INTELLIGENCE-001 —
                              announced «Weekly, Monday morning»: a false statement about when a
                              reader's mail arrives, printed on the screen they opened to find out.
                              The monthly day is chosen per recipient and does not reach this screen,
                              so it is not named; what is stated is what is true of every recipient.
                            */}
                            {digest === 'daily'
                              ? (ar ? `يوميًا، الساعة ${p.digest_hour}:00` : `Daily, at ${p.digest_hour}:00`)
                              : digest === 'monthly'
                                ? (ar ? 'شهريًا، عن الشهر المنتهي' : 'Monthly, for the finished month')
                                : (ar ? 'أسبوعيًا، صباح الاثنين' : 'Weekly, Monday morning')}
                          </td>
                        </>
                      ) : (
                        <>
                          <td className="p-2 text-center">
                            <Checkbox
                              id={`in-app-${t.key}`}
                              aria-label={`${label(TYPE_LABELS, t.key)} — ${ar ? 'داخل النظام' : 'in-app'}`}
                              checked={value.in_app}
                              onChange={(e) => setType(t.key, { in_app: e.target.checked })}
                            />
                          </td>
                          <td className="p-2 text-center">
                            <Checkbox
                              id={`email-${t.key}`}
                              aria-label={`${label(TYPE_LABELS, t.key)} — ${ar ? 'بريد' : 'email'}`}
                              checked={value.email}
                              disabled={!p.channels.email}
                              onChange={(e) => setType(t.key, { email: e.target.checked })}
                            />
                          </td>
                          <td className="p-2">
                            {t.rhythms.length > 1 ? (
                              <>
                                <Select
                                  // Sized to its longest option rather than the column: a select
                                  // stretched across a third of the table reads as the row's subject.
                                  className="max-w-[210px]"
                                  aria-label={`${label(TYPE_LABELS, t.key)} — ${ar ? 'التوقيت' : 'when'}`}
                                  value={value.rhythm}
                                  onChange={(e) => setType(t.key, { rhythm: e.target.value as Rhythm })}
                                  options={t.rhythms.map((r) => ({ value: r, label: ar ? RHYTHM_LABELS[r].ar : RHYTHM_LABELS[r].en }))}
                                />
                                {stranded && (
                                  <p className="mt-1 max-w-xs text-[13px] leading-6 text-warning">
                                    {ar
                                      ? `لن تصلك هذه الرسالة لأن ${stranded === 'daily' ? 'الملخص اليومي' : stranded === 'weekly' ? 'الملخص الأسبوعي' : 'الملخص الشهري'} غير مفعّل لديك.`
                                      : `This will not reach you: your ${stranded} summary is switched off.`}
                                  </p>
                                )}
                              </>
                            ) : (
                              <span className="text-[13px] text-text-muted">{ar ? 'فور حدوثه' : 'As it happens'}</span>
                            )}
                          </td>
                        </>
                      )}
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      ))}

      {/* ── الملخصات والتوقيت ─────────────────────────────────────────────────────────────── */}
      <div className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
        <h3 className="text-base font-bold text-text-primary">{ar ? 'المواعيد واللغة' : 'Timing and language'}</h3>
        <p className="mt-1 max-w-2xl text-[13px] leading-6 text-text-secondary">
          {ar
            ? 'الساعة التي تختارها هي ساعتك أنت. تغيير المنطقة الزمنية يغيّر وقت الوصول ولا يغيّر ما اخترته.'
            : 'The hour you choose is your own. Changing timezone changes when mail arrives, not what you asked for.'}
        </p>

        <div className="mt-4">
          <Switch
            checked={p.digests.alerts}
            onCheckedChange={(v) => setP({ ...p, digests: { ...p.digests, alerts: v } })}
            label={ar ? 'أرسل لي التنبيهات فور حدوثها' : 'Send me alerts as they happen'}
          />
          {!p.digests.alerts && (
            <p className="mt-2 max-w-2xl text-[13px] leading-6 text-text-muted">
              {ar
                ? 'مع إيقاف هذا الخيار لن تصلك تنبيهات فورية بالبريد، وتبقى الملاحظات ظاهرة داخل النظام وفي الملخصات التي اخترتها.'
                : 'With this off, no alert reaches your inbox as it happens. The findings still appear in the product and in whichever summaries you chose.'}
            </p>
          )}
        </div>

        <div className="mt-4">
          <Switch
            checked={p.digests.recommendations}
            onCheckedChange={(v) => setP({ ...p, digests: { ...p.digests, recommendations: v } })}
            label={ar ? 'أدرج التوصيات المعتمدة في الملخص' : 'Include approved recommendations in the summary'}
          />
          {/*
            EMAIL-SETTINGS-DEPTH-001 — what this opts INTO, said plainly.

            The digest quotes recommendations somebody wrote and somebody approved; it derives no
            advice of its own. A reader deciding whether to switch this on is deciding whether a
            colleague's approved judgement should arrive in their inbox, and the sentence says that
            rather than describing a feature.
          */}
          <p className="mt-2 max-w-2xl text-[13px] leading-6 text-text-muted" data-testid="recommendations-note">
            {p.digests.recommendations
              ? (ar
                ? 'يقتبس الملخص التوصيات المعتمدة فقط، ضمن الفترة التي يغطيها. لا يُنشئ النظام توصيات من الأرقام.'
                : 'The summary quotes approved recommendations only, from the period it covers. Nothing here is generated from your figures.')
              : (ar
                ? 'مع إيقاف هذا الخيار لن تصلك التوصيات بالبريد، وتبقى ظاهرة في «التوصيات» داخل النظام.'
                : 'With this off, no recommendation reaches your inbox. They stay visible under Recommendations in the product.')}
          </p>
        </div>
        <div className="mt-5 grid gap-4 sm:grid-cols-3">
          <Field label={ar ? 'ساعة وصول الملخص' : 'Summary arrives at'} htmlFor="digest-hour">
            <Select
              id="digest-hour"
              value={String(p.digest_hour)}
              onChange={(e) => setP({ ...p, digest_hour: Number(e.target.value) })}
              options={Array.from({ length: 24 }, (_, h) => ({ value: String(h), label: `${h}:00` }))}
            />
          </Field>
          <Field label={ar ? 'المنطقة الزمنية' : 'Timezone'} htmlFor="tz">
            <Select
              id="tz"
              value={p.timezone}
              onChange={(e) => setP({ ...p, timezone: e.target.value })}
              options={p.available_timezones.map((t) => ({ value: t, label: t }))}
            />
          </Field>
          <Field label={ar ? 'لغة الرسائل' : 'Message language'} htmlFor="mail-locale">
            <Select
              id="mail-locale"
              value={p.locale}
              onChange={(e) => setP({ ...p, locale: e.target.value as 'ar' | 'en' })}
              options={[{ value: 'ar', label: 'العربية' }, { value: 'en', label: 'English' }]}
            />
          </Field>
        </div>

        <div className="mt-5 grid gap-4 border-t border-border pt-5 sm:grid-cols-3">
          <Field label={ar ? 'تكرار الإشعارات داخل النظام' : 'In-app frequency'} htmlFor="freq">
            <Select
              id="freq"
              value={p.frequency}
              onChange={(e) => setP({ ...p, frequency: e.target.value as NotifPrefs['frequency'] })}
              options={FREQ.map((f) => ({ value: f.value, label: ar ? f.ar : f.en }))}
            />
          </Field>
          <Field label={ar ? 'ساعات الهدوء — من' : 'Quiet hours — from'} htmlFor="qs">
            <Input id="qs" type="time" value={p.quiet_hours.start} onChange={(e) => setP({ ...p, quiet_hours: { ...p.quiet_hours, start: e.target.value } })} />
          </Field>
          <Field label={ar ? 'إلى' : 'To'} htmlFor="qe">
            <Input id="qe" type="time" value={p.quiet_hours.end} onChange={(e) => setP({ ...p, quiet_hours: { ...p.quiet_hours, end: e.target.value } })} />
          </Field>
        </div>
        <div className="mt-2">
          <Switch
            checked={p.quiet_hours.enabled}
            onCheckedChange={(v) => setP({ ...p, quiet_hours: { ...p.quiet_hours, enabled: v } })}
            label={ar ? 'تفعيل ساعات الهدوء' : 'Enable quiet hours'}
          />
          {/*
            What it does, and the exception — MAIL-013.

            Quiet hours were stored and honoured by nothing that sends mail until then, so the switch
            was a promise the product did not keep. Now that it holds alerts, the two things a reader
            needs to know are that nothing is lost and that account messages are not delayed — the
            second because somebody who assumes their security alerts are being held has been told
            something untrue about their own account.
          */}
          <p className="mt-2 max-w-2xl text-[13px] leading-6 text-text-muted">
            {ar
              ? 'خلال هذه الساعات لا تصلك التنبيهات الفورية بالبريد، وتُرسل بعد انتهائها إن كانت الملاحظة ما زالت قائمة. الساعات محسوبة بتوقيتك أنت. أما رسائل الحساب والأمان — إعادة تعيين كلمة المرور ورموز الدخول والتنبيهات الأمنية — فتصلك فورًا دائمًا.'
              : 'During these hours no alert reaches your inbox; it is sent once they end, if the finding still stands. The hours are read in your own timezone. Account and security messages — password resets, sign-in codes and security alerts — always arrive immediately.'}
          </p>
        </div>
      </div>

      {/* ── نطاق المشاريع ─────────────────────────────────────────────────────────────────── */}
      <div className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]">
        <h3 className="text-base font-bold text-text-primary">{ar ? 'المشاريع' : 'Projects'}</h3>
        <p className="mt-1 max-w-2xl text-[13px] leading-6 text-text-secondary">
          {ar
            ? 'حدّد المشاريع التي تريد ملخصاتها وتنبيهاتها. القائمة تعرض ما تملك صلاحية الاطلاع عليه فقط، والاختيار يضيّق ولا يوسّع.'
            : 'Choose which projects you want summaries and alerts about. The list shows only what you may already see, and choosing narrows — it never widens.'}
        </p>

        {p.projects.length === 0 ? (
          <p className="mt-4 rounded-xl bg-surface-secondary px-4 py-6 text-center text-sm text-text-secondary">
            {ar ? 'لا توجد مشاريع تصل إليها بعد.' : 'No projects you can reach yet.'}
          </p>
        ) : (
          <div className="mt-4 space-y-3">
            <Checkbox
              id="all-projects"
              label={ar ? 'كل المشاريع التي أصل إليها' : 'Every project I can reach'}
              checked={allProjects}
              onChange={(e) => setP({ ...p, project_ids: e.target.checked ? null : p.projects.map((x) => x.id) })}
            />
            {!allProjects && (
              <div className="grid gap-2 sm:grid-cols-2">
                {p.projects.map((project) => (
                  <Checkbox
                    key={project.id}
                    id={`project-${project.id}`}
                    // Client-qualified: project names are only unique inside a client (MAIL-010).
                    label={project.client_name ? `${project.client_name} · ${project.name}` : project.name}
                    checked={chosenProjects.includes(project.id)}
                    onChange={(e) => toggleProject(project.id, e.target.checked)}
                  />
                ))}
              </div>
            )}
          </div>
        )}
      </div>

      <div className="flex justify-end">
        <Button onClick={submit} disabled={save.isPending}>
          {save.isPending ? (ar ? 'جارٍ الحفظ…' : 'Saving…') : (ar ? 'حفظ التفضيلات' : 'Save preferences')}
        </Button>
      </div>
    </div>
  )
}
