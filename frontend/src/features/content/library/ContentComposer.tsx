import { useEffect, useMemo, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Check, ImagePlus, Lock, Sparkles, Type, Wand2 } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { formatNumber } from '@/lib/numerals'
import { buildStudioBatch, type BatchOutput } from '../studio/batch/buildBatch'
import { copyIsClean, suggestCopy } from '../studio/batch/copySuggest'
import { countWords, LIMITS, type StudioCopy } from '../studio/direction/copyRules'
import { canvasMeasure, drawCover, loadPixels, type LoadedPicture } from '../studio/ui/loadPixels'
import { StudioPreview } from '../studio/ui/StudioPreview'
import { canExportVideo, renderImage, renderVideo } from '../studio/export/renderOutput'
import { openStudioSession, saveStudioOutput } from '../studio/studioApi'

type Step = 'media' | 'text' | 'designs' | 'enhance'

const T = {
  ar: {
    title: 'محتوى جديد', steps: { media: 'الوسائط', text: 'النص', designs: 'التصاميم', enhance: 'التحسينات' },
    mediaHead: 'الوسائط', mediaHint: 'اختر صورة المنتج. نصنع منها 3 فيديوهات و4 صور بمقاس 1080×1920 مع الهوامش الآمنة.',
    pick: 'اختيار صورة', picked: 'صورة محددة', change: 'تغيير الصورة', reading: 'نقرأ الصورة…', failed: 'تعذّرت قراءة الصورة',
    textHead: 'النص', product: 'اسم المنتج', price: 'السعر (اختياري)', hook: 'العنوان', message: 'الرسالة', cta: 'الدعوة',
    words: 'كلمات', apply: 'تطبيق', suggestions: 'اقتراحات نص', ctaRequired: 'الدعوة مطلوبة',
    designsHead: 'التصاميم', designsHint: 'كل تصميم يُعرض كما سيُحفظ. اختر ما تريد حفظه.', newBatch: 'دفعة جديدة', margins: 'إظهار الهوامش',
    video: 'فيديو', image: 'صورة', ready: 'جاهز', blocked: 'فيه ملاحظة', selected: 'محدد',
    enhanceHead: 'التحسينات', enhanceHint: 'كل التحسينات مقفلة افتراضيًا. لا نستخدم أي تحسين آلي على المحتوى.',
    enh: {
      price: ['إبراز السعر', 'يضيف السعر الذي كتبته إلى الرسالة.'],
      translate: ['الترجمة', 'تحتاج مفتاح ذكاء اصطناعي خاص بمساحة العمل. غير مفعّل.'],
      music: ['موسيقى', 'مكتبة الموسيقى غير مربوطة بعد.'],
    },
    back: 'السابق', next: 'التالي', save: 'حفظ في المكتبة', saving: 'نحفظ…', saved: 'تم الحفظ في مكتبة الوسائط',
    noProject: 'اختر مشروعًا أولًا.', saveFailed: 'تعذّر الحفظ', videoAsStill: 'متصفحك لا يصدّر الفيديو، سنحفظ الفيديوهات كصور.',
  },
  en: {
    title: 'New content', steps: { media: 'Media', text: 'Text', designs: 'Designs', enhance: 'Enhancements' },
    mediaHead: 'Media', mediaHint: 'Choose the product picture. We make 3 videos and 4 images from it at 1080×1920 with the safe margins.',
    pick: 'Choose a picture', picked: 'picture selected', change: 'Change picture', reading: 'Reading the picture…', failed: 'Could not read the picture',
    textHead: 'Text', product: 'Product name', price: 'Price (optional)', hook: 'Hook', message: 'Message', cta: 'Call to action',
    words: 'words', apply: 'Apply', suggestions: 'Text suggestions', ctaRequired: 'A call to action is required',
    designsHead: 'Designs', designsHint: 'Each design shows as it will be saved. Pick the ones to save.', newBatch: 'New batch', margins: 'Show margins',
    video: 'Video', image: 'Image', ready: 'Ready', blocked: 'Has a note', selected: 'Selected',
    enhanceHead: 'Enhancements', enhanceHint: 'Every enhancement is off by default. No automated enhancement is applied to the content.',
    enh: {
      price: ['Show the price', 'Adds the price you typed to the message.'],
      translate: ['Translation', 'Needs the workspace\'s own AI key. Not enabled.'],
      music: ['Music', 'The music library is not connected yet.'],
    },
    back: 'Back', next: 'Next', save: 'Save to library', saving: 'Saving…', saved: 'Saved to the media library',
    noProject: 'Choose a project first.', saveFailed: 'Could not save', videoAsStill: 'This browser cannot export video; videos will be saved as images.',
  },
} as const

const STEPS: Step[] = ['media', 'text', 'designs', 'enhance']
const STEP_ICON = { media: ImagePlus, text: Type, designs: Sparkles, enhance: Wand2 } as const

/**
 * CONTENT-LIBRARY-SNAP-001 — «محتوى جديد», set out the way Meta's Advantage+ creative setup is: the steps down one side, one step
 * at a time on the other. Media → text → designs → enhancements → save. Rendering happens in the browser with the studio's own
 * `drawFrame`; the server only stores (CONTENT-STUDIO-001 batch 8). Every enhancement starts off, and the ones that need a key or a
 * library that is not connected are shown locked with the reason, never pretended.
 */
export function ContentComposer({ onClose }: { onClose: () => void }) {
  const locale = useUi((s) => s.locale) === 'ar' ? 'ar' : 'en'
  const t = T[locale]
  const projectId = useProject((s) => s.currentProjectId)
  const queryClient = useQueryClient()
  const fileInput = useRef<HTMLInputElement>(null)
  const measure = useMemo(() => canvasMeasure('Cairo, Tajawal, sans-serif'), [])

  const [step, setStep] = useState<Step>('media')
  const [file, setFile] = useState<File | null>(null)
  const [picture, setPicture] = useState<LoadedPicture | null>(null)
  const [failed, setFailed] = useState(false)
  const [product, setProduct] = useState('')
  const [price, setPrice] = useState('')
  const [copy, setCopy] = useState<StudioCopy>(() => suggestCopy({}, locale)[0]!)
  const [round, setRound] = useState(0)
  const [margins, setMargins] = useState(false)
  const [chosen, setChosen] = useState<number[]>([])
  const [showPrice, setShowPrice] = useState(false)
  const [saving, setSaving] = useState<{ done: number; total: number } | null>(null)
  const [result, setResult] = useState<'saved' | 'failed' | null>(null)

  useEffect(() => {
    if (!file) return
    let alive = true
    setPicture(null)
    setFailed(false)
    loadPixels(file).then((p) => { if (alive) setPicture(p) }).catch(() => { if (alive) setFailed(true) })

    return () => { alive = false }
  }, [file])

  const finalCopy: StudioCopy = useMemo(() => (showPrice && price.trim() ? { ...copy, support: price.trim() } : copy), [copy, showPrice, price])
  const suggestions = useMemo(() => suggestCopy({ name: product || undefined, price: price || undefined }, locale), [product, price, locale])
  const batch = useMemo(() => (picture ? buildStudioBatch({ pixels: picture.pixels, copy: finalCopy, locale, measure, round }) : null), [picture, finalCopy, locale, measure, round])

  // A new batch starts with every design that passed the hard checks selected.
  useEffect(() => {
    if (batch) setChosen(batch.outputs.map((o, i) => (o.hard.length === 0 ? i : -1)).filter((i) => i >= 0))
  }, [batch])

  const canNext = step === 'media' ? picture !== null : step === 'text' ? copy.cta.trim().length > 0 && copyIsClean(copy) : step === 'designs' ? chosen.length > 0 : true
  const index = STEPS.indexOf(step)

  const save = async () => {
    if (!batch || !picture || !projectId) return
    const picked: BatchOutput[] = chosen.map((i) => batch.outputs[i]!).filter((o) => o.hard.length === 0)
    setSaving({ done: 0, total: picked.length })
    setResult(null)
    try {
      const session = await openStudioSession(projectId)
      const stamp = new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }).replace(/ (\w+) /, '$1 ')
      const base = product.trim() || file?.name.replace(/\.[^.]+$/, '') || 'Creative'
      let n = 0
      for (const o of picked) {
        const asVideo = o.as === 'video' && canExportVideo()
        const blob = asVideo ? await renderVideo(o.scene, picture.bitmap, batch.tokens) : await renderImage(o.scene, picture.bitmap, batch.tokens)
        n += 1
        await saveStudioOutput(session.id, {
          file: blob,
          kind: asVideo ? 'video' : 'image',
          name: `${stamp} ــ ${base} ــ ${asVideo ? 'Video' : 'Image'} ${n}`,
          familyKey: o.pick.family.key,
          familyVersion: o.pick.family.version,
          hook: finalCopy.hook,
          message: finalCopy.message,
          cta: finalCopy.cta,
          durationMs: asVideo ? o.scene.durationMs : null,
          score: o.warnings.length === 0 ? 100 : 80,
          tokens: { font_pair: batch.tokens.fontPair.key, accent: batch.tokens.colors.accent, surface: batch.tokens.colors.surface },
          conceptName: base,
        })
        setSaving({ done: n, total: picked.length })
      }
      void queryClient.invalidateQueries({ queryKey: ['content-intake'] })
      setResult('saved')
    } catch {
      setResult('failed')
    } finally {
      setSaving(null)
    }
  }

  const words = (key: 'hook' | 'message' | 'cta') => `${formatNumber(countWords(copy[key]))} / ${formatNumber(LIMITS[key])} ${t.words}`
  const field = (key: 'hook' | 'message' | 'cta', label: string) => (
    <label className="flex flex-col gap-1.5 text-sm font-semibold text-text-primary">
      <span className="flex justify-between">{label}<span className="text-xs font-normal text-text-secondary">{words(key)}</span></span>
      <Input value={copy[key]} onChange={(e) => setCopy((c) => ({ ...c, [key]: e.target.value }))} data-testid={`composer-${key}`} />
    </label>
  )

  const toggleRow = (title: string, body: string, on: boolean, onChange: ((v: boolean) => void) | null, testid: string) => (
    <div className="flex items-start justify-between gap-4 rounded-xl border border-border p-4" data-testid={testid}>
      <div>
        <p className="flex items-center gap-2 text-sm font-bold text-text-primary">{!onChange && <Lock size={14} aria-hidden />}{title}</p>
        <p className="mt-1 text-xs text-text-secondary">{body}</p>
      </div>
      <input type="checkbox" role="switch" checked={on} disabled={!onChange} onChange={(e) => onChange?.(e.target.checked)} aria-label={title} />
    </div>
  )

  const footer = (
    <div className="flex w-full items-center justify-between gap-2">
      <Button variant="secondary" onClick={index === 0 ? onClose : () => setStep(STEPS[index - 1]!)}>{t.back}</Button>
      {step !== 'enhance'
        ? <Button onClick={() => setStep(STEPS[index + 1]!)} disabled={!canNext} data-testid="composer-next">{t.next}</Button>
        : <Button onClick={() => void save()} disabled={!projectId || saving !== null || chosen.length === 0} data-testid="composer-save">{saving ? `${t.saving} ${formatNumber(saving.done)}/${formatNumber(saving.total)}` : t.save}</Button>}
    </div>
  )

  return (
    <Modal open onClose={onClose} title={t.title} size="xl" footer={footer}>
      <div className="grid gap-5 md:grid-cols-[12rem_minmax(0,1fr)]" data-testid="content-composer">
        <ol className="flex gap-2 overflow-x-auto md:flex-col">
          {STEPS.map((s, i) => {
            const Icon = STEP_ICON[s]
            const done = i < index

            return (
              <li key={s}>
                <button type="button" disabled={i > index} onClick={() => setStep(s)} data-testid={`composer-step-${s}`} aria-current={s === step ? 'step' : undefined}
                  className={`flex w-full items-center gap-2 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold ${s === step ? 'bg-surface-secondary text-text-primary' : 'text-text-secondary'} disabled:opacity-50`}>
                  {done ? <Check size={16} className="text-success" aria-hidden /> : <Icon size={16} aria-hidden />}{t.steps[s]}
                </button>
              </li>
            )
          })}
        </ol>

        <div className="min-w-0">
          {!projectId && <p className="mb-3 rounded-lg bg-warning-soft px-3 py-2 text-sm text-warning">{t.noProject}</p>}

          {step === 'media' && (
            <section>
              <h3 className="text-base font-bold text-text-primary">{t.mediaHead}</h3>
              <p className="mt-1 text-sm text-text-secondary">{t.mediaHint}</p>
              <input ref={fileInput} type="file" accept="image/*" hidden data-testid="composer-file" onChange={(e) => { const f = e.target.files?.[0]; if (f) setFile(f); e.target.value = '' }} />
              <div className="mt-4 flex items-center gap-4 rounded-xl border border-dashed border-border p-4">
                {picture ? <canvas className="h-32 w-20 rounded-md bg-surface-secondary" ref={(c) => { const ctx = c?.getContext('2d'); if (c && ctx && picture) { c.width = 180; c.height = 320; drawCover(ctx, picture.bitmap, 180, 320) } }} /> : <div className="flex h-32 w-20 items-center justify-center rounded-md bg-surface-secondary text-text-muted"><ImagePlus size={20} aria-hidden /></div>}
                <div>
                  <p className="text-sm font-semibold text-text-primary">{file ? `${formatNumber(1)} ${t.picked}` : t.mediaHead}</p>
                  {file && !picture && !failed && <p className="text-xs text-text-secondary">{t.reading}</p>}
                  {failed && <p className="text-xs text-danger" data-testid="composer-failed">{t.failed}</p>}
                  <Button variant="secondary" className="mt-2" onClick={() => fileInput.current?.click()} data-testid="composer-pick">{file ? t.change : t.pick}</Button>
                </div>
              </div>
            </section>
          )}

          {step === 'text' && (
            <section className="flex flex-col gap-4">
              <h3 className="text-base font-bold text-text-primary">{t.textHead}</h3>
              <div className="grid gap-4 sm:grid-cols-2">
                <label className="flex flex-col gap-1.5 text-sm font-semibold text-text-primary">{t.product}<Input value={product} onChange={(e) => setProduct(e.target.value)} data-testid="composer-product" /></label>
                <label className="flex flex-col gap-1.5 text-sm font-semibold text-text-primary">{t.price}<Input value={price} onChange={(e) => setPrice(e.target.value)} data-testid="composer-price" /></label>
              </div>
              {field('hook', t.hook)}
              {field('message', t.message)}
              {field('cta', t.cta)}
              {!copy.cta.trim() && <p className="text-xs text-danger">{t.ctaRequired}</p>}
              <div>
                <p className="mb-2 text-sm font-bold text-text-primary">{t.suggestions}</p>
                <ul className="flex flex-col gap-2">
                  {suggestions.map((s, i) => (
                    <li key={i} className="flex items-center justify-between gap-3 rounded-xl border border-border p-3">
                      <span className="min-w-0 text-sm text-text-primary"><b>{s.hook}</b> · {s.message} · <span className="text-brand-600">{s.cta}</span></span>
                      <Button variant="secondary" onClick={() => setCopy(s)} data-testid={`composer-apply-${i}`}>{t.apply}</Button>
                    </li>
                  ))}
                </ul>
              </div>
            </section>
          )}

          {step === 'designs' && batch && (
            <section>
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <h3 className="text-base font-bold text-text-primary">{t.designsHead}</h3>
                  <p className="mt-1 text-sm text-text-secondary">{t.designsHint}</p>
                </div>
                <div className="flex items-center gap-3">
                  <label className="flex items-center gap-1.5 text-xs font-semibold text-text-secondary"><input type="checkbox" checked={margins} onChange={(e) => setMargins(e.target.checked)} />{t.margins}</label>
                  <Button variant="secondary" onClick={() => setRound((r) => r + 1)} data-testid="composer-new-batch">{t.newBatch}</Button>
                </div>
              </div>
              {!canExportVideo() && <p className="mt-2 text-xs text-text-secondary">{t.videoAsStill}</p>}
              <ul className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4" data-testid="composer-grid">
                {batch.outputs.map((o, i) => {
                  const ok = o.hard.length === 0
                  const on = chosen.includes(i)

                  return (
                    <li key={`${o.as}-${o.pick.family.key}-${i}`}>
                      <button type="button" disabled={!ok} onClick={() => setChosen((c) => (on ? c.filter((x) => x !== i) : [...c, i]))} data-testid={`composer-design-${i}`}
                        className={`relative block w-full overflow-hidden rounded-xl border-2 ${on ? 'border-brand-600' : 'border-transparent'} disabled:opacity-60`}>
                        <StudioPreview scene={o.scene} bitmap={picture?.bitmap ?? null} tokens={batch.tokens} showMargins={margins} live={o.as === 'video' && i < 3} />
                        {on && <span className="absolute end-2 top-2 rounded-full bg-brand-600 p-1 text-white"><Check size={12} aria-hidden /></span>}
                      </button>
                      <p className="mt-1 flex items-center gap-2 text-xs"><b className="text-text-primary">{o.as === 'video' ? t.video : t.image}</b><span className={ok ? 'text-success' : 'text-danger'}>{ok ? t.ready : t.blocked}</span></p>
                    </li>
                  )
                })}
              </ul>
            </section>
          )}

          {step === 'enhance' && (
            <section className="flex flex-col gap-3">
              <h3 className="text-base font-bold text-text-primary">{t.enhanceHead}</h3>
              <p className="text-sm text-text-secondary">{t.enhanceHint}</p>
              {toggleRow(t.enh.price[0], t.enh.price[1], showPrice, price.trim() ? setShowPrice : null, 'composer-enh-price')}
              {toggleRow(t.enh.translate[0], t.enh.translate[1], false, null, 'composer-enh-translate')}
              {toggleRow(t.enh.music[0], t.enh.music[1], false, null, 'composer-enh-music')}
              {result === 'saved' && <p className="rounded-lg bg-success-soft px-3 py-2 text-sm font-semibold text-success" data-testid="composer-saved">{t.saved}</p>}
              {result === 'failed' && <p className="rounded-lg bg-danger-soft px-3 py-2 text-sm font-semibold text-danger" data-testid="composer-save-failed">{t.saveFailed}</p>}
            </section>
          )}
        </div>
      </div>
    </Modal>
  )
}
