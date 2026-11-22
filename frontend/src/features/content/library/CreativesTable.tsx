import { useMemo, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { ChevronDown, Download, Eye, Filter, Layers, Search, SplitSquareHorizontal } from 'lucide-react'
import { ProviderMark } from '@/components/ProviderMark'
import { Num } from '@/components/ui/Num'
import { useProject } from '@/stores/project'
import { useUi } from '@/stores/ui'
import { fmtDate } from '@/lib/datetime'
import { formatNumber } from '@/lib/numerals'
import { listCreatives, type LibraryQuery } from '../api'
import { CreativeCompare } from '../CreativeCompare'
import { COMPARE_MAX } from '../standing'
import { Cover } from './Cover'
import { LIB } from './copy'
import { deliveryOf, formatLabel, isoDaysAgo } from './util'

const PER_PAGE = 25

const STATE_DOT = { delivering: 'bg-success', paused: 'bg-warning', ended: 'bg-text-muted' } as const

/**
 * CONTENT-LIBRARY-SNAP-001 — «المحتويات»: one table the way the platforms list their creatives. A search box and one «تصفية»
 * button; a row of actions that wake when rows are ticked; then name, format, platform, status, usage and the date first seen.
 * The same creatives endpoint the analysis reads (one number, many surfaces).
 */
export function CreativesTable() {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = LIB[ar ? 'ar' : 'en']
  const navigate = useNavigate()
  const projectId = useProject((s) => s.currentProjectId)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [filterOpen, setFilterOpen] = useState(false)
  const [providers, setProviders] = useState<string[]>([])
  const [kinds, setKinds] = useState<string[]>([])
  const [selected, setSelected] = useState<string[]>([])
  const [comparing, setComparing] = useState(false)
  const from = isoDaysAgo(89)
  const to = isoDaysAgo(0)

  const query: LibraryQuery = useMemo(() => ({
    from, to, page, per_page: PER_PAGE, sort: 'recent',
    search: search.trim() || undefined,
    project_ids: projectId ? [projectId] : undefined,
    providers: providers.length ? providers : undefined,
    kinds: kinds.length ? kinds : undefined,
  }), [from, to, page, search, projectId, providers, kinds])

  const library = useQuery({ queryKey: ['creative-library', 'table', projectId, query], queryFn: () => listCreatives(query, null), placeholderData: keepPreviousData })
  const rows = library.data?.creatives ?? []
  const total = library.data?.total ?? 0
  const pages = Math.max(1, Math.ceil(total / PER_PAGE))
  const options = library.data?.filters
  const allOnPage = rows.length > 0 && rows.every((r) => selected.includes(r.id))
  const toggle = (id: string) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]))

  const exportCsv = () => {
    const chosen = rows.filter((r) => selected.includes(r.id))
    const lines = [[t.cols.name, t.cols.format, t.cols.platform, t.cols.status, t.cols.usage, t.cols.created].join(',')]
    for (const r of chosen) {
      lines.push([r.name, formatLabel(r.kind, t), r.provider, t[deliveryOf(r)], r.campaign_name ?? '', r.freshness.first_seen_at?.slice(0, 10) ?? ''].map((v) => `"${String(v).replace(/"/g, '""')}"`).join(','))
    }
    const url = URL.createObjectURL(new Blob(['﻿' + lines.join('\n')], { type: 'text/csv;charset=utf-8' }))
    const a = document.createElement('a')
    a.href = url
    a.download = `creatives-${to}.csv`
    a.click()
    URL.revokeObjectURL(url)
  }

  const action = (label: string, Icon: typeof Eye, onClick: () => void, enabled: boolean, testid: string) => (
    <button type="button" disabled={!enabled} onClick={onClick} data-testid={testid} className="flex items-center gap-1.5 text-sm font-semibold text-text-primary disabled:text-text-muted">
      <Icon size={16} aria-hidden />{label}
    </button>
  )

  const chip = (value: string, list: string[], set: (v: string[]) => void, label: string) => (
    <label key={value} className="flex items-center gap-2 text-sm text-text-primary">
      <input type="checkbox" checked={list.includes(value)} onChange={() => { setPage(1); set(list.includes(value) ? list.filter((x) => x !== value) : [...list, value]) }} />
      {label}
    </label>
  )

  return (
    <section className="rounded-2xl border border-border bg-surface" data-testid="creatives-table">
      <div className="flex items-center gap-3 border-b border-border px-4 py-3" data-testid="content-filters-controls">
        <Search size={18} className="shrink-0 text-text-muted" aria-hidden />
        <input
          value={search}
          onChange={(e) => { setPage(1); setSearch(e.target.value) }}
          placeholder={t.search}
          aria-label={t.search}
          data-testid="creatives-search"
          className="min-w-0 flex-1 bg-transparent text-base text-text-primary outline-none placeholder:text-text-muted"
        />
        <div className="relative">
          <button type="button" onClick={() => setFilterOpen((o) => !o)} data-testid="creatives-filter" className="flex items-center gap-2 rounded-xl border border-border px-4 py-2 text-sm font-semibold text-text-primary hover:bg-surface-secondary">
            <Filter size={16} aria-hidden /><span>{t.filter}{providers.length + kinds.length > 0 ? ` (${formatNumber(providers.length + kinds.length)})` : ''}</span><ChevronDown size={14} aria-hidden />
          </button>
          {filterOpen && (
            <div className="absolute end-0 top-full z-20 mt-2 w-64 rounded-xl border border-border bg-surface p-4 shadow-lg" data-testid="creatives-filter-panel">
              <p className="mb-2 text-xs font-bold text-text-secondary">{t.platform}</p>
              <div className="mb-3 flex flex-col gap-1.5">{(options?.providers ?? []).map((p) => chip(p, providers, setProviders, p))}</div>
              <p className="mb-2 text-xs font-bold text-text-secondary">{t.type}</p>
              <div className="flex flex-col gap-1.5">{(options?.kinds ?? []).map((k) => chip(k.key, kinds, setKinds, formatLabel(k.key, t)))}</div>
              <button type="button" onClick={() => { setProviders([]); setKinds([]); setPage(1) }} className="mt-3 text-xs font-semibold text-brand-600">{t.clear}</button>
            </div>
          )}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-x-6 gap-y-2 border-b border-border px-4 py-3" data-testid="creatives-actions">
        {action(t.actions.view, Eye, () => navigate(selected[0]!), selected.length > 0, 'creatives-action-view')}
        {action(t.actions.compare, SplitSquareHorizontal, () => setComparing(true), selected.length >= 2, 'creatives-action-compare')}
        {action(t.actions.group, Layers, () => navigate('groups'), true, 'creatives-action-groups')}
        {action(t.actions.export, Download, exportCsv, selected.length > 0, 'creatives-action-export')}
      </div>

      <div className="overflow-x-auto">
        <table className="w-full min-w-[56rem] text-start text-sm">
          <thead>
            <tr className="border-b border-border text-xs font-bold text-text-secondary">
              <th className="w-12 p-3"><input type="checkbox" aria-label="all" checked={allOnPage} onChange={() => setSelected(allOnPage ? selected.filter((id) => !rows.some((r) => r.id === id)) : [...new Set([...selected, ...rows.map((r) => r.id)])])} /></th>
              <th className="w-16 p-3" aria-hidden />
              <th className="p-3 text-start">{t.cols.name}</th>
              <th className="p-3 text-start">{t.cols.format}</th>
              <th className="p-3 text-start">{t.cols.platform}</th>
              <th className="p-3 text-start">{t.cols.status}</th>
              <th className="p-3 text-start">{t.cols.usage}</th>
              <th className="p-3 text-start">{t.cols.created}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => {
              const state = deliveryOf(r)

              return (
                <tr key={r.id} data-testid={`creatives-row-${r.id}`} className="cursor-pointer border-b border-border last:border-0 hover:bg-surface-secondary" onClick={() => navigate(r.id)}>
                  <td className="p-3" onClick={(e) => e.stopPropagation()}><input type="checkbox" aria-label={r.name} checked={selected.includes(r.id)} onChange={() => toggle(r.id)} /></td>
                  <td className="p-3"><Cover preview={r.preview} ar={ar} className="h-16 w-10 rounded-md" /></td>
                  <td className="max-w-sm p-3 font-semibold text-text-primary"><span className="line-clamp-2">{r.name}</span></td>
                  <td className="p-3 text-text-primary">{formatLabel(r.kind, t)}</td>
                  <td className="p-3"><span className="flex items-center gap-2 text-text-primary"><ProviderMark provider={r.provider} size={18} /></span></td>
                  <td className="p-3"><span className="flex items-center gap-2 font-semibold text-text-primary"><span className={`size-2.5 rounded-full ${STATE_DOT[state]}`} aria-hidden />{t[state]}</span></td>
                  <td className="p-3 text-text-primary">
                    {r.campaign_name ? <><span className="block">{t.inCampaign}</span><span className="block max-w-56 truncate text-xs text-text-secondary">{r.campaign_name}</span></> : <span className="text-text-secondary">{t.noCampaign}</span>}
                  </td>
                  <td className="p-3 text-text-primary"><Num>{fmtDate(r.freshness.first_seen_at)}</Num></td>
                </tr>
              )
            })}
          </tbody>
        </table>
        {!library.isLoading && rows.length === 0 && <p className="p-8 text-center text-sm text-text-secondary" data-testid="creatives-empty">{t.empty}</p>}
        {library.isLoading && <p className="p-8 text-center text-sm text-text-secondary">{t.loading}</p>}
      </div>

      {pages > 1 && (
        <div className="flex items-center justify-between border-t border-border px-4 py-3 text-sm text-text-secondary">
          <span>{t.page} {formatNumber(page)} {t.of} {formatNumber(pages)}</span>
          <div className="flex gap-2">
            <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} className="rounded-lg border border-border px-3 py-1.5 font-semibold text-text-primary disabled:text-text-muted">{t.prev}</button>
            <button type="button" disabled={page >= pages} onClick={() => setPage((p) => p + 1)} className="rounded-lg border border-border px-3 py-1.5 font-semibold text-text-primary disabled:text-text-muted">{t.next}</button>
          </div>
        </div>
      )}

      {comparing && (
        <CreativeCompare
          creativeIds={selected.slice(0, COMPARE_MAX)}
          creatives={rows.filter((c) => selected.slice(0, COMPARE_MAX).includes(c.id))}
          window={{ from, to }}
          onClose={() => setComparing(false)}
        />
      )}
    </section>
  )
}
