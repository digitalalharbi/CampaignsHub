import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Copy, ExternalLink, Link2, MessageCircle, Plus } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { EmptyState } from '@/components/ui/States'
import { Field } from '@/components/ui/Field'
import { Badge } from '@/components/ui/Badge'
import { PageIntro } from '@/components/ui/PageIntro'
import { DEFAULT_DIAL_CODE, PhoneField, phoneFieldValue } from '@/components/ui/PhoneField'
import { useUi } from '@/stores/ui'
import { createShortLink, disableShortLink, listShortLinks, type ShortLink, type ShortLinkKind } from './api'

/**
 * SHORT-LINKS-001 — a utility a non-technical person finishes in two fields.
 *
 * The owner's requirement is a constraint on what is NOT here, and it is worth stating so nobody
 * adds it back kindly: no redirect type, no URL parameters, no custom slug, no tracking settings, no
 * expiry, no campaign picker. The system owns slug generation, the destination it builds and the
 * validation; the person chooses WhatsApp or Link, types one thing, and presses one button.
 *
 * The result screen is the point of the feature — a link to copy — so it is what the form becomes
 * rather than a toast that disappears while somebody is reaching for the mouse.
 */
const COPY = {
  ar: {
    title: 'اختصار الروابط',
    subtitle: 'رابط قصير تشاركه في الإعلان أو الرسالة، ونحن نحسب النقرات.',
    create: '+ إنشاء رابط مختصر',
    whatsapp: 'واتساب',
    link: 'رابط',
    phone: 'رقم الجوال',
    url: 'الرابط',
    url_hint: 'الصق العنوان كاملاً، يبدأ بـ https://',
    submit: 'إنشاء الرابط',
    cancel: 'إلغاء',
    ready: 'رابطك جاهز',
    copy: 'نسخ الرابط',
    copied: 'تم النسخ',
    open: 'فتح',
    another: 'إنشاء رابط آخر',
    none: 'لا روابط بعد',
    none_hint: 'أنشئ رابطاً مختصراً لمشاركته في إعلان أو رسالة.',
    clicks: 'نقرة',
    disabled: 'موقوف',
    disable: 'إيقاف',
    destination: 'الوجهة',
  },
  en: {
    title: 'Short Links',
    subtitle: 'A short link to share in an ad or a message. We count the clicks.',
    create: '+ Create short link',
    whatsapp: 'WhatsApp',
    link: 'Link',
    phone: 'Phone number',
    url: 'Link',
    url_hint: 'Paste the full address, starting with https://',
    submit: 'Create the link',
    cancel: 'Cancel',
    ready: 'Your link is ready',
    copy: 'Copy link',
    copied: 'Copied',
    open: 'Open',
    another: 'Create another',
    none: 'No links yet',
    none_hint: 'Create a short link to share in an ad or a message.',
    clicks: 'clicks',
    disabled: 'Disabled',
    disable: 'Disable',
    destination: 'Destination',
  },
}

/** The clipboard, and an honest answer when the browser refuses it. */
function useCopy() {
  const [copied, setCopied] = useState<string | null>(null)

  const copy = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(text)
      window.setTimeout(() => setCopied((c) => (c === text ? null : c)), 2000)
    } catch {
      /*
       * A clipboard a browser refuses is not a failure worth a dialog: the address is on screen and
       * selectable. Saying «copied» when nothing was copied is the only outcome that would be wrong.
       */
      setCopied(null)
    }
  }

  return { copied, copy }
}

export function ShortLinksPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const client = useQueryClient()
  const { copied, copy } = useCopy()

  const [open, setOpen] = useState(false)
  const [kind, setKind] = useState<ShortLinkKind>('whatsapp')
  const [phone, setPhone] = useState('')
  const [dial, setDial] = useState(DEFAULT_DIAL_CODE)
  const [url, setUrl] = useState('')
  const [created, setCreated] = useState<ShortLink | null>(null)
  const [error, setError] = useState<string | null>(null)

  const links = useQuery({ queryKey: ['short-links'], queryFn: listShortLinks })

  const reset = () => {
    setPhone('')
    setUrl('')
    setError(null)
  }

  const create = useMutation({
    mutationFn: () => {
      /*
       * The phone is sent in its canonical form when the control can read one, and RAW when it
       * cannot — the server does the same normalisation and answers with a message about the number
       * rather than about a format. Refusing here would be a second, stricter rule in a second place.
       */
      const value = kind === 'whatsapp' ? (phoneFieldValue(phone, dial) ?? `${dial}${phone}`) : url

      return createShortLink(kind, value)
    },
    onSuccess: (link) => {
      setCreated(link)
      setError(null)
      reset()
      void client.invalidateQueries({ queryKey: ['short-links'] })
    },
    onError: (e: { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }) => {
      const errors = e.response?.data?.errors
      setError(errors?.value?.[0] ?? errors?.kind?.[0] ?? e.response?.data?.message ?? null)
    },
  })

  const disable = useMutation({
    mutationFn: (id: string) => disableShortLink(id),
    onSuccess: () => void client.invalidateQueries({ queryKey: ['short-links'] }),
  })

  const rows = links.data ?? []

  return (
    <div className="space-y-4">
      <PageIntro title={t.title} purpose={t.subtitle} />

      {created !== null ? (
        <Card>
          <div className="flex flex-col gap-3" data-testid="short-link-result">
            <span className="text-sm font-semibold text-text-secondary">{t.ready}</span>
            <code dir="ltr" className="block rounded-xl bg-surface-secondary p-3 text-center text-base font-bold">
              {created.short_url}
            </code>
            <div className="flex flex-wrap gap-2">
              {/* Copy is PRIMARY: the whole point of the screen is to take the link away with you. */}
              <Button onClick={() => void copy(created.short_url)} data-testid="short-link-copy">
                {copied === created.short_url ? <Check size={15} /> : <Copy size={15} />}
                {copied === created.short_url ? t.copied : t.copy}
              </Button>
              <Button variant="secondary" onClick={() => window.open(created.short_url, '_blank', 'noopener')}>
                <ExternalLink size={15} />
                {t.open}
              </Button>
              <Button variant="secondary" onClick={() => { setCreated(null); setOpen(true) }}>
                {t.another}
              </Button>
            </div>
          </div>
        </Card>
      ) : open ? (
        <Card>
          <div className="flex flex-col gap-4">
            {/*
              Two choices, and nothing under them until one is made.

              Buttons rather than a select: there are exactly two, they are the whole decision, and a
              dropdown asks somebody to open something to find out what their options are.
            */}
            <div className="flex gap-2">
              {(['whatsapp', 'link'] as const).map((k) => (
                <button
                  key={k}
                  type="button"
                  data-testid={`short-link-kind-${k}`}
                  aria-pressed={kind === k}
                  onClick={() => { setKind(k); setError(null) }}
                  className={`flex flex-1 items-center justify-center gap-2 rounded-xl border p-3 text-sm font-semibold ${
                    kind === k ? 'border-brand-600 bg-brand-600/10 text-brand-600' : 'border-border text-text-secondary'
                  }`}
                >
                  {k === 'whatsapp' ? <MessageCircle size={16} /> : <Link2 size={16} />}
                  {k === 'whatsapp' ? t.whatsapp : t.link}
                </button>
              ))}
            </div>

            {kind === 'whatsapp' ? (
              <PhoneField
                id="short-link-phone"
                label={t.phone}
                value={phone}
                onChange={setPhone}
                dialCode={dial}
                onDialCodeChange={setDial}
                ar={ar}
                error={error ?? undefined}
                required
              />
            ) : (
              <Field label={t.url} htmlFor="short-link-url" hint={t.url_hint} error={error ?? undefined} required>
                <input
                  id="short-link-url"
                  dir="ltr"
                  type="url"
                  value={url}
                  onChange={(e) => { setUrl(e.target.value); setError(null) }}
                  placeholder="https://"
                  className="w-full rounded-xl border border-border bg-surface p-2.5 text-sm"
                />
              </Field>
            )}

            <div className="flex gap-2">
              <Button
                onClick={() => create.mutate()}
                disabled={create.isPending || (kind === 'whatsapp' ? phone.trim() === '' : url.trim() === '')}
                data-testid="short-link-submit"
              >
                {t.submit}
              </Button>
              <Button variant="secondary" onClick={() => { setOpen(false); reset() }}>{t.cancel}</Button>
            </div>
          </div>
        </Card>
      ) : (
        <Button onClick={() => setOpen(true)} data-testid="short-link-create">
          <Plus size={15} />
          {t.create}
        </Button>
      )}

      <Card>
        {rows.length === 0 ? (
          <EmptyState title={t.none} description={t.none_hint} />
        ) : (
          <div className="flex flex-col gap-2">
            {rows.map((l) => (
              <div key={l.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border p-3" data-testid={`short-link-${l.slug}`}>
                <div className="min-w-0">
                  <code dir="ltr" className="block truncate text-sm font-bold">{l.short_url}</code>
                  {/* What they typed — a phone number stays a phone number. */}
                  <span dir="ltr" className="block truncate text-xs text-text-muted">{l.shows}</span>
                </div>
                <div className="flex items-center gap-2">
                  <Badge tone="info">{l.kind === 'whatsapp' ? t.whatsapp : t.link}</Badge>
                  <span className="tnum text-xs text-text-secondary">{l.clicks} {t.clicks}</span>
                  {!l.is_active && <Badge tone="warning">{t.disabled}</Badge>}
                  <Button variant="secondary" onClick={() => void copy(l.short_url)} aria-label={t.copy}>
                    {copied === l.short_url ? <Check size={14} /> : <Copy size={14} />}
                  </Button>
                  {l.is_active && (
                    <Button variant="secondary" onClick={() => disable.mutate(l.id)}>{t.disable}</Button>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  )
}
