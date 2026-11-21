import { useEffect, useMemo, useState } from 'react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { useUi } from '@/stores/ui'
import { buildStudioBatch } from '../batch/buildBatch'
import { copyIsClean, suggestCopy } from '../batch/copySuggest'
import type { StudioCopy } from '../direction/copyRules'
import type { HardCode } from '../direction/hardChecks'
import { FONT_PAIRS } from '../tokens/fontPairs'
import { restyle } from '../tokens/tokens'
import { canvasMeasure, loadPixels, type LoadedPicture } from './loadPixels'
import { StudioPreview } from './StudioPreview'

/**
 * CONTENT-STUDIO-001 batch 7B — the studio drawer: open it on a picture and it makes the batch by itself (3 videos + 4 images), every
 * preview live on a canvas and nothing encoded or stored. The controls are the three that matter at this stage: edit the three lines of
 * copy, make a new batch, swap the style of the whole batch; and a switch that shows the protected margins over every preview.
 * Saving, sending to approval and uploading to an ad account are not here yet and are not pretended.
 */
const T = {
  ar: {
    title: 'استوديو المحتوى', loading: 'نقرأ الصورة…', failed: 'تعذّرت قراءة الصورة', hook: 'العنوان', message: 'الرسالة', cta: 'الدعوة', margins: 'إظهار الهوامش',
    again: 'دفعة جديدة', overProduct: 'النص فوق المنتج', style: 'بدّل الأسلوب', video: 'فيديو', image: 'صورة', ready: 'جاهز', close: 'إغلاق', videos: 'فيديوهات', images: 'صور', suggest: 'اقتراح',
    hard: { layer_in_margin: 'عنصر داخل الهامش', video_too_long: 'أطول من 15 ثانية', hook_not_first_3s: 'التشويق خارج أول 3 ثوانٍ', cta_too_short: 'الدعوة قصيرة', cta_missing: 'لا دعوة', hashtag: 'هاشتاق', emoji: 'إيموجي', forbidden_word: 'كلمة ممنوعة', hook_too_long: 'العنوان طويل', message_too_long: 'الرسالة طويلة', cta_too_long: 'الدعوة طويلة' } satisfies Record<HardCode, string>,
  },
  en: {
    title: 'Content studio', loading: 'Reading the picture…', failed: 'Could not read the picture', hook: 'Hook', message: 'Message', cta: 'Call to action', margins: 'Show margins',
    again: 'New batch', overProduct: 'Text over the product', style: 'Swap style', video: 'Video', image: 'Image', ready: 'Ready', close: 'Close', videos: 'Videos', images: 'Images', suggest: 'Suggest',
    hard: { layer_in_margin: 'Layer in a margin', video_too_long: 'Over 15 seconds', hook_not_first_3s: 'Hook outside the first 3s', cta_too_short: 'CTA too short', cta_missing: 'No CTA', hashtag: 'Hashtag', emoji: 'Emoji', forbidden_word: 'Forbidden word', hook_too_long: 'Hook too long', message_too_long: 'Message too long', cta_too_long: 'CTA too long' } satisfies Record<HardCode, string>,
  },
} as const

export function StudioDrawer({ file, onClose, initialCopy }: { file: Blob; onClose: () => void; initialCopy?: Partial<StudioCopy> }) {
  const locale = useUi((s) => s.locale) === 'ar' ? 'ar' : 'en'
  const t = T[locale]
  const [picture, setPicture] = useState<LoadedPicture | null>(null)
  const [failed, setFailed] = useState(false)
  const [copy, setCopy] = useState<StudioCopy>(() => ({ ...suggestCopy({}, locale)[0]!, ...initialCopy }))
  const [round, setRound] = useState(0)
  const [styleStep, setStyleStep] = useState(0)
  const [margins, setMargins] = useState(false)
  const measure = useMemo(() => canvasMeasure('Cairo, Tajawal, sans-serif'), [])

  useEffect(() => {
    let alive = true

    loadPixels(file).then((p) => { if (alive) setPicture(p) }).catch(() => { if (alive) setFailed(true) })

    return () => { alive = false }
  }, [file])

  // The analysis is read once per picture; a new batch or a style swap re-uses it.
  const base = useMemo(() => (picture ? buildStudioBatch({ pixels: picture.pixels, copy, locale, measure, round: 0 }) : null), [picture, locale, measure]) // eslint-disable-line react-hooks/exhaustive-deps

  const batch = useMemo(() => {
    if (!picture || !base) return null

    const baseIndex = Math.max(0, FONT_PAIRS.findIndex((p) => p.key === base.tokens.fontPair.key))
    const tokens = styleStep === 0
      ? base.tokens
      : restyle(base.tokens, { fontPairKey: FONT_PAIRS[(baseIndex + styleStep) % FONT_PAIRS.length]!.key, accent: base.analysis.swatches[styleStep % Math.max(1, base.analysis.swatches.length)]?.hex })

    return buildStudioBatch({ pixels: picture.pixels, copy, locale, measure, round, analysis: base.analysis, tokens })
  }, [picture, base, copy, locale, measure, round, styleStep])

  const field = (key: keyof Pick<StudioCopy, 'hook' | 'message' | 'cta'>, label: string) => (
    <label className="flex min-w-0 flex-1 flex-col gap-1 text-xs font-semibold text-text-secondary">{label}
      <Input value={copy[key]} onChange={(e) => setCopy((c) => ({ ...c, [key]: e.target.value }))} data-testid={`studio-copy-${key}`} />
    </label>
  )

  return (
    <Modal open onClose={onClose} title={t.title} size="lg" footer={<Button variant="secondary" onClick={onClose}>{t.close}</Button>}>
      <div className="flex flex-col gap-3" data-testid="studio-drawer">
        {failed ? <p className="text-sm text-text-secondary" data-testid="studio-failed">{t.failed}</p> : null}
        {!picture && !failed ? <p className="text-sm text-text-secondary" data-testid="studio-loading">{t.loading}</p> : null}

        {batch ? (
          <>
            <div className="flex flex-wrap items-end gap-2">
              {field('hook', t.hook)}
              {field('message', t.message)}
              {field('cta', t.cta)}
            </div>
            {!copyIsClean(copy) ? <p className="text-xs text-text-secondary" data-testid="studio-copy-warning">{[...new Set(batch.outputs.flatMap((o) => o.hard.map((h) => t.hard[h.code])))].join(' · ')}</p> : null}

            <div className="flex flex-wrap items-center gap-2">
              <Button variant="secondary" onClick={() => setRound((r) => r + 1)} data-testid="studio-new-batch">{t.again}</Button>
              <Button variant="secondary" onClick={() => setStyleStep((s) => s + 1)} data-testid="studio-swap-style">{t.style}</Button>
              <Button variant="secondary" onClick={() => setCopy(suggestCopy({}, locale)[(round + 1) % 3]!)} data-testid="studio-suggest">{t.suggest}</Button>
              <label className="flex items-center gap-1.5 text-xs font-semibold text-text-secondary">
                <input type="checkbox" checked={margins} onChange={(e) => setMargins(e.target.checked)} data-testid="studio-margins" />{t.margins}
              </label>
            </div>

            <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4" data-testid="studio-grid">
              {batch.outputs.map((o, i) => (
                <li key={`${o.as}-${o.pick.family.key}`} className="flex min-w-0 flex-col gap-1.5" data-testid={`studio-output-${o.as}`} data-family={o.pick.family.key}>
                  <StudioPreview scene={o.scene} bitmap={picture?.bitmap ?? null} tokens={batch.tokens} showMargins={margins} live={o.as === 'video' && i < 3} />
                  <div className="flex flex-wrap items-center gap-1 text-xs">
                    <span className="font-bold text-text-primary">{o.as === 'video' ? t.video : t.image}</span>
                    <span className="truncate text-text-secondary">{o.pick.family.label[locale]}</span>
                  </div>
                  {o.hard.length === 0 && o.warnings.length > 0 ? <span className="w-fit rounded-full bg-warning-soft px-2 py-0.5 text-xs font-semibold text-warning" data-testid="studio-warning">{t.overProduct}</span> : null}
                  {o.hard.length === 0
                    ? <span className="w-fit rounded-full bg-success-soft px-2 py-0.5 text-xs font-semibold text-success" data-testid="studio-ready">{t.ready}</span>
                    : <span className="w-fit rounded-full bg-danger-soft px-2 py-0.5 text-xs font-semibold text-danger" data-testid="studio-hard">{[...new Set(o.hard.map((h) => t.hard[h.code]))].join(' · ')}</span>}
                </li>
              ))}
            </ul>
          </>
        ) : null}
      </div>
    </Modal>
  )
}
