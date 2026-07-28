import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CloudOff, ExternalLink, FileText, FolderPlus, Link2, Paperclip, Trash2 } from 'lucide-react'
import { useUi } from '@/stores/ui'
import { useAuth } from '@/stores/auth'
import { toApiError } from '@/lib/api/client'
import {
  DRIVE_SCOPES, attachDriveFile, linkFolder, listDriveFiles, listDriveLinks, unlinkFolder,
  type DriveBrowseState, type DriveFile, type DriveLink, type DriveScope,
} from './api'

/** Bilingual copy — self-contained to this feature (Arabic-first). */
const COPY = {
  ar: {
    title: 'روابط Google Drive', subtitle: 'اربط مجلدات Drive لكل نطاق، وتصفّح ملفاتها، واربطها بالأهداف — من دون نسخ أي ملف.',
    no_permission: 'لا تملك صلاحية عرض روابط Drive.', loading: 'جارٍ التحميل…',
    new_link: 'ربط مجلد', scope: 'النطاق', scope_id: 'معرّف النطاق (اختياري)', folder_id: 'معرّف المجلد', folder_name: 'اسم المجلد',
    link_btn: 'ربط', linking: 'جارٍ الربط…', browse: 'تصفّح', unlink: 'إلغاء الربط',
    no_links: 'لا توجد روابط بعد — اربط مجلد Drive للبدء.',
    files_title: 'ملفات', close: 'إغلاق', open: 'فتح في Drive', attach: 'إرباط', attached: 'مربوط', attaching: 'جارٍ…',
    file_name: 'الاسم', file_type: 'النوع', file_modified: 'آخر تعديل', file_size: 'الحجم', file_actions: '',
    awaiting_title: 'اربط Google Drive', awaiting_body: 'لا يوجد اعتماد Google OAuth بعد — لذلك لا تُعرض أي ملفات. هذه حالة صادقة: لن تُختلق أي بيانات Drive حتى تُوفّر الاعتمادات.',
    provider: 'المزوّد', state: 'الحالة', no_files: 'لا توجد ملفات في هذا المجلد.',
    st_awaiting: 'بانتظار اعتماد', st_sandbox: 'تجريبي موثّق', st_synced: 'مُزامَن',
    attach_type: 'نوع الهدف', attach_id: 'معرّف الهدف (UUID)', manage_note: 'الربط والإرباط والحذف تتطلّب صلاحية drive.manage.',
  },
  en: {
    title: 'Google Drive links', subtitle: 'Link Drive folders per scope, browse their files, and attach them to targets — without copying any file.',
    no_permission: 'You do not have permission to view Drive links.', loading: 'Loading…',
    new_link: 'Link a folder', scope: 'Scope', scope_id: 'Scope id (optional)', folder_id: 'Folder id', folder_name: 'Folder name',
    link_btn: 'Link', linking: 'Linking…', browse: 'Browse', unlink: 'Unlink',
    no_links: 'No links yet — link a Drive folder to get started.',
    files_title: 'Files', close: 'Close', open: 'Open in Drive', attach: 'Attach', attached: 'Attached', attaching: 'Attaching…',
    file_name: 'Name', file_type: 'Type', file_modified: 'Modified', file_size: 'Size', file_actions: '',
    awaiting_title: 'Connect Google Drive', awaiting_body: 'No Google OAuth credentials are wired yet, so no files are shown. This is an honest state: no Drive data is fabricated until credentials are provisioned.',
    provider: 'Provider', state: 'State', no_files: 'No files in this folder.',
    st_awaiting: 'Awaiting credentials', st_sandbox: 'Sandbox verified', st_synced: 'Synced',
    attach_type: 'Target type', attach_id: 'Target id (UUID)', manage_note: 'Linking, attaching, and unlinking require the drive.manage permission.',
  },
}
type Copy = (typeof COPY)['ar']

const SCOPE_LABEL: Record<DriveScope, { ar: string; en: string }> = {
  tenant: { ar: 'المستأجر', en: 'Tenant' },
  client: { ar: 'العميل', en: 'Client' },
  project: { ar: 'المشروع', en: 'Project' },
  campaign: { ar: 'الحملة', en: 'Campaign' },
}

const STATE_STYLE: Record<DriveBrowseState, string> = {
  awaiting_credentials: 'bg-warning/15 text-warning',
  sandbox_verified: 'bg-purple/15 text-purple',
  synced: 'bg-success/15 text-success',
}

export function DrivePage() {
  const locale = useUi((s) => s.locale)
  const c = COPY[locale]
  const canView = useAuth((s) => s.hasPermission('drive.view'))
  const canManage = useAuth((s) => s.hasPermission('drive.manage'))
  const [openLink, setOpenLink] = useState<DriveLink | null>(null)

  const q = useQuery({ queryKey: ['drive-links'], queryFn: listDriveLinks, enabled: canView })

  if (!canView) {
    return (
      <div className="mx-auto w-full max-w-5xl p-4 md:p-6">
        <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{c.no_permission}</p>
      </div>
    )
  }

  const links = q.data ?? []

  return (
    <div className="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 md:p-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-3xl font-extrabold tracking-tight text-text-primary">{c.title}</h1>
        <p className="text-sm text-text-secondary">{c.subtitle}</p>
      </header>

      <div className="grid gap-4 md:grid-cols-[1fr_320px]">
        <div className="flex flex-col gap-2">
          {q.isLoading ? (
            <p className="p-8 text-center text-sm text-text-secondary">{c.loading}</p>
          ) : links.length === 0 ? (
            <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{c.no_links}</p>
          ) : (
            links.map((link) => (
              <LinkRow key={link.id} c={c} locale={locale} link={link} canManage={canManage} onBrowse={() => setOpenLink(link)} />
            ))
          )}
        </div>

        {canManage
          ? <LinkForm c={c} locale={locale} />
          : <p className="flex h-fit items-start gap-2 rounded-2xl border border-border bg-surface p-4 text-xs text-text-secondary"><CloudOff size={14} className="mt-0.5 shrink-0 text-warning" /> {c.manage_note}</p>}
      </div>

      {openLink && <FilesPanel c={c} link={openLink} canManage={canManage} onClose={() => setOpenLink(null)} />}
    </div>
  )
}

function LinkRow({ c, locale, link, canManage, onBrowse }: {
  c: Copy; locale: 'ar' | 'en'; link: DriveLink; canManage: boolean; onBrowse: () => void
}) {
  const qc = useQueryClient()
  const unlinkM = useMutation({ mutationFn: () => unlinkFolder(link.id), onSuccess: () => qc.invalidateQueries({ queryKey: ['drive-links'] }) })

  return (
    <div className="flex items-center justify-between gap-3 rounded-2xl border border-border bg-surface p-3.5">
      <div className="flex items-center gap-3">
        <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-surface-hover text-text-secondary"><Link2 size={16} /></span>
        <div className="flex flex-col gap-0.5">
          <span className="font-semibold text-text-primary">{link.folder_name}</span>
          <span className="text-[11px] text-text-tertiary tnum">
            {SCOPE_LABEL[link.scope]?.[locale] ?? link.scope} · {link.folder_id}
          </span>
        </div>
      </div>
      <div className="flex items-center gap-1.5">
        <button
          onClick={onBrowse}
          className="flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1 text-xs font-semibold text-text-secondary hover:border-brand-500 hover:text-brand-600"
        >
          <FileText size={13} /> {c.browse}
        </button>
        {canManage && (
          <button
            onClick={() => unlinkM.mutate()} disabled={unlinkM.isPending} aria-label={c.unlink}
            className="flex items-center justify-center rounded-lg border border-border px-2 py-1 text-text-secondary hover:border-danger hover:text-danger disabled:opacity-50"
          >
            <Trash2 size={13} />
          </button>
        )}
      </div>
    </div>
  )
}

function LinkForm({ c, locale }: { c: Copy; locale: 'ar' | 'en' }) {
  const qc = useQueryClient()
  const [scope, setScope] = useState<DriveScope>('project')
  const [scopeId, setScopeId] = useState('')
  const [folderId, setFolderId] = useState('')
  const [folderName, setFolderName] = useState('')
  const [error, setError] = useState<string | null>(null)

  const createM = useMutation({
    mutationFn: () => linkFolder({ scope, scopeId: scopeId.trim() || null, folderId: folderId.trim(), folderName: folderName.trim() }),
    onSuccess: () => {
      setError(null); setFolderId(''); setFolderName(''); setScopeId('')
      qc.invalidateQueries({ queryKey: ['drive-links'] })
    },
    onError: (e) => setError(toApiError(e).message),
  })

  return (
    <form
      onSubmit={(e) => { e.preventDefault(); createM.mutate() }}
      className="flex h-fit flex-col gap-3 rounded-2xl border border-border bg-surface p-4"
    >
      <h3 className="flex items-center gap-2 text-sm font-bold text-text-primary"><FolderPlus size={15} /> {c.new_link}</h3>
      <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
        {c.scope}
        <select value={scope} onChange={(e) => setScope(e.target.value as DriveScope)}
          className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary">
          {DRIVE_SCOPES.map((s) => <option key={s} value={s}>{SCOPE_LABEL[s][locale]}</option>)}
        </select>
      </label>
      <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
        {c.scope_id}
        <input value={scopeId} onChange={(e) => setScopeId(e.target.value)} placeholder="—"
          className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary" />
      </label>
      <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
        {c.folder_id}
        <input required value={folderId} onChange={(e) => setFolderId(e.target.value)} placeholder="1AbC…"
          className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary" />
      </label>
      <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
        {c.folder_name}
        <input required minLength={1} value={folderName} onChange={(e) => setFolderName(e.target.value)}
          className="rounded-lg border border-border bg-background px-2.5 py-1.5 text-sm text-text-primary" />
      </label>
      <button type="submit" disabled={createM.isPending}
        className="rounded-lg bg-brand-600 px-3 py-2 text-sm font-bold text-white hover:bg-brand-700 disabled:opacity-50">
        {createM.isPending ? c.linking : c.link_btn}
      </button>
      {error && <span className="text-[11px] font-semibold text-danger">{error}</span>}
    </form>
  )
}

function FilesPanel({ c, link, canManage, onClose }: {
  c: Copy; link: DriveLink; canManage: boolean; onClose: () => void
}) {
  const q = useQuery({ queryKey: ['drive-files', link.id], queryFn: () => listDriveFiles(link.id) })
  const result = q.data

  return (
    <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-4">
      <div className="flex items-center justify-between">
        <h3 className="flex items-center gap-2 text-sm font-bold text-text-primary">
          <FileText size={15} /> {c.files_title} · {link.folder_name}
          {result && (
            <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${STATE_STYLE[result.state] ?? 'bg-surface-hover text-text-secondary'}`}>
              {result.state === 'awaiting_credentials' ? c.st_awaiting : result.state === 'sandbox_verified' ? c.st_sandbox : c.st_synced}
            </span>
          )}
        </h3>
        <button onClick={onClose} className="rounded-lg border border-border px-2.5 py-1 text-xs font-semibold text-text-secondary hover:text-text-primary">{c.close}</button>
      </div>

      {q.isLoading || !result ? (
        <p className="p-6 text-center text-sm text-text-secondary">{c.loading}</p>
      ) : !result.configured ? (
        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-warning/40 bg-warning/5 p-8 text-center">
          <CloudOff size={28} className="text-warning" />
          <span className="text-sm font-bold text-text-primary">{c.awaiting_title}</span>
          <p className="max-w-md text-xs text-text-secondary">{c.awaiting_body}</p>
          <span className="text-[11px] text-text-tertiary tnum">{c.provider}: {result.provider}</span>
        </div>
      ) : result.files.length === 0 ? (
        <p className="rounded-xl border border-dashed border-border p-6 text-center text-sm text-text-secondary">{c.no_files}</p>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {result.files.map((file) => (
            <FileCard key={file.id} c={c} file={file} canManage={canManage} />
          ))}
        </ul>
      )}
    </div>
  )
}

function FileCard({ c, file, canManage }: { c: Copy; file: DriveFile; canManage: boolean }) {
  const qc = useQueryClient()
  const [attaching, setAttaching] = useState(false)
  const [attached, setAttached] = useState(Boolean(file.attached_to_id))
  const [type, setType] = useState('')
  const [id, setId] = useState('')
  const [error, setError] = useState<string | null>(null)

  const attachM = useMutation({
    mutationFn: () => attachDriveFile(file.id, { attachedToType: type.trim(), attachedToId: id.trim() }),
    onSuccess: () => { setAttached(true); setAttaching(false); setError(null); qc.invalidateQueries({ queryKey: ['drive-files'] }) },
    onError: (e) => setError(toApiError(e).message),
  })

  return (
    <li className="flex flex-col gap-2 rounded-xl border border-border bg-background p-2.5">
      <div className="flex aspect-video items-center justify-center overflow-hidden rounded-lg border border-border bg-surface-hover">
        {file.thumbnail_link
          ? <img src={file.thumbnail_link} alt={file.name} className="h-full w-full object-cover" />
          : <FileText size={22} className="text-text-tertiary" />}
      </div>
      <span className="truncate text-xs font-semibold text-text-primary" title={file.name}>{file.name}</span>
      <span className="text-[10px] text-text-tertiary tnum">
        {(file.mime ?? '—').split('/').pop()}
        {file.size ? ` · ${fmtBytes(file.size)}` : ''}
        {file.modified_time ? ` · ${fmtDate(file.modified_time)}` : ''}
      </span>
      <div className="flex items-center gap-1.5">
        {file.web_view_link && (
          <a
            href={file.web_view_link} target="_blank" rel="noopener noreferrer"
            className="flex flex-1 items-center justify-center gap-1 rounded-lg border border-border px-2 py-1 text-[11px] font-semibold text-text-secondary hover:border-brand-500 hover:text-brand-600"
          >
            <ExternalLink size={12} /> {c.open}
          </a>
        )}
        {canManage && !attached && (
          <button
            onClick={() => setAttaching((v) => !v)}
            className="flex items-center justify-center gap-1 rounded-lg border border-border px-2 py-1 text-[11px] font-semibold text-text-secondary hover:border-brand-500 hover:text-brand-600"
          >
            <Paperclip size={12} /> {c.attach}
          </button>
        )}
        {attached && <span className="flex items-center gap-1 text-[11px] font-semibold text-success"><Paperclip size={12} /> {c.attached}</span>}
      </div>
      {attaching && !attached && (
        <form
          onSubmit={(e) => { e.preventDefault(); attachM.mutate() }}
          className="flex flex-col gap-1.5 border-t border-border pt-2"
        >
          <input required value={type} onChange={(e) => setType(e.target.value)} placeholder={c.attach_type}
            className="rounded-lg border border-border bg-surface px-2 py-1 text-[11px] text-text-primary" />
          <input required value={id} onChange={(e) => setId(e.target.value)} placeholder={c.attach_id}
            className="rounded-lg border border-border bg-surface px-2 py-1 text-[11px] text-text-primary tnum" />
          <button type="submit" disabled={attachM.isPending}
            className="rounded-lg bg-brand-600 px-2 py-1 text-[11px] font-bold text-white hover:bg-brand-700 disabled:opacity-50">
            {attachM.isPending ? c.attaching : c.attach}
          </button>
          {error && <span className="text-[10px] font-semibold text-danger">{error}</span>}
        </form>
      )}
    </li>
  )
}

function fmtBytes(n: number): string {
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${Math.round(n / 102.4) / 10} KB`
  return `${Math.round(n / (1024 * 104.8576)) / 10} MB`
}
function fmtDate(iso: string): string {
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { dateStyle: 'medium' })
}
