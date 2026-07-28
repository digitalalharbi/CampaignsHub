import { api, deleteData, ensureCsrfCookie, postData } from '@/lib/api/client'
import type { ApiEnvelope } from '@/lib/api/types'

/**
 * Google Drive content-linking API layer. HONESTY: browsing a link returns a `state` in the envelope meta —
 * `awaiting_credentials` (no real Google OAuth wired) yields zero files; the off-prod Sandbox provider yields
 * `sandbox_verified` demo files. This layer never fabricates a live Drive connection.
 */

/** Folder-link scopes accepted by the backend (DriveService::SCOPES). */
export const DRIVE_SCOPES = ['tenant', 'client', 'project', 'campaign'] as const
export type DriveScope = (typeof DRIVE_SCOPES)[number]

export interface DriveLink {
  id: string
  tenant_id: string | null
  scope: DriveScope
  scope_id: string | null
  folder_id: string
  folder_name: string
  connection_id: string | null
  created_at?: string | null
}

export interface DriveFile {
  id: string
  drive_link_id: string
  file_id: string
  name: string
  mime: string | null
  size: number | null
  thumbnail_link: string | null
  web_view_link: string | null
  modified_time: string | null
  version: string | null
  attached_to_type: string | null
  attached_to_id: string | null
}

/** The honest browse states the backend reports in meta.state. */
export type DriveBrowseState = 'awaiting_credentials' | 'sandbox_verified' | 'synced'

export interface DriveFilesResult {
  files: DriveFile[]
  state: DriveBrowseState
  provider: string
  configured: boolean
}

export async function listDriveLinks(): Promise<DriveLink[]> {
  const res = await api.get<ApiEnvelope<DriveLink[]>>('/drive/links')
  return res.data.data ?? []
}

export interface LinkFolderInput {
  scope: DriveScope
  scopeId?: string | null
  folderId: string
  folderName: string
  connectionId?: string | null
}

export async function linkFolder(input: LinkFolderInput): Promise<DriveLink> {
  await ensureCsrfCookie()
  return postData<DriveLink>('/drive/links', {
    scope: input.scope,
    scope_id: input.scopeId ?? null,
    folder_id: input.folderId,
    folder_name: input.folderName,
    connection_id: input.connectionId ?? null,
  })
}

/** Browse a link's files. Reads the honest state/provider/configured from the envelope meta. */
export async function listDriveFiles(linkId: string): Promise<DriveFilesResult> {
  const res = await api.get<ApiEnvelope<DriveFile[]>>(`/drive/links/${encodeURIComponent(linkId)}/files`)
  const meta = res.data.meta ?? {}
  return {
    files: res.data.data ?? [],
    state: (meta.state as DriveBrowseState) ?? 'awaiting_credentials',
    provider: (meta.provider as string) ?? 'null',
    configured: Boolean(meta.configured),
  }
}

export async function unlinkFolder(linkId: string): Promise<void> {
  await ensureCsrfCookie()
  await deleteData(`/drive/links/${encodeURIComponent(linkId)}`)
}

export interface AttachFileInput {
  attachedToType: string
  attachedToId: string
}

export async function attachDriveFile(fileId: string, input: AttachFileInput): Promise<DriveFile> {
  await ensureCsrfCookie()
  return postData<DriveFile>(`/drive/files/${encodeURIComponent(fileId)}/attach`, {
    attached_to_type: input.attachedToType,
    attached_to_id: input.attachedToId,
  })
}
