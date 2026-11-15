import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Sunrise } from 'lucide-react'
import { api } from '@/lib/api/client'
import type { ApiEnvelope } from '@/lib/api/types'
import { getBriefSettings, isUpgradeRequired, saveBriefSettings, type BriefSettings } from './followupApi'
import { listMutes, removeMutes } from './api'
import { UpgradeLock } from './UpgradeLock'
import { useCopy } from './centerCopy'

/**
 * NOTIF-V2-FOLLOWUP-001 — the personal additions to the preferences page (spec §2, §6): the morning brief (on/off, time, channels),
 * the projects this person follows, and the types they muted for a project. «Followed projects» is the person's existing
 * `project_ids` preference (the one the digests and alerts already narrow by): one setting, not a second list.
 */
interface Prefs { project_ids: string[] | null }
interface ProjectLite { id: string; name: string }

export function FollowUpPrefsCard() {
  const c = useCopy()
  const qc = useQueryClient()
  const settings = useQuery({ queryKey: ['followup', 'settings'], queryFn: getBriefSettings, retry: false })
  const prefs = useQuery({ queryKey: ['followup', 'prefs-projects'], queryFn: async () => (await api.get<ApiEnvelope<Prefs>>('/settings/notifications')).data.data ?? { project_ids: null } })
  const projects = useQuery({ queryKey: ['followup', 'projects'], queryFn: async () => (await api.get<ApiEnvelope<ProjectLite[]>>('/projects')).data.data ?? [] })
  const mutes = useQuery({ queryKey: ['notifications', 'mutes'], queryFn: listMutes })

  const [form, setForm] = useState<BriefSettings | null>(null)
  const [followed, setFollowed] = useState<string[]>([])
  const [saved, setSaved] = useState(false)
  useEffect(() => { if (settings.data) setForm(settings.data) }, [settings.data])
  useEffect(() => { setFollowed(prefs.data?.project_ids ?? []) }, [prefs.data])

  const save = useMutation({
    meta: { feedback: 'none' },
    mutationFn: async () => {
      if (form) await saveBriefSettings({ brief_enabled: form.brief_enabled, brief_time: form.brief_time, brief_channels: form.brief_channels })
      await api.put('/settings/notifications', { project_ids: followed.length === 0 ? null : followed })
    },
    onSuccess: () => { setSaved(true); void qc.invalidateQueries({ queryKey: ['followup'] }) },
  })
  const unmute = useMutation({ meta: { feedback: 'none' }, mutationFn: (ids: string[]) => removeMutes(ids), onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }) })

  const locked = isUpgradeRequired(settings.error)
  const toggleChannel = (ch: 'in_app' | 'email') => form && setForm({ ...form, brief_channels: form.brief_channels.includes(ch) ? form.brief_channels.filter((x) => x !== ch) : [...form.brief_channels, ch] })

  return (
    <section data-testid="followup-prefs" className="min-w-0 rounded-xl border border-border bg-surface p-4">
      <h2 className="flex items-center gap-2 text-base font-bold text-text-primary"><Sunrise size={17} /> {c('prefs_title')}</h2>
      <p className="mb-3 text-xs text-text-secondary">{c('prefs_sub')}</p>

      {locked ? <UpgradeLock name={c('brief_title')} /> : form && (
        <div className="flex flex-col gap-3">
          <label className="flex items-center gap-2 text-sm text-text-primary">
            <input type="checkbox" data-testid="brief-enabled" checked={form.brief_enabled} onChange={(e) => setForm({ ...form, brief_enabled: e.target.checked })} className="h-5 w-5" />
            {c('brief_on')}
          </label>
          <label className="flex flex-col gap-1 text-sm text-text-primary">
            <span>{c('brief_time')} <span dir="ltr" className="tnum text-xs text-text-muted">({form.timezone})</span></span>
            <input type="time" dir="ltr" data-testid="brief-time" value={form.brief_time} onChange={(e) => setForm({ ...form, brief_time: e.target.value })} className="h-11 w-full max-w-[10rem] rounded-lg border border-border bg-surface px-2" />
          </label>
          <fieldset className="flex flex-wrap items-center gap-3 text-sm text-text-primary">
            <legend className="mb-1 text-sm font-semibold">{c('brief_channels')}</legend>
            {(['in_app', 'email'] as const).map((ch) => (
              <label key={ch} className="flex items-center gap-2">
                <input type="checkbox" data-testid={`brief-channel-${ch}`} checked={form.brief_channels.includes(ch)} onChange={() => toggleChannel(ch)} className="h-5 w-5" />
                {ch === 'in_app' ? c('channel_in_app') : c('channel_email')}
              </label>
            ))}
          </fieldset>
        </div>
      )}

      <fieldset className="mt-4 flex flex-col gap-2 text-sm text-text-primary" data-testid="followed-projects">
        <legend className="mb-1 text-sm font-semibold">{c('followed')}</legend>
        <p className="text-xs text-text-muted">{followed.length === 0 ? c('followed_all') : c('followed_some')}</p>
        <div className="flex max-h-48 flex-col gap-1 overflow-y-auto">
          {(projects.data ?? []).map((p) => (
            <label key={p.id} className="flex min-h-9 items-center gap-2 break-words">
              <input type="checkbox" checked={followed.includes(p.id)} onChange={() => setFollowed(followed.includes(p.id) ? followed.filter((x) => x !== p.id) : [...followed, p.id])} className="h-5 w-5 shrink-0" />
              <span className="min-w-0">{p.name}</span>
            </label>
          ))}
        </div>
      </fieldset>

      {(mutes.data ?? []).length > 0 && (
        <div className="mt-4" data-testid="muted-types">
          <p className="mb-1 text-sm font-semibold text-text-primary">{c('muted_title')}</p>
          <ul className="flex flex-col gap-1">
            {(mutes.data ?? []).map((m) => (
              <li key={m.ids.join('-')} className="flex flex-wrap items-center justify-between gap-2 text-sm text-text-secondary">
                <span className="min-w-0 break-words">{m.project_name} · {m.label}</span>
                <button type="button" onClick={() => unmute.mutate(m.ids)} className="h-9 rounded-lg border border-border px-3 text-xs font-semibold hover:bg-surface-hover">{c('unmute')}</button>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="mt-4 flex items-center gap-3">
        <button type="button" data-testid="followup-prefs-save" onClick={() => { setSaved(false); save.mutate() }} className="h-10 rounded-lg bg-brand-500 px-4 text-sm font-semibold text-white hover:bg-brand-600">{c('save')}</button>
        {saved && <span role="status" className="text-xs font-semibold text-success">{c('saved')}</span>}
      </div>
    </section>
  )
}
