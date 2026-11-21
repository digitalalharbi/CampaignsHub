import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BellRing, Smartphone, Trash2, Volume2 } from 'lucide-react'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Card, CardDescription, CardTitle } from '@/components/ui/Card'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Switch } from '@/components/ui/Switch'
import { api } from '@/lib/api/client'
import { installAudioUnlock, playPing } from '@/lib/alertSound'
import { useUi } from '@/stores/ui'
import {
  enablePush, getAlertSettings, getPushConfig, pushBlock, removeDevice, saveAlertSettings, testDevice,
  type AlertSettings, type PushBlock, type PushType,
} from './push'

/**
 * NOTIF-PUSH-001 / NOTIF-SOUND-001 — «الصوت وإشعارات الجوال»: the person's own sound, quiet hours, mobile switch, push types and devices.
 *
 * Nothing on this page asks the browser for permission by itself. The prompt appears only after «فعّل إشعارات الجوال» is pressed,
 * because a permission request nobody asked for gets refused, and a refusal is remembered by the browser for good.
 */
const PUSH_TYPES: ReadonlyArray<{ key: PushType; ar: string; en: string }> = [
  { key: 'critical', ar: 'تنبيه حرج', en: 'A critical alert' },
  { key: 'budget_90', ar: 'الميزانية وصلت 90%', en: 'Budget at 90%' },
  { key: 'budget_100', ar: 'الميزانية وصلت 100% أو تجاوزتها', en: 'Budget at 100% or over' },
  { key: 'sync_failed', ar: 'فشل المزامنة', en: 'A sync failed' },
  { key: 'approval', ar: 'طلب موافقة', en: 'An approval request' },
]

const BLOCKED: Record<Exclude<PushBlock, null>, { ar: string; en: string }> = {
  not_configured: {
    ar: 'إشعارات الجوال غير مفعّلة على الخادم بعد. تحتاج مفاتيح VAPID من إعدادات الخادم.',
    en: 'Mobile notifications are not set up on the server yet. They need VAPID keys in the server settings.',
  },
  ios_install: {
    ar: 'على الآيفون تشتغل الإشعارات بعد تثبيت التطبيق على الشاشة الرئيسية (iOS 16.4 أو أحدث): مشاركة ← إضافة إلى الشاشة الرئيسية، ثم افتح التطبيق من هناك وفعّلها من هذي الصفحة.',
    en: 'On iPhone, notifications work after the app is installed to the Home Screen (iOS 16.4 or newer): Share → Add to Home Screen, then open the app from there and switch them on here.',
  },
  denied: {
    ar: 'المتصفح يمنع الإشعارات لهذا الموقع. غيّر الإذن من إعدادات الموقع في المتصفح ثم ارجع هنا.',
    en: 'The browser is blocking notifications for this site. Allow them in the site settings, then come back.',
  },
  unsupported: {
    ar: 'هذا المتصفح ما يدعم إشعارات الجوال. جرّب كروم أو سفاري أو فايرفوكس المحدّث.',
    en: 'This browser does not support mobile notifications. Try an up-to-date Chrome, Safari or Firefox.',
  },
}

function when(iso: string | null, ar: boolean): string {
  if (!iso) return ar ? 'لم يُستخدم بعد' : 'Not used yet'

  return new Date(iso).toLocaleString('en-CA', { dateStyle: 'short', timeStyle: 'short' })
}

export function MobileAndSoundCard() {
  const ar = useUi((s) => s.locale) === 'ar'
  const qc = useQueryClient()
  const settings = useQuery({ queryKey: ['notifications', 'alert-settings'], queryFn: getAlertSettings })
  const push = useQuery({ queryKey: ['push', 'config'], queryFn: getPushConfig })
  const [volume, setVolume] = useState<number | null>(null)
  const [message, setMessage] = useState<{ tone: 'ok' | 'warn'; text: string } | null>(null)
  const [quiet, setQuiet] = useState<{ enabled: boolean; start: string; end: string } | null>(null)

  useEffect(() => { installAudioUnlock() }, [])

  const save = useMutation({
    meta: { feedback: 'none' },
    mutationFn: (patch: Parameters<typeof saveAlertSettings>[0]) => saveAlertSettings(patch),
    onSuccess: (d) => qc.setQueryData(['notifications', 'alert-settings'], d),
  })
  const saveQuiet = useMutation({
    meta: { feedback: 'none' },
    mutationFn: (q: { enabled: boolean; start: string; end: string }) => api.put('/settings/notifications', { quiet_hours: q }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['notifications', 'alert-settings'] })
      void qc.invalidateQueries({ queryKey: ['settings', 'notifications'] })
      setMessage({ tone: 'ok', text: ar ? 'حفظنا وقت الهدوء.' : 'Quiet hours saved.' })
    },
  })
  const enable = useMutation({
    meta: { feedback: 'none' },
    mutationFn: () => enablePush(push.data?.public_key ?? ''),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['push'] })
      setMessage({ tone: 'ok', text: ar ? 'فعّلنا إشعارات الجوال على هذا الجهاز.' : 'Mobile notifications are on for this device.' })
    },
    onError: (e: unknown) => {
      const denied = (e as { code?: string }).code === 'permission_denied'
      setMessage({ tone: 'warn', text: denied ? BLOCKED.denied[ar ? 'ar' : 'en'] : (ar ? 'ما قدرنا نفعّل الإشعارات. حاول مرة ثانية.' : 'We could not switch notifications on. Try again.') })
      void qc.invalidateQueries({ queryKey: ['push'] })
    },
  })
  const revoke = useMutation({ meta: { feedback: 'none' }, mutationFn: (id: string) => removeDevice(id), onSuccess: () => qc.invalidateQueries({ queryKey: ['push'] }) })
  const test = useMutation({
    meta: { feedback: 'none' },
    mutationFn: (id: string) => testDevice(id),
    onSuccess: (r) => setMessage({
      tone: r.data.data.delivered ? 'ok' : 'warn',
      text: r.data.data.delivered ? (ar ? 'أرسلنا إشعار تجربة لهذا الجهاز.' : 'A test notification was sent to this device.') : (ar ? 'خدمة الإشعارات ما قبلت التجربة.' : 'The push service did not accept the test.'),
    }),
  })

  // The slider saves once the person stops moving it, not on every pixel.
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const commitVolume = (v: number) => {
    setVolume(v)
    if (timer.current) clearTimeout(timer.current)
    timer.current = setTimeout(() => save.mutate({ sound_volume: v }), 400)
  }
  useEffect(() => () => { if (timer.current) clearTimeout(timer.current) }, [])

  const s: AlertSettings | undefined = settings.data
  const block = push.data ? pushBlock(push.data.configured) : null
  const shownVolume = volume ?? s?.sound_volume ?? 35
  const q = quiet ?? (s ? { enabled: s.quiet_hours.enabled, start: s.quiet_hours.start, end: s.quiet_hours.end } : null)

  const tryTheSound = () => {
    const played = playPing(shownVolume, { fromGesture: true })
    setMessage(played ? null : { tone: 'warn', text: shownVolume === 0 ? (ar ? 'الصوت على صفر.' : 'The volume is at zero.') : (ar ? 'المتصفح ما سمح بتشغيل الصوت. اضغط في الصفحة ثم جرّب مرة ثانية.' : 'The browser did not allow sound. Click the page, then try again.') })
  }

  return (
    <Card data-testid="mobile-and-sound">
      <div className="flex flex-col gap-1">
        <CardTitle>{ar ? 'الصوت وإشعارات الجوال' : 'Sound and mobile notifications'}</CardTitle>
        <CardDescription>
          {ar ? 'لك أنت فقط. لا تأثير على بقية الفريق.' : 'Yours alone. It does not change anything for your teammates.'}
        </CardDescription>
      </div>

      {message && <div className="mt-3"><Alert severity={message.tone === 'ok' ? 'positive' : 'warning'} title={message.text} /></div>}

      <section aria-labelledby="sound-h" className="mt-5 flex flex-col gap-3 border-t border-border pt-5">
        <h3 id="sound-h" className="flex items-center gap-2 text-sm font-bold text-text-primary"><Volume2 size={16} /> {ar ? 'صوت التنبيه' : 'Alert sound'}</h3>
        <p className="max-w-2xl text-xs leading-6 text-text-muted">
          {ar
            ? 'نغمة خفيفة وقصيرة مرة وحدة عند وصول تنبيه حرج أو تحذير والصفحة مفتوحة. ما تُسمع للمعلومات العادية ولا وقت الهدوء.'
            : 'One short, soft tone when a critical alert or a warning arrives while the page is open. Never for plain information, never during quiet hours.'}
        </p>
        <Switch
          testId="sound-enabled"
          checked={s?.sound_enabled ?? true}
          disabled={!s}
          onCheckedChange={(v) => save.mutate({ sound_enabled: v })}
          label={ar ? 'شغّل الصوت' : 'Play the sound'}
        />
        <div className="flex flex-wrap items-end gap-4">
          <Field label={ar ? 'مستوى الصوت' : 'Volume'} htmlFor="sound-volume">
            <input
              id="sound-volume"
              data-testid="sound-volume"
              type="range"
              min={0}
              max={100}
              step={5}
              value={shownVolume}
              disabled={!s}
              onChange={(e) => commitVolume(Number(e.target.value))}
              className="w-56 accent-[var(--brand-600)]"
            />
          </Field>
          <Button variant="secondary" size="sm" data-testid="sound-try" onClick={tryTheSound}>{ar ? 'جرّب الصوت' : 'Try the sound'}</Button>
        </div>

        <div className="mt-2 grid gap-4 sm:grid-cols-3">
          <Field label={ar ? 'وقت الهدوء — من' : 'Quiet hours — from'} htmlFor="aq-start">
            <Input id="aq-start" type="time" value={q?.start ?? '22:00'} disabled={!q} onChange={(e) => q && setQuiet({ ...q, start: e.target.value })} />
          </Field>
          <Field label={ar ? 'إلى' : 'To'} htmlFor="aq-end">
            <Input id="aq-end" type="time" value={q?.end ?? '08:00'} disabled={!q} onChange={(e) => q && setQuiet({ ...q, end: e.target.value })} />
          </Field>
          <div className="flex items-end gap-3">
            <Switch checked={q?.enabled ?? true} disabled={!q} onCheckedChange={(v) => q && setQuiet({ ...q, enabled: v })} label={ar ? 'فعّل وقت الهدوء' : 'Use quiet hours'} />
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <Button size="sm" variant="secondary" disabled={!q || saveQuiet.isPending} onClick={() => q && saveQuiet.mutate(q)}>{ar ? 'احفظ وقت الهدوء' : 'Save quiet hours'}</Button>
          <p className="text-xs text-text-muted">
            {s?.quiet_hours.is_default
              ? (ar ? 'الحين يطبَّق الافتراضي: من 10 م إلى 8 ص بتوقيتك.' : 'The default applies: 10 pm to 8 am in your time.')
              : (ar ? 'بتوقيتك أنت.' : 'In your own time.')}
            {' '}
            {ar ? 'خلاله لا صوت ولا إشعار جوال، ويوصلك ملخص واحد بعد ما ينتهي.' : 'During it there is no sound and no mobile notification; you get one summary after.'}
          </p>
        </div>
      </section>

      <section aria-labelledby="push-h" className="mt-5 flex flex-col gap-3 border-t border-border pt-5">
        <h3 id="push-h" className="flex items-center gap-2 text-sm font-bold text-text-primary"><Smartphone size={16} /> {ar ? 'إشعارات الجوال' : 'Mobile notifications'}</h3>
        <p className="max-w-2xl text-xs leading-6 text-text-muted">
          {ar
            ? 'تصلك على جوالك أو جهازك حتى لو الصفحة مقفولة، وبصوت وهزّة الجهاز العادية. نرسلها بس للأشياء المهمة، وللمشاريع اللي تقدر تفتحها. في الإشعار اسم العميل ونوع التنبيه فقط — بدون أرقام ولا أسماء حملات.'
            : 'They reach your phone or device even when the page is closed, with the device’s own sound and vibration. Only for the important things, and only for projects you can open. The notification carries the client name and the kind of alert — no figures, no campaign names.'}
        </p>

        {block && block !== 'denied' ? (
          <Alert severity="info" title={BLOCKED[block][ar ? 'ar' : 'en']} />
        ) : (
          <>
            {block === 'denied' && <Alert severity="warning" title={BLOCKED.denied[ar ? 'ar' : 'en']} />}
            <div>
              <Button
                data-testid="enable-push"
                disabled={!push.data || block === 'denied' || enable.isPending}
                onClick={() => enable.mutate()}
              >
                <BellRing size={16} /> {ar ? 'فعّل إشعارات الجوال' : 'Turn on mobile notifications'}
              </Button>
            </div>
          </>
        )}

        {push.data && push.data.configured && (
          <div className="flex flex-col gap-2" data-testid="push-types">
            <p className="text-xs font-semibold text-text-secondary">{ar ? 'وش تبغى يوصلك على الجوال؟' : 'What should reach your phone?'}</p>
            {PUSH_TYPES.map((t) => (
              <Switch
                key={t.key}
                testId={`push-type-${t.key}`}
                checked={s?.push_types[t.key] ?? true}
                disabled={!s}
                onCheckedChange={(v) => save.mutate({ push_types: { [t.key]: v } })}
                label={ar ? t.ar : t.en}
              />
            ))}
          </div>
        )}

        {push.data && push.data.devices.length > 0 && (
          <div className="flex flex-col gap-2" data-testid="push-devices">
            <p className="text-xs font-semibold text-text-secondary">{ar ? 'أجهزتك' : 'Your devices'}</p>
            <ul className="flex flex-col divide-y divide-border rounded-xl border border-border">
              {push.data.devices.map((d) => (
                <li key={d.id} data-testid={`push-device-${d.id}`} className="flex flex-wrap items-center gap-3 px-3 py-2">
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold text-text-primary">{d.label || (ar ? 'جهاز' : 'Device')}</span>
                    <span className="block text-xs text-text-muted">
                      {ar ? 'آخر إرسال: ' : 'Last sent: '}<span dir="ltr" className="tnum">{when(d.last_used_at, ar)}</span>
                      {d.failing ? (ar ? ' — ما وصله الإرسال أكثر من مرة' : ' — recent sends did not arrive') : ''}
                    </span>
                  </span>
                  <Button size="sm" variant="secondary" onClick={() => test.mutate(d.id)} disabled={test.isPending}>{ar ? 'جرّب' : 'Test'}</Button>
                  <Button size="sm" variant="ghost" aria-label={ar ? 'إلغاء الجهاز' : 'Remove device'} data-testid={`push-revoke-${d.id}`} onClick={() => revoke.mutate(d.id)}>
                    <Trash2 size={15} /> {ar ? 'إلغاء' : 'Remove'}
                  </Button>
                </li>
              ))}
            </ul>
          </div>
        )}
      </section>
    </Card>
  )
}
