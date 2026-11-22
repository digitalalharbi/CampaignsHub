import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Check, ChevronDown, ChevronLeft, ChevronRight, Download, Info, Lock, Megaphone, Plus, Search, Trash2, Upload } from 'lucide-react'
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
import { countWords, LIMITS, type StudioCopy } from '../studio/direction/copyRules'
import type { SceneStyle } from '../studio/motion/look'
import type { Scene } from '../studio/motion/scene'
import { canvasMeasure, loadPixels, type LoadedPicture } from '../studio/ui/loadPixels'
import { StudioPreview } from '../studio/ui/StudioPreview'
import { ensureFonts } from '../studio/ui/fonts'
import { canExportVideo, renderImage, renderVideo } from '../studio/export/renderOutput'
import { downloadBlob, zipFiles } from '../studio/export/zip'
import { openStudioSession, saveStudioOutput } from '../studio/studioApi'
import { CTA_OPTIONS, scoreText, strategies } from './textStrategies'

type Step = 'setup' | 'media' | 'text' | 'designs' | 'enhance'
const STEPS: Step[] = ['setup', 'media', 'text', 'designs', 'enhance']
const MAX_MEDIA = 10
const MAX_OPTIONS = 5
const COVER = mediaFitClass(null, null, 'cover')

interface Pick { key: string; blob: () => Promise<Blob>; thumb: string }
type EnhKey = 'stickerCta' | 'touchUps' | 'textImprove' | 'comments' | 'enhanceCta' | 'details' | 'videoFx' | 'browsing'

const T = {
  ar: {
    title: 'محتوى جديد',
    steps: { setup: 'إعداد المحتوى', media: 'الوسائط', text: 'النص', designs: 'توليد المحتوى', enhance: 'التحسينات' },
    setupHead: 'إعداد المحتوى', setupHint: 'اسم المحتوى كما سيظهر في المكتبة، ويبدأ بالتاريخ تلقائيًا.', name: 'اسم المنتج أو الحملة', preview: 'سيُحفظ باسم',
    mediaHead: 'الوسائط', mediaPick: 'اختر حتى 10 صور', mediaHint: 'الصورة الأولى هي الأساسية، ومنها نصنع 3 فيديوهات و4 صور بمقاس 1080×1920 مع الهوامش الآمنة.',
    images: 'الصور', upload: 'رفع', search: 'ابحث في الوسائط', library: 'صور المكتبة', noImages: 'لا توجد صور في المكتبة بعد.',
    drop: 'اسحب الصور وأفلتها هنا أو', dropBrowse: 'اختر من جهازك', dropNow: 'أفلت الصور هنا',
    selected: '{n} من 10 محددة', fromYou: 'وسائط منك', primary: 'الأساسية', reading: 'نقرأ الصورة…', failed: 'تعذّرت قراءة الصورة',
    textHead: 'النص', textHint: 'أضف أكثر من خيار نص، ونعرض كل خيار على تصميم مختلف.',
    primaryText: 'النص الأساسي', addPrimary: 'إضافة خيار نص', headline: 'العنوان', addHeadline: 'إضافة خيار عنوان',
    description: 'الوصف', descriptionPh: 'أضف تفاصيل إضافية', price: 'السعر (اختياري)', cta: 'الدعوة',
    ctaNote: 'الدعوة مطلوبة في كل تصميم', ctaNoteBody: 'تظهر أسفل التصميم داخل الهامش الآمن، ولا تُحذف من أي تصميم.',
    words: 'كلمات', remove: 'حذف',
    genHead: 'توليد النص', scoreHead: 'جاهزية النص', scoreBody: 'تقييم من القواعد المعتمدة: الدعوة، طول السطر، تعدد الخيارات، رقم أو سعر، بدون هاشتاقات أو إيموجي.',
    applyAll: 'تطبيق كل الاقتراحات', applyHeadlines: 'تطبيق العناوين', applyPrimary: 'تطبيق النص الأساسي',
    excellent: 'ممتاز', good: 'جيد', weak: 'يحتاج تحسين',
    designsHead: 'توليد المحتوى', designsHint: 'كل تصميم يُعرض كما سيُحفظ. اختر ما تريد حفظه.', newBatch: 'دفعة جديدة', margins: 'إظهار الهوامش',
    video: 'فيديو', image: 'صورة', ready: 'جاهز', blocked: 'فيه ملاحظة',
    enhanceHead: 'تحسينات المحتوى', enhanceHint: 'كل التحسينات مقفلة افتراضيًا. ما يُفعّل منها يُرسم داخل التصميم نفسه، بدون أي ذكاء اصطناعي.',
    enh: {
      stickerCta: ['ملصق الدعوة', 'يحوّل الدعوة إلى ملصق أبيض بعلامة رابط بدل الزر الملوّن.'],
      touchUps: ['تهيئة المقاسات', 'نسخ 4:5 و1:1 لمواضع أكثر. قريبًا.'],
      textImprove: ['تحسينات النص', 'يوزّع خيارات العناوين والنصوص على التصاميم، كل تصميم بخيار.'],
      comments: ['التعليقات ذات الصلة', 'تعمل داخل المنصة عند النشر، وليست جزءًا من التصميم.'],
      enhanceCta: ['تحسين الدعوة', 'يضيف الوصف سطرًا مساندًا فوق الدعوة.'],
      details: ['إضافة تفاصيل للتصميم', 'يضيف السعر كملصق دائري على التصميم.'],
      videoFx: ['تأثيرات الفيديو', 'يرفع التباين والألوان قليلًا على الصورة نفسها، وليس على النص.'],
      browsing: ['تصفح المنتجات', 'يحتاج كتالوج منتجات مربوط بالمنصة. غير متاح هنا.'],
    } satisfies Record<EnhKey, [string, string]>,
    example: 'مثال {i} من {n}', soon: 'قريبًا', platformOnly: 'داخل المنصة', needsPrice: 'اكتب السعر في خطوة النص', needsDescription: 'اكتب الوصف في خطوة النص', needsOptions: 'أضف خيار عنوان أو نص ثانيًا',
    cancel: 'إلغاء', back: 'السابق', next: 'التالي', save: 'حفظ في المكتبة', saving: 'نحفظ…',
    saved: 'حُفظت {n} ملفات في مكتبة الوسائط.', downloadAll: 'تحميل الكل (zip)', startCampaign: 'بدء حملة بهذه المحتويات',
    pushHead: 'إرسال إلى مكتبات المنصات', pushBody: 'رفع الملفات مباشرة إلى مكتبة ميتا وسناب وتيك توك يحتاج صلاحية كتابة على كل حساب إعلاني. غير مفعّل بعد.',
    noProject: 'اختر مشروعًا أولًا من أعلى الصفحة.', saveFailed: 'تعذّر الحفظ', videoAsStill: 'متصفحك لا يصدّر الفيديو، سنحفظ الفيديوهات كصور.',
  },
  en: {
    title: 'New content',
    steps: { setup: 'Content setup', media: 'Media', text: 'Text', designs: 'Content generation', enhance: 'Enhancements' },
    setupHead: 'Content setup', setupHint: 'The name as it will appear in the library; it starts with the date automatically.', name: 'Product or campaign name', preview: 'Saved as',
    mediaHead: 'Media', mediaPick: 'Choose up to 10 pictures', mediaHint: 'The first is the primary one; we make 3 videos and 4 images from it at 1080×1920 with the safe margins.',
    images: 'Images', upload: 'Upload', search: 'Search media', library: 'Library images', noImages: 'No images in the library yet.',
    drop: 'Drag and drop pictures here or', dropBrowse: 'browse your device', dropNow: 'Drop the pictures here',
    selected: '{n} of 10 selected', fromYou: 'Media from you', primary: 'Primary', reading: 'Reading the picture…', failed: 'Could not read the picture',
    textHead: 'Text', textHint: 'Add more than one text option; each design carries a different one.',
    primaryText: 'Primary text', addPrimary: 'Add text option', headline: 'Headline', addHeadline: 'Add headline option',
    description: 'Description', descriptionPh: 'Include additional details', price: 'Price (optional)', cta: 'Call to action',
    ctaNote: 'A call to action is required on every design', ctaNoteBody: 'It sits at the foot of the design inside the safe margin, and is never dropped.',
    words: 'words', remove: 'Remove',
    genHead: 'Text generation', scoreHead: 'Text readiness', scoreBody: 'Scored by the agency rules: a CTA, line length, more than one option, a number or price, no hashtags or emoji.',
    applyAll: 'Apply all suggestions', applyHeadlines: 'Apply headlines', applyPrimary: 'Apply primary text',
    excellent: 'Excellent', good: 'Good', weak: 'Needs work',
    designsHead: 'Content generation', designsHint: 'Each design shows as it will be saved. Pick the ones to save.', newBatch: 'New batch', margins: 'Show margins',
    video: 'Video', image: 'Image', ready: 'Ready', blocked: 'Has a note',
    enhanceHead: 'Content enhancements', enhanceHint: 'Every enhancement is off by default. The ones you switch on are drawn into the design itself, with no AI.',
    enh: {
      stickerCta: ['Sticker CTA', 'Turns the call to action into a white sticker with a link mark.'],
      touchUps: ['Placement sizes', '4:5 and 1:1 copies for more placements. Coming soon.'],
      textImprove: ['Text improvements', 'Spreads your headline and text options across the designs, one each.'],
      comments: ['Relevant comments', 'Works inside the platform at delivery; not part of the design.'],
      enhanceCta: ['Enhance CTA', 'Adds the description as a supporting line above the call to action.'],
      details: ['Add details to the design', 'Adds the price as a round sticker on the design.'],
      videoFx: ['Video effects', 'A little more contrast and colour on the picture, never on the text.'],
      browsing: ['Product browsing', 'Needs a product catalogue connected to the platform. Not available here.'],
    } satisfies Record<EnhKey, [string, string]>,
    example: 'Example {i} of {n}', soon: 'Coming soon', platformOnly: 'In the platform', needsPrice: 'Type the price in the text step', needsDescription: 'Type the description in the text step', needsOptions: 'Add a second headline or text option',
    cancel: 'Cancel', back: 'Back', next: 'Next', save: 'Save to library', saving: 'Saving…',
    saved: '{n} files saved to the media library.', downloadAll: 'Download all (zip)', startCampaign: 'Start a campaign with these',
    pushHead: 'Send to the platforms\' libraries', pushBody: 'Uploading straight to the Meta, Snapchat and TikTok libraries needs write access on each ad account. Not enabled yet.',
    noProject: 'Choose a project first from the top of the page.', saveFailed: 'Could not save', videoAsStill: 'This browser cannot export video; videos will be saved as images.',
  },
} as const

/** The agency's naming: «25Oct 2026». */
function dateStamp(): string {
  return new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }).replace(/ (\w+) /, '$1 ')
}

function ScoreRing({ score }: { score: number }) {
  const r = 26
  const c = 2 * Math.PI * r
  const color = score >= 80 ? 'var(--color-success, #16a34a)' : score >= 50 ? 'var(--color-warning, #d97706)' : 'var(--color-danger, #dc2626)'

  return (
    <svg viewBox="0 0 64 64" className="size-16 shrink-0" aria-hidden>
      <circle cx="32" cy="32" r={r} fill="none" stroke="currentColor" strokeOpacity="0.15" strokeWidth="7" />
      <circle cx="32" cy="32" r={r} fill="none" stroke={color} strokeWidth="7" strokeLinecap="round" strokeDasharray={`${(score / 100) * c} ${c}`} transform="rotate(-90 32 32)" />
    </svg>
  )
}

/**
 * CONTENT-LIBRARY-SNAP-001 — «محتوى جديد», laid out as Meta's Advantage+ creative setup: steps across the top, one step at a time,
 * «إلغاء» and «التالي» at the foot.
 *
 * - Media: drag and drop from the device, upload, or pick from the library; up to 10, the first is primary.
 * - Text: several primary texts and headlines, a description and a CTA list on one side; on the other a readiness ring scored from the
 *   agency's own rules and strategies whose headlines and texts apply with a tick. Built from what was typed, never a model.
 * - Enhancements: the platform's cards, each previewed on the reader's own design. The ones the studio really draws switch on; the ones
 *   that live inside a platform (comments, catalogue) or are not built yet say so and stay locked. All start off.
 * - After saving: download everything as one zip, or go on to a campaign draft. Nothing is published.
 */
export function ContentComposer({ onClose }: { onClose: () => void }) {
  const locale = useUi((s) => s.locale) === 'ar' ? 'ar' : 'en'
  const t = T[locale]
  const navigate = useNavigate()
  const projectId = useProject((s) => s.currentProjectId)
  const queryClient = useQueryClient()
  const fileInput = useRef<HTMLInputElement>(null)
  const measure = useMemo(() => canvasMeasure('Cairo, Tajawal, sans-serif'), [])

  const [step, setStep] = useState<Step>('setup')
  const [product, setProduct] = useState('')
  const [search, setSearch] = useState('')
  const [dragging, setDragging] = useState(false)
  const [picked, setPicked] = useState<Pick[]>([])
  const [picture, setPicture] = useState<LoadedPicture | null>(null)
  const [failed, setFailed] = useState(false)
  const [primaries, setPrimaries] = useState<string[]>([''])
  const [headlines, setHeadlines] = useState<string[]>([''])
  const [description, setDescription] = useState('')
  const [price, setPrice] = useState('')
  const [cta, setCta] = useState<string>(CTA_OPTIONS[locale][0])
  const [open, setOpen] = useState<string | null>(null)
  const [round, setRound] = useState(0)
  const [margins, setMargins] = useState(false)
  const [chosen, setChosen] = useState<number[]>([])
  const [enh, setEnh] = useState<Record<EnhKey, boolean>>({ stickerCta: false, touchUps: false, textImprove: false, comments: false, enhanceCta: false, details: false, videoFx: false, browsing: false })
  const [example, setExample] = useState(0)
  const [saving, setSaving] = useState<{ done: number; total: number } | null>(null)
  const [savedFiles, setSavedFiles] = useState<Array<{ name: string; blob: Blob }> | null>(null)
  const [failedSave, setFailedSave] = useState(false)

  const library = useQuery({ queryKey: ['content-intake', 'composer', projectId], queryFn: () => listIntake({ project: projectId }), enabled: step === 'media' })
  const q = search.trim().toLowerCase()
  const images = (library.data?.items ?? []).filter((i) => i.kind === 'image' && i.has_file && i.name.toLowerCase().includes(q))

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
  const addFiles = (files: File[]) => {
    for (const f of files.filter((x) => x.type.startsWith('image/'))) togglePick({ key: `upload-${f.name}-${f.size}`, blob: async () => f, thumb: URL.createObjectURL(f) })
  }

  // Text → studio copies. The support line carries the price and/or the description, each only when its enhancement is on.
  // A price always carries its currency: a bare number typed here reads «199 ر.س».
  const priced = /^[\d.,\u0660-\u0669]+$/.test(price.trim()) ? `${price.trim()} ${locale === 'ar' ? 'ر.س' : 'SAR'}` : price.trim()
  const support = [enh.details ? priced : '', enh.enhanceCta ? description.trim() : ''].filter(Boolean).join(' · ')
  const prims = primaries.map((s) => s.trim()).filter(Boolean)
  const heads = headlines.map((s) => s.trim()).filter(Boolean)
  const copyAt = (i: number): StudioCopy => ({
    hook: heads[i % Math.max(1, heads.length)] ?? '',
    message: prims[i % Math.max(1, prims.length)] ?? '',
    cta,
    ...(support ? { support } : {}),
  })
  const variantCount = Math.max(heads.length, prims.length, 1)
  const variants = useMemo(() => (enh.textImprove ? Array.from({ length: variantCount }, (_, i) => copyAt(i)) : undefined), [enh.textImprove, variantCount, primaries, headlines, cta, support]) // eslint-disable-line react-hooks/exhaustive-deps
  const baseCopy = copyAt(0)
  const style: Omit<SceneStyle, 'look'> = { ctaSticker: enh.stickerCta, colorBoost: enh.videoFx }

  const batch = useMemo(
    () => (picture ? buildStudioBatch({ pixels: picture.pixels, copy: baseCopy, variants, style, locale, measure, round }) : null),
    [picture, baseCopy.hook, baseCopy.message, baseCopy.cta, baseCopy.support, variants, enh.stickerCta, enh.videoFx, locale, measure, round], // eslint-disable-line react-hooks/exhaustive-deps
  )

  // The design's fonts are fetched before the previews are trusted; when they arrive every canvas draws again in the real face.
  const [fontsTick, setFontsTick] = useState(0)
  const pairKey = batch?.tokens.fontPair.key
  useEffect(() => {
    if (!batch) return
    let alive = true
    void ensureFonts(batch.tokens.fontPair).then(() => { if (alive) setFontsTick((n) => n + 1) })

    return () => { alive = false }
  }, [pairKey]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (batch) setChosen(batch.outputs.map((o, i) => (o.hard.length === 0 ? i : -1)).filter((i) => i >= 0))
  }, [batch])

  const facts = { product, price, description }
  const strats = useMemo(() => strategies(facts, locale), [product, price, description, locale]) // eslint-disable-line react-hooks/exhaustive-deps
  const score = scoreText({ primaries, headlines, description, cta }, locale)
  const addOptions = (list: string[], set: (v: string[]) => void, more: string[]) => set([...new Set([...list.filter((s) => s.trim() !== ''), ...more])].slice(0, MAX_OPTIONS))

  const index = STEPS.indexOf(step)
  const canNext = step === 'setup' ? product.trim().length > 0
    : step === 'media' ? picture !== null
      : step === 'text' ? heads.length > 0 && cta.trim() !== '' && heads.every((h) => countWords(h) <= LIMITS.hook) && prims.every((p) => countWords(p) <= LIMITS.message)
        : step === 'designs' ? chosen.length > 0 : true
  const baseName = `${dateStamp()} ــ ${product.trim() || 'Creative'}`

  const save = async () => {
    if (!batch || !picture || !projectId) return
    const outputs: BatchOutput[] = chosen.map((i) => batch.outputs[i]!).filter((o) => o.hard.length === 0)
    setSaving({ done: 0, total: outputs.length })
    setFailedSave(false)
    const files: Array<{ name: string; blob: Blob }> = []
    try {
      await ensureFonts(batch.tokens.fontPair)
      const session = await openStudioSession(projectId)
      let n = 0
      for (const o of outputs) {
        const asVideo = o.as === 'video' && canExportVideo()
        const blob = asVideo ? await renderVideo(o.scene, picture.bitmap, batch.tokens) : await renderImage(o.scene, picture.bitmap, batch.tokens)
        n += 1
        const name = `${baseName} ــ ${asVideo ? 'Video' : 'Image'} ${n}`
        const used = copyAt(enh.textImprove ? batch.outputs.indexOf(o) : 0)
        await saveStudioOutput(session.id, {
          file: blob, kind: asVideo ? 'video' : 'image', name,
          familyKey: o.pick.family.key, familyVersion: o.pick.family.version,
          hook: used.hook, message: used.message, cta: used.cta,
          durationMs: asVideo ? o.scene.durationMs : null,
          score: o.warnings.length === 0 ? 100 : 80,
          tokens: { font_pair: batch.tokens.fontPair.key, accent: batch.tokens.colors.accent, surface: batch.tokens.colors.surface },
          conceptName: product.trim() || 'Creative',
        })
        files.push({ name: `${name}.${asVideo ? 'mp4' : 'png'}`, blob })
        setSaving({ done: n, total: outputs.length })
      }
      void queryClient.invalidateQueries({ queryKey: ['content-intake'] })
      setSavedFiles(files)
    } catch {
      setFailedSave(true)
      if (files.length > 0) setSavedFiles(files)
    } finally {
      setSaving(null)
    }
  }

  const optionList = (label: string, list: string[], set: (v: string[]) => void, limit: number, add: string, testid: string) => (
    <div className="flex flex-col gap-2">
      <p className="flex items-center gap-1.5 text-sm font-bold text-text-primary">{label}<Info size={14} className="text-text-muted" aria-hidden /></p>
      {list.map((value, i) => (
        <div key={i} className="flex items-center gap-2">
          <div className="relative flex-1">
            <Input value={value} onChange={(e) => set(list.map((v, j) => (j === i ? e.target.value : v)))} data-testid={`${testid}-${i}`} />
            <span className={`absolute end-3 top-1/2 -translate-y-1/2 text-xs ${countWords(value) > limit ? 'text-danger' : 'text-text-muted'}`}><Num>{formatNumber(countWords(value))}/{formatNumber(limit)}</Num></span>
          </div>
          {list.length > 1 && <button type="button" onClick={() => set(list.filter((_, j) => j !== i))} aria-label={t.remove} className="text-text-muted hover:text-danger"><Trash2 size={16} aria-hidden /></button>}
        </div>
      ))}
      {list.length < MAX_OPTIONS && (
        <button type="button" onClick={() => set([...list, ''])} data-testid={`${testid}-add`} className="flex w-fit items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-semibold text-text-primary hover:bg-surface-secondary">
          <Plus size={16} aria-hidden />{add}
        </button>
      )}
    </div>
  )

  // The design every enhancement card previews: the first image design, re-dressed with that one enhancement switched on.
  const sample = batch?.outputs.find((o) => o.as === 'image') ?? batch?.outputs[0]
  const previewWith = (key: EnhKey): ReactNode => {
    if (!sample || !picture || !batch) return null
    const s = { ...sample.scene.style!, ...(key === 'stickerCta' ? { ctaSticker: true } : {}), ...(key === 'videoFx' ? { colorBoost: true } : {}) }
    let scene: Scene = { ...sample.scene, style: s }
    if (key === 'textImprove' || key === 'details' || key === 'enhanceCta') {
      const v = key === 'textImprove' ? copyAt(example) : { ...copyAt(0), support: key === 'details' ? priced : description.trim() }
      const one = buildStudioBatch({ pixels: picture.pixels, copy: v, style: s, locale, measure, round, analysis: batch.analysis, tokens: batch.tokens, videos: 0, images: 4 })
      scene = one.outputs.find((o) => o.pick.family.key === sample.pick.family.key)?.scene ?? one.outputs[0]!.scene
    }

    return <StudioPreview key={fontsTick} scene={scene} bitmap={picture.bitmap} tokens={batch.tokens} showMargins={false} live={false} />
  }

  const ENH: Array<{ key: EnhKey; state: 'on' | 'soon' | 'platform' | 'needs'; need?: string; art: 'design' | 'sizes' | 'comments' | 'catalog' }> = [
    { key: 'stickerCta', state: 'on', art: 'design' },
    { key: 'touchUps', state: 'soon', art: 'sizes' },
    { key: 'textImprove', state: variantCount > 1 ? 'on' : 'needs', need: t.needsOptions, art: 'design' },
    { key: 'comments', state: 'platform', art: 'comments' },
    { key: 'enhanceCta', state: description.trim() ? 'on' : 'needs', need: t.needsDescription, art: 'design' },
    { key: 'details', state: price.trim() ? 'on' : 'needs', need: t.needsPrice, art: 'design' },
    { key: 'videoFx', state: 'on', art: 'design' },
    { key: 'browsing', state: 'platform', art: 'catalog' },
  ]

  const footer = savedFiles ? (
    <div className="flex w-full items-center justify-end gap-2">
      <Button variant="secondary" onClick={onClose}>{t.cancel}</Button>
    </div>
  ) : (
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
            const done = i < index || savedFiles !== null
            const current = s === step && savedFiles === null

            return (
              <li key={s} className="flex items-center gap-1">
                {i > 0 && <span className="text-text-muted" aria-hidden>··</span>}
                <button type="button" disabled={i > index || savedFiles !== null} onClick={() => setStep(s)} data-testid={`composer-step-${s}`} aria-current={current ? 'step' : undefined}
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

        {savedFiles && (
          <section className="flex flex-col gap-4" data-testid="composer-done">
            <p className="rounded-lg bg-success-soft px-3 py-2 text-sm font-semibold text-success" data-testid="composer-saved">{t.saved.replace('{n}', formatNumber(savedFiles.length))}</p>
            {failedSave && <p className="rounded-lg bg-danger-soft px-3 py-2 text-sm font-semibold text-danger" data-testid="composer-save-failed">{t.saveFailed}</p>}
            <div className="flex flex-wrap gap-2">
              <Button onClick={() => void zipFiles(savedFiles).then((z) => downloadBlob(z, `${baseName}.zip`))} data-testid="composer-download-all"><Download size={16} aria-hidden />{t.downloadAll}</Button>
              <Button variant="secondary" onClick={() => { onClose(); navigate('../launch', { relative: 'path' }) }} data-testid="composer-campaign"><Megaphone size={16} aria-hidden />{t.startCampaign}</Button>
            </div>
            <div className="flex items-start gap-3 rounded-xl border border-border p-4" data-testid="composer-push">
              <Lock size={18} className="mt-0.5 text-text-muted" aria-hidden />
              <div>
                <p className="text-sm font-bold text-text-primary">{t.pushHead}</p>
                <p className="mt-1 text-sm text-text-secondary">{t.pushBody}</p>
              </div>
            </div>
          </section>
        )}

        {!savedFiles && step === 'setup' && (
          <section className="flex max-w-xl flex-col gap-4">
            <div>
              <h3 className="text-base font-bold text-text-primary">{t.setupHead}</h3>
              <p className="mt-1 text-sm text-text-secondary">{t.setupHint}</p>
            </div>
            <label className="flex flex-col gap-1.5 text-sm font-bold text-text-primary">{t.name}<Input value={product} onChange={(e) => setProduct(e.target.value)} data-testid="composer-product" /></label>
            <p className="text-sm text-text-secondary">{t.preview}: <b className="text-text-primary"><Num>{baseName} ــ Video 1</Num></b></p>
          </section>
        )}

        {!savedFiles && step === 'media' && (
          <section className="flex flex-col gap-4">
            <div>
              <h3 className="text-base font-bold text-text-primary">{t.mediaHead} · {t.mediaPick}</h3>
              <p className="mt-1 text-sm text-text-secondary">{t.mediaHint}</p>
            </div>
            <input ref={fileInput} type="file" accept="image/*" multiple hidden data-testid="composer-file" onChange={(e) => { addFiles([...(e.target.files ?? [])]); e.target.value = '' }} />
            <div
              onDragOver={(e) => { e.preventDefault(); setDragging(true) }}
              onDragLeave={() => setDragging(false)}
              onDrop={(e) => { e.preventDefault(); setDragging(false); addFiles([...e.dataTransfer.files]) }}
              data-testid="composer-drop"
              className={`flex flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed p-6 text-center ${dragging ? 'border-brand-600 bg-brand-600/5' : 'border-border'}`}
            >
              <Upload size={22} className="text-text-muted" aria-hidden />
              <p className="text-sm text-text-primary">{dragging ? t.dropNow : <>{t.drop} <button type="button" onClick={() => fileInput.current?.click()} className="font-bold text-brand-600 underline" data-testid="composer-pick">{t.dropBrowse}</button></>}</p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <span className="rounded-xl border border-border bg-surface-secondary px-4 py-2 text-sm font-bold text-text-primary">{t.images}</span>
              <label className="flex min-w-48 flex-1 items-center gap-2 rounded-xl border border-border px-3 py-2">
                <Search size={16} className="text-text-muted" aria-hidden />
                <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t.search} aria-label={t.search} className="min-w-0 flex-1 bg-transparent text-sm text-text-primary outline-none placeholder:text-text-muted" />
              </label>
            </div>
            <p className="text-sm font-bold text-text-primary">{t.library}</p>
            {library.isSuccess && images.length === 0 && <p className="text-sm text-text-secondary" data-testid="composer-no-images">{t.noImages}</p>}
            <ul className="grid grid-cols-3 gap-3 sm:grid-cols-5 lg:grid-cols-8" data-testid="composer-library">
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
                    {i.width && i.height ? <p className="text-xs text-text-muted"><Num>{i.width} × {i.height}</Num></p> : null}
                  </li>
                )
              })}
            </ul>
            <div className="flex flex-wrap items-center gap-3 border-t border-border pt-3" data-testid="composer-slots">
              <div>
                <p className="text-sm font-bold text-text-primary">{t.selected.replace('{n}', formatNumber(picked.length))}</p>
                <p className="text-xs text-text-secondary">{t.fromYou}</p>
              </div>
              {Array.from({ length: MAX_MEDIA }, (_, n) => {
                const p = picked[n]

                return (
                  <div key={n} className="relative size-14 overflow-hidden rounded-lg border border-dashed border-border">
                    {p ? <img src={p.thumb} alt="" className={`size-full ${COVER}`} /> : <button type="button" onClick={() => fileInput.current?.click()} aria-label={t.upload} className="flex size-full items-center justify-center text-text-muted"><Plus size={16} aria-hidden /></button>}
                    {p && n === 0 && <span className="absolute inset-x-0 bottom-0 bg-brand-600 text-center text-xs font-bold text-white">{t.primary}</span>}
                  </div>
                )
              })}
            </div>
            {primary && !picture && !failed && <p className="text-xs text-text-secondary">{t.reading}</p>}
            {failed && <p className="text-xs text-danger" data-testid="composer-failed">{t.failed}</p>}
          </section>
        )}

        {!savedFiles && step === 'text' && (
          <section className="flex flex-col gap-4">
            <div>
              <h3 className="text-base font-bold text-text-primary">{t.textHead}</h3>
              <p className="mt-1 text-sm text-text-secondary">{t.textHint}</p>
            </div>
            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
              <div className="flex flex-col gap-5 rounded-2xl border border-border p-5">
                {optionList(t.primaryText, primaries, setPrimaries, LIMITS.message, t.addPrimary, 'composer-primary')}
                {optionList(t.headline, headlines, setHeadlines, LIMITS.hook, t.addHeadline, 'composer-headline')}
                <label className="flex flex-col gap-1.5 text-sm font-bold text-text-primary">
                  <span className="flex items-center gap-1.5">{t.description}<Info size={14} className="text-text-muted" aria-hidden /></span>
                  <textarea value={description} onChange={(e) => setDescription(e.target.value)} placeholder={t.descriptionPh} rows={3} data-testid="composer-description"
                    className="rounded-xl border border-border bg-surface px-3 py-2 text-sm font-normal text-text-primary outline-none placeholder:text-text-muted focus:border-brand-600" />
                </label>
                <label className="flex flex-col gap-1.5 text-sm font-bold text-text-primary">{t.price}<Input value={price} onChange={(e) => setPrice(e.target.value)} data-testid="composer-price" /></label>
                <label className="flex flex-col gap-1.5 text-sm font-bold text-text-primary">
                  <span className="flex items-center gap-1.5">{t.cta}<Info size={14} className="text-text-muted" aria-hidden /></span>
                  <select value={cta} onChange={(e) => setCta(e.target.value)} data-testid="composer-cta" className="rounded-xl border border-border bg-surface px-3 py-2.5 text-sm font-normal text-text-primary">
                    {CTA_OPTIONS[locale].map((c) => <option key={c} value={c}>{c}</option>)}
                  </select>
                </label>
                <div className="flex gap-3 rounded-xl border-s-4 border-brand-600 bg-surface-secondary p-3">
                  <Info size={16} className="mt-0.5 shrink-0 text-text-secondary" aria-hidden />
                  <div>
                    <p className="text-sm font-bold text-text-primary">{t.ctaNote}</p>
                    <p className="mt-1 text-xs text-text-secondary">{t.ctaNoteBody}</p>
                  </div>
                </div>
              </div>

              <div className="flex flex-col gap-4 rounded-2xl border border-border p-5" data-testid="composer-generation">
                <h3 className="text-base font-bold text-text-primary">{t.genHead}</h3>
                <div className="flex items-center gap-4" data-testid="composer-score">
                  <div className="relative">
                    <ScoreRing score={score.score} />
                    <span className="absolute inset-0 flex items-center justify-center text-sm font-bold text-text-primary"><Num>{formatNumber(score.score)}</Num></span>
                  </div>
                  <div className="min-w-0">
                    <p className="text-sm font-bold text-text-primary">{t.scoreHead} · {score.score >= 80 ? t.excellent : score.score >= 50 ? t.good : t.weak}</p>
                    <p className="mt-1 text-xs text-text-secondary">{t.scoreBody}</p>
                    {score.tips.length > 0 && <p className="mt-1 text-xs text-warning">{score.tips.join(' · ')}</p>}
                  </div>
                </div>
                <Button className="w-fit" onClick={() => { addOptions(headlines, setHeadlines, strats.flatMap((s) => s.headlines)); addOptions(primaries, setPrimaries, strats.flatMap((s) => s.primaries)) }} data-testid="composer-apply-all">{t.applyAll}</Button>
                {strats.map((s) => {
                  const expanded = open === s.key

                  return (
                    <div key={s.key} className="overflow-hidden rounded-xl bg-surface-secondary" data-testid={`composer-strategy-${s.key}`}>
                      <div className="flex items-start gap-3 p-3">
                        <input type="checkbox" aria-label={s.title} className="mt-1 size-5"
                          checked={s.headlines.every((h) => headlines.includes(h)) && s.primaries.every((p) => primaries.includes(p))}
                          onChange={(e) => {
                            if (e.target.checked) { addOptions(headlines, setHeadlines, s.headlines); addOptions(primaries, setPrimaries, s.primaries) } else {
                              setHeadlines((l) => { const r = l.filter((h) => !s.headlines.includes(h)); return r.length ? r : [''] })
                              setPrimaries((l) => { const r = l.filter((p) => !s.primaries.includes(p)); return r.length ? r : [''] })
                            }
                          }} />
                        <div className="min-w-0 flex-1">
                          <p className="text-sm font-bold text-text-primary">{s.title}</p>
                          <p className="text-xs text-text-secondary">{s.why}</p>
                        </div>
                        <button type="button" onClick={() => setOpen(expanded ? null : s.key)} aria-expanded={expanded} className="text-text-muted hover:text-text-primary"><ChevronDown size={18} className={expanded ? 'rotate-180' : ''} aria-hidden /></button>
                      </div>
                      {expanded && (
                        <div className="flex flex-col gap-2 border-t border-border bg-surface p-3">
                          <label className="flex items-center gap-2 text-sm font-semibold text-text-primary">
                            <input type="checkbox" className="size-4" checked={s.headlines.every((h) => headlines.includes(h))} onChange={(e) => (e.target.checked ? addOptions(headlines, setHeadlines, s.headlines) : setHeadlines((l) => { const r = l.filter((h) => !s.headlines.includes(h)); return r.length ? r : [''] }))} />
                            {t.applyHeadlines}
                          </label>
                          {s.headlines.map((h) => <p key={h} className="rounded-lg bg-surface-secondary px-3 py-2 text-sm text-text-primary">{h}</p>)}
                          <label className="mt-2 flex items-center gap-2 text-sm font-semibold text-text-primary">
                            <input type="checkbox" className="size-4" checked={s.primaries.every((p) => primaries.includes(p))} onChange={(e) => (e.target.checked ? addOptions(primaries, setPrimaries, s.primaries) : setPrimaries((l) => { const r = l.filter((p) => !s.primaries.includes(p)); return r.length ? r : [''] }))} />
                            {t.applyPrimary}
                          </label>
                          {s.primaries.map((p) => <p key={p} className="rounded-lg bg-surface-secondary px-3 py-2 text-sm text-text-primary">{p}</p>)}
                        </div>
                      )}
                    </div>
                  )
                })}
              </div>
            </div>
          </section>
        )}

        {!savedFiles && step === 'designs' && batch && (
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
                      <StudioPreview key={`${i}-${fontsTick}`} scene={o.scene} bitmap={picture?.bitmap ?? null} tokens={batch.tokens} showMargins={margins} live={o.as === 'video' && i < 3} />
                      {on && <span className="absolute end-2 top-2 rounded-full bg-brand-600 p-1 text-white"><Check size={12} aria-hidden /></span>}
                    </button>
                    <p className="mt-1 flex items-center gap-2 text-xs"><b className="text-text-primary">{o.as === 'video' ? t.video : t.image}</b><span className={ok ? 'text-success' : 'text-danger'}>{ok ? t.ready : t.blocked}</span></p>
                  </li>
                )
              })}
            </ul>
          </section>
        )}

        {!savedFiles && step === 'enhance' && (
          <section className="flex flex-col gap-4">
            <div>
              <h3 className="text-base font-bold text-text-primary">{t.enhanceHead}</h3>
              <p className="mt-1 text-sm text-text-secondary">{t.enhanceHint}</p>
            </div>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {ENH.map(({ key, state, need, art }) => {
                const [title, body] = t.enh[key]
                const usable = state === 'on'
                const badge = state === 'soon' ? t.soon : state === 'platform' ? t.platformOnly : state === 'needs' ? need : null

                return (
                  <div key={key} className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-4" data-testid={`composer-enh-${key}`}>
                    <label className="flex items-center gap-3 text-sm font-bold text-text-primary">
                      <input type="checkbox" role="switch" checked={usable && enh[key]} disabled={!usable} onChange={(e) => setEnh((m) => ({ ...m, [key]: e.target.checked }))} aria-label={title} className="size-5" />
                      {!usable && <Lock size={14} aria-hidden />}{title}
                    </label>
                    {badge && <span className="w-fit rounded-full bg-surface-secondary px-2 py-0.5 text-xs font-semibold text-text-secondary">{badge}</span>}
                    <div className="flex aspect-[9/16] items-center justify-center overflow-hidden rounded-xl bg-surface-secondary">
                      {art === 'design' && usable ? <div className="w-full">{previewWith(key)}</div> : (
                        <div className="relative h-4/5 w-3/5 overflow-hidden rounded-xl border-2 border-border bg-surface">
                          {art === 'sizes' && <span className="absolute inset-x-3 inset-y-8 rounded-lg border-2 border-brand-600/60 bg-brand-600/10" />}
                          {art === 'comments' && <span className="absolute inset-x-3 bottom-4 h-8 rounded-full bg-border" />}
                          {art === 'catalog' && <span className="absolute inset-x-3 bottom-4 flex gap-1"><span className="h-10 flex-1 rounded bg-border" /><span className="h-10 flex-1 rounded bg-border" /><span className="h-10 flex-1 rounded bg-border" /></span>}
                          {art === 'design' && <span className="absolute inset-x-3 top-1/2 h-2 rounded bg-border" />}
                        </div>
                      )}
                    </div>
                    <p className="text-center text-xs text-text-secondary">{body}</p>
                    {key === 'textImprove' && usable && (
                      <div className="flex items-center justify-between rounded-lg border border-border px-2 py-1.5 text-xs text-text-primary">
                        <button type="button" onClick={() => setExample((e) => (e + variantCount - 1) % variantCount)} aria-label="previous"><ChevronRight size={16} aria-hidden /></button>
                        <Num>{t.example.replace('{i}', formatNumber(example + 1)).replace('{n}', formatNumber(variantCount))}</Num>
                        <button type="button" onClick={() => setExample((e) => (e + 1) % variantCount)} aria-label="next"><ChevronLeft size={16} aria-hidden /></button>
                      </div>
                    )}
                  </div>
                )
              })}
            </div>
            {failedSave && <p className="rounded-lg bg-danger-soft px-3 py-2 text-sm font-semibold text-danger" data-testid="composer-save-failed">{t.saveFailed}</p>}
          </section>
        )}
      </div>
    </Modal>
  )
}
