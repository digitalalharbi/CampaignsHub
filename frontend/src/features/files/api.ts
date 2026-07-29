import { getData } from '@/lib/api/client'

/** A row in the unified files library. Mirrors backend FilesLibraryController (read-only, real stores). */
export interface LibraryFile {
  source: 'request' | 'report' | string
  id: string
  name: string
  type: string | null
  size: number | null
  visibility: 'client_visible' | 'internal' | string
  uploaded_at: string | null
  uploader: string | null
  client_id: string | null
  client_name: string | null
  related: { type: string; label: string | null }
  download_url: string | null
}

export interface FilesLibrary {
  files: LibraryFile[]
  drive_links: number
}

export const getFilesLibrary = () => getData<FilesLibrary>('/files/library')
