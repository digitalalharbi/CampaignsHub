import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { FilesLibraryPage } from './FilesLibraryPage'
import type { FilesLibrary, LibraryFile } from './api'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, getFilesLibrary: vi.fn() }
})

import { getFilesLibrary } from './api'

/**
 * FILES-LIBRARY-001 — the count is what exists, and the gap is stated.
 *
 * The endpoint caps its response at five hundred and said nothing about it, so this page counted
 * the rows it was handed: a workspace holding eight hundred files read «500», which is
 * indistinguishable from a workspace that holds five hundred. The reader concludes «these are my
 * files», and they are wrong.
 */
const file = (id: string): LibraryFile => ({
  source: 'request', id, name: `brief-${id}.pdf`, type: 'application/pdf', size: 10,
  visibility: 'client_visible', uploaded_at: '2026-08-01T00:00:00Z', uploader: 'O',
  client_id: 'c1', client_name: 'Acme', related: { type: 'request', label: 'REQ-1' },
  download_url: null,
})

const library = (over: Partial<FilesLibrary> = {}): FilesLibrary => ({
  files: [file('a'), file('b')],
  files_total: 2,
  files_withheld: 0,
  drive_links: 0,
  ...over,
})

describe('the files library', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['clients.manage_files', 'files.view'])
  })
  afterEach(() => signOut())

  it('counts what exists, not what arrived', async () => {
    vi.mocked(getFilesLibrary).mockResolvedValue(library({ files_total: 812, files_withheld: 810 }))
    renderWithProviders(<FilesLibraryPage />, { locale: 'en' })

    expect(await screen.findByText('812')).toBeInTheDocument()
  })

  /* A heading saying «812» over five hundred rows is a different lie unless the gap is stated. */
  it('says how many it is not listing', async () => {
    vi.mocked(getFilesLibrary).mockResolvedValue(library({ files_total: 812, files_withheld: 810 }))
    renderWithProviders(<FilesLibraryPage />, { locale: 'en' })

    const note = await screen.findByTestId('files-withheld')
    expect(note.textContent).toContain('812')
    expect(note.textContent).toContain('810')
  })

  it('says nothing when the whole library fitted', async () => {
    vi.mocked(getFilesLibrary).mockResolvedValue(library())
    renderWithProviders(<FilesLibraryPage />, { locale: 'en' })

    expect(await screen.findByText('brief-a.pdf')).toBeInTheDocument()
    expect(screen.queryByTestId('files-withheld')).toBeNull()
  })

  /**
   * The line that identifies a file stays recoverable when it is cut.
   *
   * The card's second line holds the two things that say WHICH file this is — whose it is and what
   * it belongs to — and `line-clamp-1` cuts it. At 1440 the card read «…emo Client ·
   * REQ-DEMO-CLIENT-»: the client clipped at the front and the reference at the back, neither
   * recoverable. The table view shows both in full, so the information exists; only this view lost
   * it with no way back. The name above it already carried a `title` for exactly this reason.
   */
  it('keeps the client and the reference reachable on a card that truncates', async () => {
    vi.mocked(getFilesLibrary).mockResolvedValue(library())

    renderWithProviders(<FilesLibraryPage />, { locale: 'en' })

    await screen.findByText('brief-a.pdf')

    const line = screen.getAllByTitle('Acme · REQ-1')[0]
    expect(line).toBeInTheDocument()
    expect(line).toHaveTextContent('Acme')
    expect(line).toHaveTextContent('REQ-1')
  })
})
