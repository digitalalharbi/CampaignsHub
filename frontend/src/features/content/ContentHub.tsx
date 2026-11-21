import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { BarChart3, ChevronDown, Images, LayoutList, Plus, Sparkles, Upload } from 'lucide-react'
import { PageIntro } from '@/components/ui/PageIntro'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'
import { CreativesPage } from './CreativesPage'
import { ContentComposer } from './library/ContentComposer'
import { CreativeInsights } from './library/CreativeInsights'
import { CreativesTable } from './library/CreativesTable'
import { LibraryTabs } from './library/LibraryTabs'
import { MediaLibrary, type MediaFolder } from './library/MediaLibrary'
import { LIB } from './library/copy'

/*
 * CONTENT-LIBRARY-SNAP-001 — the content area, laid out the way the platforms' own creative libraries are (Owner, 2026-11-22):
 * one title, one «محتوى جديد» button, and three tabs — «المحتويات» (the table), «رؤى المحتوى» (top performers) and «مكتبة الوسائط»
 * (raw files in folders). The earlier intake views live on as media folders, and their old addresses still open them.
 *
 * A drill-down from another page (a dashboard tile, a report) arrives with its filters in the address; that keeps opening the full
 * analysis view it was built for, so no link elsewhere in the product breaks.
 */
const TABS = ['creatives', 'insights', 'media'] as const
type Tab = (typeof TABS)[number]
const LEGACY_FOLDERS: Record<string, MediaFolder> = { review: 'review', followup: 'followup', drive: 'drive' }
const FOLDERS: MediaFolder[] = ['files', 'review', 'followup', 'drive']
const DRILL_KEYS = ['view', 'health', 'search', 'creative']

function isDrillDown(params: URLSearchParams): boolean {
  return [...params.keys()].some((k) => k.endsWith('[]') || DRILL_KEYS.includes(k))
}

export function ContentHub() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = LIB[ar ? 'ar' : 'en']
  const [params, setParams] = useSearchParams()
  const canIntake = useAuth((s) => s.hasPermission('content.view'))
  const [composer, setComposer] = useState(false)
  const [menu, setMenu] = useState(false)
  /*
   * Decided once, when the address first carries a drill-down (or `?view=grid|list`), and never undone by the analysis page
   * rewriting its own address: clearing its last filter must not swap the page out from under the reader.
   */
  const [analysis, setAnalysis] = useState(() => isDrillDown(params))
  const drill = isDrillDown(params)
  useEffect(() => { if (drill) setAnalysis(true) }, [drill])

  if (analysis || drill) return <CreativesPage />

  const raw = params.get('tab') ?? ''
  const legacy = LEGACY_FOLDERS[raw]
  const tab: Tab = legacy ? 'media' : (TABS as readonly string[]).includes(raw) ? (raw as Tab) : 'creatives'
  const usable: Tab = tab === 'media' && !canIntake ? 'creatives' : tab
  const folderRaw = (legacy ?? params.get('folder') ?? 'files') as MediaFolder
  const folder: MediaFolder = FOLDERS.includes(folderRaw) ? folderRaw : 'files'

  const go = (next: Tab, nextFolder?: MediaFolder) => setParams(next === 'creatives' ? {} : next === 'media' ? { tab: 'media', folder: nextFolder ?? 'files' } : { tab: next })
  const items = [
    { key: 'creatives' as const, label: t.tabs.creatives, icon: LayoutList },
    { key: 'insights' as const, label: t.tabs.insights, icon: BarChart3 },
    ...(canIntake ? [{ key: 'media' as const, label: t.tabs.media, icon: Images }] : []),
  ]

  const actions = canIntake ? (
    <div className="relative">
      <button type="button" onClick={() => setMenu((m) => !m)} data-testid="content-new" className="flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
        <Plus size={16} aria-hidden />{t.newContent}<ChevronDown size={14} aria-hidden />
      </button>
      {menu && (
        <div className="absolute end-0 top-full z-30 mt-2 w-56 overflow-hidden rounded-xl border border-border bg-surface shadow-lg" data-testid="content-new-menu">
          <button type="button" onClick={() => { setMenu(false); setComposer(true) }} data-testid="content-new-studio" className="flex w-full items-center gap-2 px-4 py-3 text-sm font-semibold text-text-primary hover:bg-surface-secondary">
            <Sparkles size={16} aria-hidden />{t.newFromStudio}
          </button>
          <button type="button" onClick={() => { setMenu(false); go('media', 'files') }} data-testid="content-new-upload" className="flex w-full items-center gap-2 px-4 py-3 text-sm font-semibold text-text-primary hover:bg-surface-secondary">
            <Upload size={16} aria-hidden />{t.newUpload}
          </button>
        </div>
      )}
    </div>
  ) : undefined

  return (
    <div className="flex flex-col gap-5" data-testid="content-hub">
      <PageIntro testid="content-hub-intro" title={t.title} actions={actions} />
      <LibraryTabs items={items} active={usable} onChange={(k) => go(k)} />
      {usable === 'creatives' && <CreativesTable />}
      {usable === 'insights' && <CreativeInsights />}
      {usable === 'media' && <MediaLibrary folder={folder} onFolder={(f) => go('media', f)} />}
      {composer && <ContentComposer onClose={() => setComposer(false)} />}
    </div>
  )
}
