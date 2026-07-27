import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Archive, RotateCcw } from 'lucide-react'
import { archiveClient, restoreClient, updateSettings, type ClientDetail } from './api'
import { useT } from '@/lib/i18n'

/**
 * Client-level settings — deliberately separate from user + org settings. Display name, logo, client report
 * identity, report/alert prefs, and the archive lifecycle (pause, not delete; restore is a separate act).
 */
export function TabSettings({ d }: { d: ClientDetail }) {
  const t = useT()
  const qc = useQueryClient()
  const s = (d as unknown as { settings?: Record<string, unknown> }).settings ?? {}
  const reportIdentity = (s.report_identity as Record<string, string>) ?? {}
  const reportPrefs = (s.report_prefs as Record<string, string>) ?? {}

  const [form, setForm] = useState({
    name: d.name,
    logo_url: '',
    week_start: (s.week_start as string) ?? 'sunday',
    report_display_name: reportIdentity.display_name ?? '',
    report_footer: reportIdentity.footer ?? '',
    default_format: reportPrefs.default_format ?? 'pdf',
  })
  const [saved, setSaved] = useState(false)

  const save = useMutation({
    mutationFn: () => updateSettings(d.id, {
      name: form.name,
      branding: form.logo_url ? { logo_url: form.logo_url } : undefined,
      settings: {
        week_start: form.week_start,
        report_identity: { display_name: form.report_display_name, footer: form.report_footer },
        report_prefs: { default_format: form.default_format },
      },
    }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['app', 'client', d.id] }); setSaved(true); setTimeout(() => setSaved(false), 2500) },
  })

  const archive = useMutation({
    mutationFn: () => archiveClient(d.id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['app', 'client', d.id] }),
  })
  const restore = useMutation({
    mutationFn: () => restoreClient(d.id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['app', 'client', d.id] }),
  })

  const field = 'h-10 w-full rounded-lg border border-border bg-surface px-3 text-sm outline-none focus:border-brand-500'
  const set = (p: Partial<typeof form>) => setForm((f) => ({ ...f, ...p }))

  return (
    <div className="grid gap-5">
      <form onSubmit={(e) => { e.preventDefault(); save.mutate() }} className="grid gap-3 sm:grid-cols-2" aria-label={t('tab_settings')}>
        <label className="text-xs font-semibold text-text-secondary">{t('cc_settings_name')}
          <input className={field} value={form.name} onChange={(e) => set({ name: e.target.value })} disabled={!d.can.manage_settings} />
        </label>
        <label className="text-xs font-semibold text-text-secondary">{t('cc_settings_logo')}
          <input className={field} value={form.logo_url} placeholder="https://…" onChange={(e) => set({ logo_url: e.target.value })} disabled={!d.can.manage_settings} />
        </label>
        <label className="text-xs font-semibold text-text-secondary">{t('cc_week_start')}
          <select className={field} value={form.week_start} onChange={(e) => set({ week_start: e.target.value })} disabled={!d.can.manage_settings}>
            <option value="sunday">{t('cc_week_sunday')}</option>
            <option value="monday">{t('cc_week_monday')}</option>
          </select>
        </label>
        <label className="text-xs font-semibold text-text-secondary">{t('cc_settings_default_format')}
          <select className={field} value={form.default_format} onChange={(e) => set({ default_format: e.target.value })} disabled={!d.can.manage_settings}>
            <option value="pdf">PDF</option><option value="xlsx">XLSX</option><option value="csv">CSV</option>
          </select>
        </label>
        <label className="text-xs font-semibold text-text-secondary sm:col-span-2">{t('cc_settings_report_identity')}
          <input className={field} value={form.report_display_name} onChange={(e) => set({ report_display_name: e.target.value })} disabled={!d.can.manage_settings} />
        </label>
        <label className="text-xs font-semibold text-text-secondary sm:col-span-2">{t('cc_settings_report_footer')}
          <input className={field} value={form.report_footer} onChange={(e) => set({ report_footer: e.target.value })} disabled={!d.can.manage_settings} />
        </label>
        {d.can.manage_settings && (
          <div className="flex items-center gap-2 sm:col-span-2">
            <button type="submit" disabled={save.isPending} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">{t('cc_save')}</button>
            {saved && <span className="text-xs font-semibold text-success">{t('cc_saved')}</span>}
          </div>
        )}
      </form>

      {d.can.archive && (
        <div className="rounded-xl border border-border bg-surface-secondary p-4">
          {d.is_archived ? (
            <div className="flex items-center justify-between gap-3">
              <p className="text-sm text-text-secondary">{t('cc_archived_banner')}</p>
              <button onClick={() => restore.mutate()} disabled={restore.isPending} className="flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-border bg-surface px-3 py-2 text-sm font-semibold text-text-primary"><RotateCcw size={15} /> {t('cc_restore')}</button>
            </div>
          ) : (
            <button onClick={() => archive.mutate()} disabled={archive.isPending} className="flex items-center gap-1.5 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-sm font-semibold text-warning"><Archive size={15} /> {t('cc_archive')}</button>
          )}
        </div>
      )}
    </div>
  )
}
