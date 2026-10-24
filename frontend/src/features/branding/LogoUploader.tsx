import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ImageOff, Loader2, Trash2, Upload } from 'lucide-react'
import { api, getData, toApiError } from '@/lib/api/client'

/** The formats and ceiling the server enforces (`BrandingSpec`); checked here first so a wrong file never travels. */
export const LOGO_MIMES = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'] as const
export const LOGO_MAX_BYTES = 2 * 1024 * 1024

export interface LogoState {
  own: boolean
  effective_source: 'own' | 'client' | 'agency' | null
  url: string | null
  mime: string | null
  bytes: number | null
}

export function checkLogoFile(f: File): 'type' | 'size' | null {
  if (!(LOGO_MIMES as readonly string[]).includes(f.type)) return 'type'
  return f.size > LOGO_MAX_BYTES ? 'size' : null
}

/** The logo state for a layer, shared with the uploader's cache so a preview elsewhere updates the moment a logo is changed. */
export function useLogoState(endpoint: string) {
  return useQuery({ queryKey: ['logo', endpoint], queryFn: () => getData<LogoState>(endpoint) })
}

const COPY = {
  ar: {
    choose: 'رفع شعار', replace: 'استبدال الشعار', remove: 'حذف الشعار', uploading: 'جارٍ الرفع…', none: 'لا يوجد شعار بعد.',
    formats: 'PNG أو JPG أو SVG أو WEBP — حتى 2 ميغابايت. لا حاجة لأي رابط.', badType: 'نوع الملف غير مقبول. المسموح: PNG أو JPG أو SVG أو WEBP.',
    tooBig: 'حجم الملف أكبر من 2 ميغابايت. اختر صورة أصغر.', retry: 'إعادة المحاولة', saved: 'تم حفظ الشعار.', removed: 'تم حذف الشعار.',
    from: { client: 'الشعار المعروض هو شعار العميل (لا يوجد شعار خاص بهذا العنصر).', agency: 'الشعار المعروض هو شعار الوكالة (لا يوجد شعار أخصّ).' },
    preview: 'معاينة الشعار',
  },
  en: {
    choose: 'Upload a logo', replace: 'Replace logo', remove: 'Remove logo', uploading: 'Uploading…', none: 'No logo yet.',
    formats: 'PNG, JPG, SVG or WEBP — up to 2 MB. No link needed.', badType: 'This file type is not accepted. Use PNG, JPG, SVG or WEBP.',
    tooBig: 'The file is larger than 2 MB. Choose a smaller image.', retry: 'Try again', saved: 'Logo saved.', removed: 'Logo removed.',
    from: { client: 'The logo shown is the client’s (this item has none of its own).', agency: 'The logo shown is the agency’s (nothing more specific is set).' },
    preview: 'Logo preview',
  },
}

/**
 * LOGO-UPLOAD-001 — a logo is a file, never an address.
 *
 * One component for every place a logo is set (the agency, a client, a project): choose a file → instant preview → upload; replace;
 * remove. It never offers a URL box. What is drawn is what the server will use: while the layer has no logo of its own the preview
 * is the inherited one, and says whose it is. The picture sits on a neutral ground at its own ratio (`object-contain`) so nothing is
 * cropped or stretched, in light and dark, and the box does not move between states.
 */
export function LogoUploader({ endpoint, ar, canEdit, testId = 'logo-uploader', invalidate = [] }: {
  /** The resource, e.g. `/app/clients/{id}/logo` (GET state · POST file · DELETE). */
  endpoint: string
  ar: boolean
  canEdit: boolean
  testId?: string
  /** Query keys to refresh after a change (the screens that draw this logo). */
  invalidate?: unknown[][]
}) {
  const t = ar ? COPY.ar : COPY.en
  const qc = useQueryClient()
  const input = useRef<HTMLInputElement>(null)
  const key = ['logo', endpoint]
  const state = useLogoState(endpoint)
  const [local, setLocal] = useState<{ file: File; url: string } | null>(null)
  const [problem, setProblem] = useState<string | null>(null)
  const [said, setSaid] = useState<'saved' | 'removed' | null>(null)

  useEffect(() => () => { if (local) URL.revokeObjectURL(local.url) }, [local])

  const refresh = (data: LogoState) => {
    qc.setQueryData(key, data)
    invalidate.forEach((k) => void qc.invalidateQueries({ queryKey: k }))
  }
  const upload = useMutation({
    mutationFn: async (file: File) => {
      const form = new FormData()
      form.set('file', file)
      return (await api.post(endpoint, form)).data.data as LogoState
    },
    onSuccess: (data) => { setLocal(null); setSaid('saved'); refresh(data) },
  })
  const remove = useMutation({
    mutationFn: async () => (await api.delete(endpoint)).data.data as LogoState,
    onSuccess: (data) => { setSaid('removed'); refresh(data) },
  })

  const choose = (files: FileList | null) => {
    const f = files?.[0]
    if (!f) return
    setSaid(null)
    const refusal = checkLogoFile(f)
    setProblem(refusal === 'type' ? t.badType : refusal === 'size' ? t.tooBig : null)
    if (refusal) { setLocal(null); return }
    setLocal({ file: f, url: URL.createObjectURL(f) })
    upload.mutate(f)
  }

  const s = state.data
  const shown = local?.url ?? s?.url ?? null
  const hasOwn = s?.own === true
  const failure = upload.error ?? remove.error ?? state.error
  const busy = upload.isPending || remove.isPending

  return (
    <div data-testid={testId} className="grid gap-2">
      <div className="flex flex-wrap items-center gap-3">
        <div
          data-testid={`${testId}-preview`}
          aria-label={t.preview}
          className="flex h-20 w-48 shrink-0 items-center justify-center rounded-xl border border-border bg-surface-secondary p-2"
        >
          {shown
            ? <img src={shown} alt={t.preview} className="max-h-full max-w-full object-contain" />
            : <span className="flex items-center gap-1.5 text-xs text-text-muted"><ImageOff size={14} /> {t.none}</span>}
        </div>
        {canEdit && (
          <div className="flex flex-wrap items-center gap-2">
            <button type="button" data-testid={`${testId}-choose`} disabled={busy} onClick={() => input.current?.click()} className="flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-2 text-xs font-semibold text-text-primary hover:bg-surface-secondary disabled:opacity-60">
              {upload.isPending ? <Loader2 size={13} className="animate-spin" /> : <Upload size={13} />} {upload.isPending ? t.uploading : hasOwn ? t.replace : t.choose}
            </button>
            {hasOwn && (
              <button type="button" data-testid={`${testId}-remove`} disabled={busy} onClick={() => remove.mutate()} className="flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold text-danger hover:bg-surface-secondary disabled:opacity-60">
                <Trash2 size={13} /> {t.remove}
              </button>
            )}
            <input ref={input} data-testid={`${testId}-input`} type="file" accept={LOGO_MIMES.join(',')} hidden onChange={(e) => { choose(e.target.files); e.target.value = '' }} />
          </div>
        )}
      </div>
      {canEdit && <p className="text-[11px] text-text-muted">{t.formats}</p>}
      {!hasOwn && s?.effective_source && s.effective_source !== 'own' && <p data-testid={`${testId}-inherited`} className="text-[11px] text-text-secondary">{t.from[s.effective_source]}</p>}
      {problem && <p role="alert" data-testid={`${testId}-problem`} className="text-xs text-danger">{problem}</p>}
      {said && !failure && <p role="status" className="text-xs text-success">{said === 'saved' ? t.saved : t.removed}</p>}
      {failure && (
        <p role="alert" className="flex flex-wrap items-center gap-2 text-xs text-danger">
          {toApiError(failure).message}
          {local && <button type="button" data-testid={`${testId}-retry`} onClick={() => upload.mutate(local.file)} className="rounded-lg border border-border px-2 py-1 font-semibold text-text-primary">{t.retry}</button>}
        </p>
      )}
    </div>
  )
}
