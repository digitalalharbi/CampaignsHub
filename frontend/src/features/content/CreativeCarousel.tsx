import { useEffect, useRef, useState } from 'react'
import { ChevronLeft, ChevronRight, Images } from 'lucide-react'
import { CreativeVideoPlayer } from './CreativeVideoPlayer'
import { imageLoading } from './format'
import type { CreativePreview } from './api'
import type { Locale } from '@/stores/ui'

/**
 * §15 — a carousel, shown as the several things it actually is.
 *
 * ## What this replaces
 *
 * The columns a creative syncs into are singular: one `asset_url`, one `headline`, one
 * `destination_url`. A five-card carousel poured into them keeps the FIRST card and drops the rest,
 * and every surface then rendered a fifth of what ran with nothing on screen admitting it. A reader
 * comparing «the carousel» against a video was comparing one of its cards. That is a wrong answer,
 * not a missing feature, which is why the fix goes all the way down to a column.
 *
 * ## Three states, and none of them is a shrug
 *
 *   - the provider sent a breakdown → the cards, in the order they ran;
 *   - it sent none (`cards_reported === false`) → said plainly, so the single asset above is not read
 *     as «this carousel has one card»;
 *   - some card links were refused for carrying a credential → the count is stated, because «3 of 5
 *     cards are shown» is a sentence the reader is entitled to.
 *
 * ## One card at a time, and only one player
 *
 * The strip below is thumbnails; the panel above shows the selected card. `CreativeVideoPlayer` is
 * keyed by the card index, so moving to another card unmounts the old player rather than leaving a
 * video playing behind a picture — the same rule `CreativeViewer` follows between creatives.
 */

const COPY = {
  ar: {
    title: 'بطاقات الكاروسيل',
    of: 'من',
    card: 'البطاقة',
    previous: 'البطاقة السابقة',
    next: 'البطاقة التالية',
    notReported: 'لم ترسل هذه المنصة تفاصيل بطاقات هذا الإعلان — المعروض أعلاه هو الأصل الوحيد المتاح.',
    withheld: (n: number) => `${n} من البطاقات تحمل روابط بيانات اعتماد، فلم تُعرض.`,
    empty: 'أرسلت المنصة قائمة بطاقات فارغة.',
    headline: 'العنوان',
    body: 'النص',
    cta: 'زر الإجراء',
    destination: 'الوجهة',
    noPreview: 'لا تتوفر معاينة لهذه البطاقة',
  },
  en: {
    title: 'Carousel cards',
    of: 'of',
    card: 'Card',
    previous: 'Previous card',
    next: 'Next card',
    notReported: 'This platform sent no card breakdown for this ad — the asset above is all it exposes.',
    withheld: (n: number) => `${n} card links carried a credential and were not shown.`,
    empty: 'The platform sent an empty card list.',
    headline: 'Headline',
    body: 'Body',
    cta: 'Call to action',
    destination: 'Destination',
    noPreview: 'No preview available for this card',
  },
} as const

export function CreativeCarousel({
  preview,
  locale,
}: {
  preview: CreativePreview
  locale: Locale
}) {
  const ar = locale === 'ar'
  const t = COPY[ar ? 'ar' : 'en']
  const [active, setActive] = useState(0)
  const panel = useRef<HTMLDivElement>(null)

  const cards = preview.cards ?? []
  const reported = preview.cards_reported === true
  const withheld = preview.cards_withheld ?? 0

  // A creative whose card count changed under us (a period change refetches) must not keep pointing
  // past the end of the new list.
  useEffect(() => {
    setActive((i) => (i < cards.length ? i : 0))
  }, [cards.length])

  // Only a carousel has cards. Everything else renders nothing at all rather than an empty section.
  if (preview.kind !== 'carousel') return null

  if (!reported) {
    return (
      <section className="rounded-lg border border-border bg-surface p-4">
        <h2 className="flex items-center gap-2 text-sm font-medium text-text-primary">
          <Images className="h-4 w-4" aria-hidden />
          {t.title}
        </h2>
        <p className="mt-2 text-sm text-text-secondary">{t.notReported}</p>
      </section>
    )
  }

  const current = cards[active]

  return (
    <section className="space-y-3 rounded-lg border border-border bg-surface p-4" data-testid="creative-carousel">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 text-sm font-medium text-text-primary">
          <Images className="h-4 w-4" aria-hidden />
          {t.title}
        </h2>
        {cards.length > 0 && (
          <p className="text-xs text-text-secondary" dir="ltr">
            {t.card} {active + 1} {t.of} {cards.length}
          </p>
        )}
      </div>

      {withheld > 0 && <p className="text-xs text-warning">{t.withheld(withheld)}</p>}

      {cards.length === 0 ? (
        <p className="text-sm text-text-secondary">{t.empty}</p>
      ) : (
        <>
          {/*
           * Arrow keys move between cards when the panel has focus — the same gesture the image
           * viewer uses, so the two do not have to be learned separately.
           */}
          <div
            ref={panel}
            tabIndex={0}
            role="group"
            aria-label={`${t.card} ${active + 1} ${t.of} ${cards.length}`}
            onKeyDown={(e) => {
              if (e.key === 'ArrowRight') setActive((i) => (i + 1) % cards.length)
              if (e.key === 'ArrowLeft') setActive((i) => (i - 1 + cards.length) % cards.length)
            }}
            className="rounded-md border border-border p-3 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          >
            <div className="flex items-center gap-2">
              <button
                type="button"
                aria-label={t.previous}
                disabled={cards.length < 2}
                onClick={() => setActive((i) => (i - 1 + cards.length) % cards.length)}
                className="shrink-0 rounded-md border border-border p-1.5 text-text-secondary hover:bg-surface-hover disabled:opacity-40"
              >
                <ChevronLeft className="h-4 w-4 ltr:rotate-0 rtl:rotate-180" aria-hidden />
              </button>

              <div className="flex min-h-40 flex-1 items-center justify-center overflow-hidden">
                {current.video_url ? (
                  // Keyed by index: moving cards unmounts the player rather than leaving one playing
                  // behind the next picture.
                  <CreativeVideoPlayer
                    key={`card-${current.index}`}
                    src={current.video_url}
                    poster={current.thumbnail_url ?? current.image_url}
                  />
                ) : current.image_url || current.thumbnail_url ? (
                  <img
                    src={(current.image_url ?? current.thumbnail_url) as string}
                    alt={current.headline ?? `${t.card} ${active + 1}`}
                    loading={imageLoading((current.image_url ?? current.thumbnail_url) as string)}
                    className="max-h-72 w-auto max-w-full rounded object-contain"
                  />
                ) : (
                  <p className="text-sm text-text-secondary">{t.noPreview}</p>
                )}
              </div>

              <button
                type="button"
                aria-label={t.next}
                disabled={cards.length < 2}
                onClick={() => setActive((i) => (i + 1) % cards.length)}
                className="shrink-0 rounded-md border border-border p-1.5 text-text-secondary hover:bg-surface-hover disabled:opacity-40"
              >
                <ChevronRight className="h-4 w-4 ltr:rotate-0 rtl:rotate-180" aria-hidden />
              </button>
            </div>

            {/*
             * A key that is ABSENT was removed by the link, and a key that is present and null is a
             * card the platform sent nothing for. Neither renders a labelled empty row.
             */}
            <dl className="mt-3 grid gap-2 sm:grid-cols-2">
              {current.headline && <CardFact label={t.headline} value={current.headline} />}
              {current.body && <CardFact label={t.body} value={current.body} />}
              {current.cta && <CardFact label={t.cta} value={current.cta} />}
              {/* Text, never a link: it is a destination chosen by whoever wrote the ad. */}
              {current.destination_url && <CardFact label={t.destination} value={current.destination_url} ltr />}
            </dl>
          </div>

          {cards.length > 1 && (
            <ul className="flex flex-wrap gap-2">
              {cards.map((card, i) => (
                <li key={card.index}>
                  <button
                    type="button"
                    aria-label={`${t.card} ${i + 1}`}
                    aria-current={i === active}
                    onClick={() => setActive(i)}
                    className={`overflow-hidden rounded border ${i === active ? 'border-primary' : 'border-border'}`}
                  >
                    {card.thumbnail_url || card.image_url ? (
                      <img
                        src={(card.thumbnail_url ?? card.image_url) as string}
                        alt=""
                        loading={imageLoading((card.thumbnail_url ?? card.image_url) as string)}
                        className="h-14 w-14 object-cover"
                      />
                    ) : (
                      <span className="flex h-14 w-14 items-center justify-center bg-surface-hover text-xs text-text-secondary">
                        {i + 1}
                      </span>
                    )}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </section>
  )
}

function CardFact({ label, value, ltr }: { label: string; value: string; ltr?: boolean }) {
  return (
    <div>
      <dt className="text-[11px] text-text-secondary">{label}</dt>
      <dd className="mt-0.5 break-words text-sm text-text-primary" dir={ltr ? 'ltr' : undefined}>
        {value}
      </dd>
    </div>
  )
}
