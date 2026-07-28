import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { DrivePage } from './DrivePage'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import type { DriveFile, DriveLink } from './api'

vi.mock('./api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./api')>()
  return {
    ...actual,
    listDriveLinks: vi.fn(),
    listDriveFiles: vi.fn(),
    linkFolder: vi.fn(),
    unlinkFolder: vi.fn(),
    attachDriveFile: vi.fn(),
  }
})

import { listDriveFiles, listDriveLinks } from './api'

function link(): DriveLink {
  return { id: 'l1', tenant_id: 't1', scope: 'project', scope_id: null, folder_id: 'F1', folder_name: 'Creatives', connection_id: null }
}
function file(): DriveFile {
  return {
    id: 'f1', drive_link_id: 'l1', file_id: 'sandbox-file-1', name: 'Campaign Brief.pdf', mime: 'application/pdf',
    size: 248213, thumbnail_link: 'https://drive.example.test/thumb.png', web_view_link: 'https://drive.example.test/view',
    modified_time: '2026-07-21T10:01:00+00:00', version: '3', attached_to_type: null, attached_to_id: null,
  }
}

describe('DrivePage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(listDriveLinks).mockResolvedValue([link()])
  })
  afterEach(() => signOut())

  it('blocks without drive.view', () => {
    signInWith([])
    renderWithProviders(<DrivePage />)
    expect(screen.getByText(/do not have permission/i)).toBeInTheDocument()
  })

  it('lists folder links and hides the link form without drive.manage', async () => {
    signInWith(['drive.view'])
    renderWithProviders(<DrivePage />)
    expect(await screen.findByText('Creatives')).toBeInTheDocument()
    expect(screen.queryByText('Link a folder')).not.toBeInTheDocument()
    expect(screen.getByText(/drive\.manage/)).toBeInTheDocument()
  })

  it('shows the honest "connect Google Drive" state when the provider is not configured', async () => {
    vi.mocked(listDriveFiles).mockResolvedValue({ files: [], state: 'awaiting_credentials', provider: 'null', configured: false })
    signInWith(['drive.view'])
    renderWithProviders(<DrivePage />)
    fireEvent.click(await screen.findByText('Browse'))
    await waitFor(() => expect(listDriveFiles).toHaveBeenCalledWith('l1'))
    expect(await screen.findByText('Connect Google Drive')).toBeInTheDocument()
  })

  it('browses sandbox demo files with a working "open in Drive" link', async () => {
    vi.mocked(listDriveFiles).mockResolvedValue({ files: [file()], state: 'sandbox_verified', provider: 'sandbox', configured: true })
    signInWith(['drive.view'])
    renderWithProviders(<DrivePage />)
    fireEvent.click(await screen.findByText('Browse'))
    expect(await screen.findByText('Campaign Brief.pdf')).toBeInTheDocument()
    const open = screen.getByText('Open in Drive').closest('a')
    expect(open).toHaveAttribute('href', 'https://drive.example.test/view')
    expect(open).toHaveAttribute('target', '_blank')
  })

  it('shows the link form with drive.manage', async () => {
    signInWith(['drive.view', 'drive.manage'])
    renderWithProviders(<DrivePage />)
    expect(await screen.findByText('Link a folder')).toBeInTheDocument()
  })
})
