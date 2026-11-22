import { mediaFitClass } from '../adPreview'
import { useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Cloud, Film, Folder, ListChecks, Search, Upload } from 'lucide-react'
import { Num } from '@/components/ui/Num'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { fmtDate } from '@/lib/datetime'
import { formatNumber } from '@/lib/numerals'
import { createIntake, intakeFileUrl, listIntake, type IntakeItem } from '../intake/api'
import { IntakePanel } from '../intake/IntakePanel'
import { FollowUpPanel } from '../intake/FollowUpPanel'
import { DriveSyncPanel } from '../intake/DriveSyncPanel'
import { LIB } from './copy'

const COVER = mediaFitClass(null, null, 'cover')

export type MediaFolder = 'files' | 'review' | 'followup' | 'drive'

function duration(ms: number | null): string | null {
  if (!ms) return null
  const s = Math.round(ms / 1000)

  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
}

function MediaTile({ item }: { item: IntakeItem }) {
  const [broken, setBroken] = useState(false)
  const src = item.has_file ? intakeFileUrl(item.id) : null
  const d = duration(item.duration_ms)

  return (
    <figure className="relative overflow-hidden rounded-xl border border-border bg-surface-secondary" data-testid={`media-tile-${item.id}`}>
      <div className="aspect-[9/16] w-full">
        {src && !broken && item.kind === 'image' && <img src={src} alt="" loading="lazy" onError={() => setBroken(true)} className={`size-full ${COVER}`} />}
        {src && !broken && item.kind === 'video' && <video src={`${src}#t=0.1`} preload="metadata" muted playsInline onError={() => setBroken(true)} className={`size-full ${COVER}`} />}
        {(!src || broken || (item.kind !== 'image' && item.kind !== 'video')) && <div className="flex size-full items-center justify-center text-text-muted"><Film size={20} aria-hidden /></div>}
      </div>
      {d && <span className="absolute end-2 top-2 rounded-md bg-black/60 px-1.5 py-0.5 text-xs font-semibold text-white"><Num>{d}</Num></span>}
      <figcaption className="absolute inset-x-0 bottom-0 truncate bg-gradient-to-t from-black/70 to-transparent px-2 pb-2 pt-6 text-xs font-semibold text-white">{item.name}</figcaption>
    </figure>
  )
}

/**
 * CONTENT-LIBRARY-SNAP-001 — «مكتبة الوسائط»: folders on one side, files on the other, the way the platforms keep raw media. The
 * folders are the content intake the product already has (files, review, follow-up, Drive), so nothing here is a
 * second store. Files are grouped by the day they changed.
 */
export function MediaLibrary({ folder, onFolder }: { folder: MediaFolder; onFolder: (f: MediaFolder) => void }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = LIB[ar ? 'ar' : 'en']
  const projectId = useProject((s) => s.currentProjectId)
  const queryClient = useQueryClient()
  const fileInput = useRef<HTMLInputElement>(null)
  const [search, setSearch] = useState('')

  const list = useQuery({ queryKey: ['content-intake', 'media', projectId], queryFn: () => listIntake({ project: projectId }), enabled: folder === 'files' })
  const upload = useMutation({
    mutationFn: async (files: File[]) => {
      for (const file of files) await createIntake({ project_id: projectId ?? undefined, name: file.name.replace(/\.[^.]+$/, ''), file })
    },
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['content-intake'] }),
  })

  const items = useMemo(() => {
    const all = (list.data?.items ?? []).filter((i) => i.kind === 'image' || i.kind === 'video')
    const q = search.trim().toLowerCase()

    return q ? all.filter((i) => i.name.toLowerCase().includes(q)) : all
  }, [list.data, search])

  const byDay = useMemo(() => {
    const groups = new Map<string, IntakeItem[]>()
    for (const i of [...items].sort((a, b) => b.updated_at.localeCompare(a.updated_at))) {
      const day = i.updated_at.slice(0, 10)
      groups.set(day, [...(groups.get(day) ?? []), i])
    }

    return [...groups.entries()]
  }, [items])

  const folders: Array<{ key: MediaFolder; label: string; icon: typeof Folder }> = [
    { key: 'files', label: t.folder.files, icon: Folder },
    { key: 'review', label: t.folder.review, icon: CheckCircle2 },
    { key: 'followup', label: t.folder.followup, icon: ListChecks },
    { key: 'drive', label: t.folder.drive, icon: Cloud },
  ]

  return (
    <div className="grid gap-4 lg:grid-cols-[15rem_minmax(0,1fr)]" data-testid="media-library">
      <nav className="rounded-2xl border border-border bg-surface p-3" aria-label={t.folders}>
        <p className="px-2 pb-2 text-sm font-bold text-text-primary">{t.folders}</p>
        {folders.map(({ key, label, icon: Icon }) => (
          <button
            key={key}
            type="button"
            onClick={() => onFolder(key)}
            data-testid={`media-folder-${key}`}
            aria-current={folder === key ? 'page' : undefined}
            className={`flex w-full items-center gap-2 rounded-lg px-2 py-2 text-sm font-semibold ${folder === key ? 'bg-surface-secondary text-text-primary' : 'text-text-secondary hover:bg-surface-secondary'}`}
          >
            <Icon size={16} aria-hidden />{label}
          </button>
        ))}
      </nav>

      <div className="min-w-0">
        {folder === 'review' && <IntakePanel />}
        {folder === 'followup' && <FollowUpPanel />}
        {folder === 'drive' && <DriveSyncPanel />}

        {folder === 'files' && (
          <section className="rounded-2xl border border-border bg-surface">
            <div className="flex items-center gap-3 border-b border-border px-4 py-3">
              <Search size={18} className="shrink-0 text-text-muted" aria-hidden />
              <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t.searchMedia} aria-label={t.searchMedia} className="min-w-0 flex-1 bg-transparent text-base text-text-primary outline-none placeholder:text-text-muted" />
              <input ref={fileInput} type="file" accept="image/*,video/*" multiple hidden data-testid="media-upload-input" onChange={(e) => { const f = [...(e.target.files ?? [])]; if (f.length) upload.mutate(f); e.target.value = '' }} />
              <button type="button" disabled={!projectId || upload.isPending} onClick={() => fileInput.current?.click()} data-testid="media-upload" className="flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
                <Upload size={16} aria-hidden />{t.uploadMedia}
              </button>
            </div>
            <div className="p-4">
              {list.isLoading && <p className="py-10 text-center text-sm text-text-secondary">{t.loading}</p>}
              {!list.isLoading && items.length === 0 && <p className="py-10 text-center text-sm text-text-secondary" data-testid="media-empty">{t.noMedia}</p>}
              {byDay.map(([day, group]) => (
                <div key={day} className="mb-6 last:mb-0">
                  <p className="mb-3 text-sm font-bold text-text-primary"><Num>{fmtDate(day)}</Num> <span className="font-normal text-text-secondary">· {formatNumber(group.length)}</span></p>
                  <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                    {group.map((i) => <MediaTile key={i.id} item={i} />)}
                  </div>
                </div>
              ))}
            </div>
          </section>
        )}
      </div>
    </div>
  )
}
