import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Lock, Plus, Search } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Num } from '@/components/ui/Num'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { formatNumber } from '@/lib/numerals'
import { mediaFitClass } from '../adPreview'
import { intakeFileUrl, listIntake } from '../intake/api'
import { buildStudioBatch, type BatchOutput } from '../studio/batch/buildBatch'
import { copyIsClean, suggestCopy } from '../studio/batch/copySuggest'
import { countWords, LIMITS, type StudioCopy } from '../studio/direction/copyRules'
import { canvasMeasure, drawCover, loadPixels, type LoadedPicture } from '../studio/ui/loadPixels'
import { StudioPreview } from '../studio/ui/StudioPreview'
import { canExportVideo, renderImage, renderVideo } from '../studio/export/renderOutput'
import { openStudioSession, saveStudioOutput } from '../studio/studioApi'

type Step = 'setup' | 'media' | 'text' | 'designs' | 'enhance'
const STEPS: Step[] = ['setup', 'media', 'text', 'designs', 'enhance']
const MAX_MEDIA = 10
const COVER = mediaFitClass(null, null, 'cover')

interface Pick { key: string; blob: () => Promise<Blob>; thumb: string }

const T = {
  ar: {
    title: 'محتوى جديد',
    steps: { setup: 'إعداد المحتوى', media: 'الوسائط', text: 'النص', designs: 'توليد المحتوى', enhance: 'التحسينات' },
    setupHead: 'إعداد المحتوى', setupHint: 'اسم المحتوى كما سيظهر في المكتبة. الاسم يبدأ بالتاريخ تلقائيًا.', name: 'اسم المنتج أو الحملة', preview: 'سيُحفظ باسم',
    mediaHead: 'الوسائط', mediaHint: 'اختر حتى 10 صور. الصورة الأولى هي الأساسية ونصنع منها 3 فيديوهات و4 صور بمقاس 1080×1920 مع الهوامش الآمنة.',
    images: 'الصور', upload: 'رفع', search: 'ابحث في الوسائط', library: 'صور المكتبة', noImages: 'لا توجد صور في المكتبة بعد. ارفع صورة.',
    selected: '{n} من 10 محددة', primary: 'الأساسية', reading: 'نقرأ الصورة…', failed: 'تعذّرت قراءة الصورة',
    textHead: 'النص', textHint: 'اكتب العنوان والرسالة والدعوة، أو طبّق اقتراحًا.', price: 'السعر (اختياري)', hook: 'العنوان', message: 'الرسالة', cta: 'الدعوة',
    words: 'كلمات', apply: 'تطبيق', suggestions: 'اقتراحات النص', suggestionsHint: 'صياغات جاهزة من اسم المنتج والسعر، بدون هاشتاقات ولا إيموجي.', ctaRequired: 'الدعوة مطلوبة',
    designsHead: 'توليد المحتوى', designsHint: 'كل تصميم يُعرض كما سيُحفظ. اختر ما تريد حفظه.', newBatch: 'دفعة جديدة', margins: 'إظهار الهوامش',
    video: 'فيديو', image: 'صورة', ready: 'جاهز', blocked: 'فيه ملاحظة',
    enhanceHead: 'التحسينات', enhanceHint: 'كل التحسينات مقفلة افتراضيًا، ولا نستخدم أي تحسين آلي على المحتوى.',
    enh: {
      price: ['إبراز السعر', 'يضيف السعر الذي كتبته تحت الرسالة.'],
      margins: ['الهوامش الآمنة', 'أعلى 10% وأسفل 20% خالية دائمًا من النص والشعار والدعوة. مفعّلة دائمًا.'],
      translate: ['الترجمة', 'تحتاج مفتاح ذكاء اصطناعي خاص بمساحة العمل. غير مفعّلة.'],
      music: ['موسيقى', 'مكتبة الموسيقى غير مربوطة بعد.'],
    },
    cancel: 'إلغاء', back: 'السابق', next: 'التالي', save: 'حفظ في المكتبة', saving: 'نحفظ…', saved: 'تم الحفظ في مكتبة الوسائط',
    noProject: 'اختر مشروعًا أولًا من أعلى الصفحة.', saveFailed: 'تعذّر الحفظ', videoAsStill: 'متصفحك لا يصدّر الفيديو، سنحفظ الفيديوهات كصور.',
  },
  en: {
    title: 'New content',
    steps: { setup: 'Content setup', media: 'Media', text: 'Text', designs: 'Content generation', enhance: 'Enhancements' },
    setupHead: 'Content setup', setupHint: 'The name as it will appear in the library. It starts with the date automatically.', name: 'Product or campaign name', preview: 'Saved as',
    mediaHead: 'Media', mediaHint: 'Choose up to 10 pictures. The first is the primary one; we make 3 videos and 4 images from it at 1080×1920 with the safe margins.',
    images: 'Images', upload: 'Upload', search: 'Search media', library: 'Library images', noImages: 'No images in the library yet. Upload one.',
    selected: '{n} of 10 selected', primary: 'Primary', reading: 'Reading the picture…', failed: 'Could not read the picture',
    textHead: 'Text', textHint: 'Write the hook, message and call to action, or apply a suggestion.', price: 'Price (optional)', hook: 'Hook', message: 'Message', cta: 'Call to action',
    words: 'words', apply: 'Apply', suggestions: 'Text suggestions', suggestionsHint: 'Ready wordings from the product name and price, with no hashtags or emoji.', ctaRequired: 'A call to action is required',
    designsHead: 'Content generation', designsHint: 'Each design shows as it will be saved. Pick the ones to save.', newBatch: 'New batch', margins: 'Show margins',
    video: 'Video', image: 'Image', ready: 'Ready', blocked: 'Has a note',
    enhanceHead: 'Enhancements', enhanceHint: 'Every enhancement is off by default. No automated enhancement is applied to the content.',
    enh: {
      price: ['Show the price', 'Adds the price you typed under the message.'],
      margins: ['Safe margins', 'The top 10% and bottom 20% always stay free of text, logo and CTA. Always on.'],
      translate: ['Translation', 'Needs the workspace\'s own AI key. Not enabled.'],
      music: ['Music', 'The music library is not connected yet.'],
    },
    cancel: 'Cancel', back: 'Back', next: 'Next', save: 'Save to library', saving: 'Saving…', saved: 'Saved to the media library',
    noProject: 'Choose a project first from the top of the page.', saveFailed: 'Could not save', videoAsStill: 'This browser cannot export video; videos will be saved as images.',
  },
} as const

/** The agency's naming: «25Oct 2026». */
function dateStamp(): string {
  return new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }).replace(/ (\w+) /, '$1 ')
}

function PhoneArt({ children }: { children?: ReactNode }) {
  return <div className="relative h-4/5 w-1/2 overflow-hidden rounded-xl border-2 border-border bg-surface">{children}</div>
}

/**
 * CONTENT-LIBRARY-SNAP-001 — «محتوى جديد», set out the way Meta's Advantage+ creative setup is: the steps across the top with a tick on
 * each one done, one step at a time below, «إلغاء» and «التالي» at the foot. Setup → media (pick from the library or upload, up to 10)
 * → text (fields beside ready suggestions) → generation → enhancements → save. Rendering happens in the browser with the studio's own
 * `drawFrame`; the server only stores (CONTENT-STUDIO-001 batch 8). Every enhancement starts off, and the ones that need a key or a
 * library that is not connected are locked with the reason, never pretended.
 */
export function ContentComposer({ onClose }: { onClose: () => void }) {
  const locale = useUi((s) => s.locale) === 'ar' ? 'ar' : 'en'
  const t = T[locale]
  const projectId = useProject((s) => s.currentProjectId)
  const queryClient = useQueryClient()
  const fileInput = useRef<HTMLInputElement>(null)
  const measure = useMemo(() => canvasMeasure('Cairo, Tajawal, sans-serif'), [])

  const [step, setStep] = useState<Step>('setup')
  const [product, setProduct] = useState('')
  const [search, setSearch] = useState('')
  const [picked, setPicked] = useState<Pick[]>([])
  const [picture, setPicture] = useState<LoadedPicture | null>(null)
  const [failed, setFailed] = useState(false)
  const [price, setPrice] = useState('')
  const [copy, setCopy] = useState<StudioCopy>(() => suggestCopy({}, locale)[0]!)
  const [round, setRound] = useState(0)
  const [margins, setMargins] = useState(false)
  const [chosen, setChosen] = useState<number[]>([])
  const [showPrice, setShowPrice] = useState(false)
  const [saving, setSaving] = useState<{ done: number; total: number } | null>(null)
  const [result, setResult] = useState<'saved' | 'failed' | null>(null)

  const library = useQuery({ queryKey: ['content-intake', 'composer', projectId], queryFn: () => listIntake({ project: projectId }), enabled: step === 'media' })
  const q = search.trim().toLowerCase()
  const images = (library.data?.items ?? []).filter((i) => i.kind === 'image' && i.has_file && i.name.toLowerCase().includes(q))

  // The primary picture is the first one picked; read it whenever that changes.
  const primary = picked[0]
  useEffect(() => {
    if (!primary) { setPicture(null); return }
    let alive = true
    setPicture(null)
    setFailed(false)
    primary.blob().then(loadPixels).then((p) => { if (alive) setPicture(p) }).catch(() => { if (alive) setFailed(true) })

    return () => { alive = false }
  }, [primary])

  const togglePick = (entry: Pick) => setPicked((list) => (
    list.some((p) => p.key === entry.key) ? list.filter((p) => p.key !== entry.key) : list.length >= MAX_MEDIA ? list : [...list, entry]
  ))

  const finalCopy: StudioCopy = useMemo(() => (showPrice && price.trim() ? { ...copy, support: price.trim() } : copy), [copy, showPrice, price])
  const suggestions = useMemo(() => suggestCopy({ name: product || undefined, price: price || undefined }, locale), [product, price, locale])
  const batch = useMemo(() => (picture ? buildStudioBatch({ pixels: picture.pixels, copy: finalCopy, locale, measure, round }) : null), [picture, finalCopy, locale, measure, round])

  // A new batch starts with every design that passed the hard checks selected.
  useEffect(() => {
    if (batch) setChosen(batch.outputs.map((o, i) => (o.hard.length === 0 ? i : -1)).filter((i) => i >= 0))
  }, [batch])

  const index = STEPS.indexOf(step)
  const canNext = step === 'setup' ? product.trim().length > 0
    : step === 'media' ? picture !== null
      : step === 'text' ? copy.cta.trim().length > 0 && copyIsClean(copy)
        : step === 'designs' ? chosen.length > 0 : true
  const baseName = `${dateStamp()} ــ ${product.trim() || 'Creative'}`

  const save = async () => {
    if (!batch || !picture || !projectId) return
    const outputs: BatchOutput[] = chosen.map((i) => batch.outputs[i]!).filter((o) => o.hard.length === 0)
    setSaving({ done: 0, total: outputs.length })
    setResult(null)
    try {
      const session = await openStudioSession(projectId)
      let n = 0
      for (const o of outputs) {
        const asVideo = o.as === 'video' && canExportVideo()
        const blob = asVideo ? await renderVideo(o.scene, picture.bitmap, batch.tokens) : await renderImage(o.scene, picture.bitmap, batch.tokens)
        n += 1
        await saveStudioOutput(session.id, {
          file: blob,
          kind: asVideo ? 'video' : 'image',
          name: `${baseName} ــ ${asVideo ? 'Video' : 'Image'} ${n}`,
          familyKey: o.pick.family.key,
          familyVersion: o.pick.family.version,
          hook: finalCopy.hook,
          message: finalCopy.message,
          cta: finalCopy.cta,
          durationMs: asVideo ? o.scene.durationMs : null,
          score: o.warnings.length === 0 ? 100 : 80,
          tokens: { font_pair: batch.tokens.fontPair.key, accent: batch.tokens.colors.accent, surface: batch.tokens.colors.surface },
          conceptName: product.trim() || 'Creative',
        })
        setSaving({ done: n, total: outputs.length })
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
    <label className="flex flex-col gap-1.5 text-sm font-bold text-text-primary">
      <span className="flex justify-between">{label}<span className="text-xs font-normal text-text-secondary">{words(key)}</span></span>
      <Input value={copy[key]} onChange={(e) => setCopy((c) => ({ ...c, [key]: e.target.value }))} data-testid={`composer-${key}`} />
    </label>
  )

  const card = (title: string, body: string, on: boolean, onChange: ((v: boolean) => void) | null, testid: string, art: ReactNode) => (
    <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-4" data-testid={testid}>
      <label className="flex items-center gap-3 text-sm font-bold text-text-primary">
        <input type="checkbox" role="switch" checked={on} disabled={!onChange} onChange={(e) => onChange?.(e.target.checked)} aria-label={title} className="size-5" />
        {!onChange && <Lock size={14} aria-hidden />}{title}
      </label>
      <div className="flex aspect-[3/4] items-center justify-center overflow-hidden rounded-xl bg-surface-secondary">{art}</div>
      <p className="text-center text-xs text-text-secondary">{body}</p>
    </div>
  )

  const footer = (
    <div className="flex w-full items-center justify-end gap-2">
      <Button variant="secondary" onClick={index === 0 ? onClose : () => setStep(STEPS[index - 1]!)}>{index === 0 ? t.cancel : t.back}</Button>
      {step !== 'enhance'
        ? <Button onClick={() => setStep(STEPS[index + 1]!)} disabled={!canNext} data-testid="composer-next">{t.next}</Button>
        : <Button onClick={() => void save()} disabled={!projectId || saving !== null || chosen.length === 0} data-testid="composer-save">{saving ? `${t.saving} ${formatNumber(saving.done)}/${formatNumber(saving.total)}` : t.save}</Button>}
    </div>
  )

  return (
    <Modal open onClose={onClose} title={t.title} size="xl" footer={footer}>
      <div className="flex flex-col gap-5" data-testid="content-composer">
        <ol className="flex items-center gap-1 overflow-x-auto border-b border-border pb-3" aria-label={t.title}>
          {STEPS.map((s, i) => {
            const done = i < index
            const current = s === step

            return (
              <li key={s} className="flex items-center gap-1">
                {i > 0 && <span className="text-text-muted" aria-hidden>··</span>}
                <button type="button" disabled={i > index} onClick={() => setStep(s)} data-testid={`composer-step-${s}`} aria-current={current ? 'step' : undefined}
                  className={`flex items-center gap-2 whitespace-nowrap rounded-lg px-2.5 py-1.5 text-sm font-bold ${current ? 'bg-brand-600/10 text-brand-600' : done ? 'text-text-primary' : 'text-text-muted'}`}>
                  <span className={`flex size-5 items-center justify-center rounded-full ${done ? 'bg-success text-white' : current ? 'border-2 border-brand-600' : 'border-2 border-border'}`}>
                    {done && <Check size={12} aria-hidden />}
                  </span>
                  {t.steps[s]}
                </button>
              </li>
            )
          })}
        </ol>

        {!projectId && <p className="rounded-lg bg-warning-soft px-3 py-2 text-sm text-warning">{t.noProject}</p>}

        {step === 'setup' && (
          <section className="flex max-w-xl flex-col gap-4">
            <div>
              <h3 className="text-base font-bold text-text-primary">{t.setupHead}</h3>
              <p className="mt-1 text-sm text-text-secondary">{t.setupHint}</p>
            </div>
            <label className="flex flex-col gap-1.5 text-sm font-bold text-text-primary">{t.name}<Input value={product} onChange={(e) => setProduct(e.target.value)} data-testid="composer-product" /></label>
            <p className="text-sm text-text-secondary">{t.preview}: <b className="text-text-primary"><Num>{baseName} ــ Video 1</Num></b></p>
          </section>
        )}

        {step === 'media' && (
          <section className="flex flex-col gap-4">
            <div>
              <h3 className="text-base font-bold text-text-primary">{t.mediaHead}</h3>
              <p className="mt-1 text-sm text-text-secondary">{t.mediaHint}</p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <span className="rounded-xl border border-border bg-surface-secondary px-4 py-2 text-sm font-bold text-text-primary">{t.images}</span>
              <input ref={fileInput} type="file" accept="image/*" multiple hidden data-testid="composer-file" onChange={(e) => {
                for (const f of [...(e.target.files ?? [])]) togglePick({ key: `upload-${f.name}-${f.size}`, blob: async () => f, thumb: URL.createObjectURL(f) })
                e.target.value = ''
              }} />
              <Button variant="secondary" onClick={() => fileInput.current?.click()} data-testid="composer-pick"><Plus size={16} aria-hidden />{t.upload}</Button>
              <label className="flex min-w-48 flex-1 items-center gap-2 rounded-xl border border-border px-3 py-2">
                <Search size={16} className="text-text-muted" aria-hidden />
                <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t.search} aria-label={t.search} className="min-w-0 flex-1 bg-transparent text-sm text-text-primary outline-none placeholder:text-text-muted" />
              </label>
            </div>
            <p className="text-sm font-bold text-text-primary">{t.library}</p>
            {library.isSuccess && images.length === 0 && <p className="text-sm text-text-secondary" data-testid="composer-no-images">{t.noImages}</p>}
            <ul className="grid grid-cols-3 gap-3 sm:grid-cols-5 lg:grid-cols-6" data-testid="composer-library">
              {images.map((i) => {
                const src = intakeFileUrl(i.id)
                const on = picked.some((p) => p.key === i.id)

                return (
                  <li key={i.id}>
                    <button type="button" onClick={() => togglePick({ key: i.id, blob: () => fetch(src, { credentials: 'include' }).then((r) => r.blob()), thumb: src })}
                      data-testid={`composer-lib-${i.id}`} className={`relative block w-full overflow-hidden rounded-xl border-2 ${on ? 'border-brand-600' : 'border-transparent'}`}>
                      <img src={src} alt="" loading="lazy" className={`aspect-[9/16] w-full bg-surface-secondary ${COVER}`} />
                      {on && <span className="absolute end-2 top-2 rounded-full bg-brand-600 p-1 text-white"><Check size={12} aria-hidden /></span>}
                    </button>
                    <p className="mt-1 truncate text-xs text-text-secondary">{i.name}</p>
                  </li>
                )
              })}
            </ul>
            <div className="flex flex-wrap items-center gap-3 border-t border-border pt-3" data-testid="composer-slots">
              <p className="text-sm font-bold text-text-primary">{t.selected.replace('{n}', formatNumber(picked.length))}</p>
              {Array.from({ length: MAX_MEDIA }, (_, n) => {
                const p = picked[n]

                return (
                  <div key={n} className="relative size-14 overflow-hidden rounded-lg border border-dashed border-border">
                    {p ? <img src={p.thumb} alt="" className={`size-full ${COVER}`} /> : <span className="flex size-full items-center justify-center text-text-muted"><Plus size={16} aria-hidden /></span>}
                    {p && n === 0 && <span className="absolute inset-x-0 bottom-0 bg-brand-600 text-center text-xs font-bold text-white">{t.primary}</span>}
                  </div>
                )
              })}
            </div>
            {primary && !picture && !failed && <p className="text-xs text-text-secondary">{t.reading}</p>}
            {failed && <p className="text-xs text-danger" data-testid="composer-failed">{t.failed}</p>}
          </section>
        )}

        {step === 'text' && (
          <section className="grid gap-5 lg:grid-cols-2">
            <div className="flex flex-col gap-4 rounded-2xl border border-border p-4">
              <div>
                <h3 className="text-base font-bold text-text-primary">{t.textHead}</h3>
                <p className="mt-1 text-sm text-text-secondary">{t.textHint}</p>
              </div>
              {field('hook', t.hook)}
              {field('message', t.message)}
              {field('cta', t.cta)}
              {!copy.cta.trim() && <p className="text-xs text-danger">{t.ctaRequired}</p>}
              <label className="flex flex-col gap-1.5 text-sm font-bold text-text-primary">{t.price}<Input value={price} onChange={(e) => setPrice(e.target.value)} data-testid="composer-price" /></label>
            </div>
            <div className="flex flex-col gap-3 rounded-2xl border border-border p-4">
              <div>
                <h3 className="text-base font-bold text-text-primary">{t.suggestions}</h3>
                <p className="mt-1 text-sm text-text-secondary">{t.suggestionsHint}</p>
              </div>
              {suggestions.map((s, i) => (
                <div key={i} className="flex flex-col gap-2 rounded-xl bg-surface-secondary p-3">
                  <p className="text-sm font-bold text-text-primary">{s.hook}</p>
                  <p className="text-sm text-text-primary">{s.message}</p>
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-sm font-semibold text-brand-600">{s.cta}</span>
                    <Button variant="secondary" onClick={() => setCopy(s)} data-testid={`composer-apply-${i}`}>{t.apply}</Button>
                  </div>
                </div>
              ))}
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
            <ul className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7" data-testid="composer-grid">
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
          <section className="flex flex-col gap-4">
            <div>
              <h3 className="text-base font-bold text-text-primary">{t.enhanceHead}</h3>
              <p className="mt-1 text-sm text-text-secondary">{t.enhanceHint}</p>
            </div>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {card(t.enh.price[0], t.enh.price[1], showPrice, price.trim() ? setShowPrice : null, 'composer-enh-price',
                picture ? <canvas className="h-full" ref={(c) => { const ctx = c?.getContext('2d'); if (c && ctx && picture) { c.width = 270; c.height = 480; drawCover(ctx, picture.bitmap, 270, 480) } }} /> : <PhoneArt />)}
              {card(t.enh.margins[0], t.enh.margins[1], true, null, 'composer-enh-margins',
                <PhoneArt><span className="absolute inset-x-0 top-0 h-[10%] bg-danger/20" /><span className="absolute inset-x-0 bottom-0 h-[20%] bg-danger/20" /></PhoneArt>)}
              {card(t.enh.translate[0], t.enh.translate[1], false, null, 'composer-enh-translate', <PhoneArt><span className="absolute inset-x-3 top-1/2 h-2 rounded bg-border" /></PhoneArt>)}
              {card(t.enh.music[0], t.enh.music[1], false, null, 'composer-enh-music', <PhoneArt><span className="absolute inset-x-3 bottom-6 h-6 rounded-full bg-border" /></PhoneArt>)}
            </div>
            {result === 'saved' && <p className="rounded-lg bg-success-soft px-3 py-2 text-sm font-semibold text-success" data-testid="composer-saved">{t.saved}</p>}
            {result === 'failed' && <p className="rounded-lg bg-danger-soft px-3 py-2 text-sm font-semibold text-danger" data-testid="composer-save-failed">{t.saveFailed}</p>}
          </section>
        )}
      </div>
    </Modal>
  )
}
