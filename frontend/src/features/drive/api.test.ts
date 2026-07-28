import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api/client', () => ({
  api: { get: vi.fn() },
  postData: vi.fn(),
  deleteData: vi.fn(),
  ensureCsrfCookie: vi.fn().mockResolvedValue(undefined),
}))

import { api, deleteData, ensureCsrfCookie, postData } from '@/lib/api/client'
import { attachDriveFile, linkFolder, listDriveFiles, listDriveLinks, unlinkFolder } from './api'

const mockGet = vi.mocked(api.get)

describe('drive api layer', () => {
  beforeEach(() => vi.clearAllMocks())

  it('lists links and unwraps the envelope', async () => {
    mockGet.mockResolvedValue({ data: { data: [{ id: 'l1' }] } })
    expect(await listDriveLinks()).toEqual([{ id: 'l1' }])
    expect(api.get).toHaveBeenCalledWith('/drive/links')
  })

  it('reads the HONEST browse state + provider from the envelope meta (awaiting = no files)', async () => {
    mockGet.mockResolvedValue({ data: { data: [], meta: { state: 'awaiting_credentials', provider: 'null', configured: false } } })
    const out = await listDriveFiles('l1')
    expect(api.get).toHaveBeenCalledWith('/drive/links/l1/files')
    expect(out).toEqual({ files: [], state: 'awaiting_credentials', provider: 'null', configured: false })
  })

  it('surfaces sandbox demo files as sandbox_verified + configured', async () => {
    mockGet.mockResolvedValue({ data: { data: [{ id: 'f1', name: 'Brief.pdf' }], meta: { state: 'sandbox_verified', provider: 'sandbox', configured: true } } })
    const out = await listDriveFiles('l1')
    expect(out.configured).toBe(true)
    expect(out.state).toBe('sandbox_verified')
    expect(out.files).toHaveLength(1)
  })

  it('defaults to awaiting_credentials when meta is absent (never fabricate a connection)', async () => {
    mockGet.mockResolvedValue({ data: { data: [] } })
    const out = await listDriveFiles('l1')
    expect(out.state).toBe('awaiting_credentials')
    expect(out.configured).toBe(false)
  })

  it('links a folder with a normalized body after priming CSRF', async () => {
    vi.mocked(postData).mockResolvedValue({ id: 'l2' })
    await linkFolder({ scope: 'project', scopeId: 'p-1', folderId: 'F1', folderName: 'Creatives' })
    expect(ensureCsrfCookie).toHaveBeenCalled()
    expect(postData).toHaveBeenCalledWith('/drive/links', {
      scope: 'project', scope_id: 'p-1', folder_id: 'F1', folder_name: 'Creatives', connection_id: null,
    })
  })

  it('attaches a file to a target', async () => {
    vi.mocked(postData).mockResolvedValue({ id: 'f1', attached_to_id: 't-1' })
    await attachDriveFile('f 1', { attachedToType: 'creative', attachedToId: 't-1' })
    expect(postData).toHaveBeenCalledWith('/drive/files/f%201/attach', { attached_to_type: 'creative', attached_to_id: 't-1' })
  })

  it('unlinks by encoded id', async () => {
    await unlinkFolder('l 3')
    expect(deleteData).toHaveBeenCalledWith('/drive/links/l%203')
  })
})
