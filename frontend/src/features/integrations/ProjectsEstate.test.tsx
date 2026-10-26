import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ProjectsEstate } from './ProjectsEstate'
import { renderWithProviders } from '@/test/utils'

vi.mock('./AccountsPanel', () => ({ AccountsPanel: ({ project }: { project?: string }) => <div data-testid="panel">{project}</div> }))
vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  fetchEstate: vi.fn(() => Promise.resolve({
    projects: [{ project_id: 'p1', project_name: 'Razzah Avenue', providers: ['snapchat'], accounts: 2, last_synced_at: null, needs_attention: 1 }],
  })),
}))

const snap = { key: 'snapchat', label: 'Snapchat', label_ar: 'سناب شات', kind: 'advertising' } as never
const shop = { key: 'salla', label: 'Salla', label_ar: 'سلة', kind: 'commerce' } as never

/** INTEGRATION-ESTATE-001 — client first: one row per project, its accounts on open, a direct per-platform link. */
describe('projects estate', () => {
  it('shows the project, opens its own accounts, and links a platform for THAT project', async () => {
    const onLink = vi.fn()
    renderWithProviders(<ProjectsEstate connectable={[snap, shop]} onLink={onLink} />, { locale: 'en' })

    await waitFor(() => expect(screen.getByTestId('estate-row')).toHaveTextContent('Razzah Avenue'))
    expect(screen.getByTestId('estate-attention')).toHaveTextContent('1 need attention')

    fireEvent.click(screen.getByTestId('estate-toggle-p1'))
    expect(screen.getByTestId('panel')).toHaveTextContent('p1')

    fireEvent.click(screen.getByTestId('estate-link-p1'))
    expect(screen.queryByTestId('estate-link-p1-salla')).not.toBeInTheDocument()
    fireEvent.click(screen.getByTestId('estate-link-p1-snapchat'))
    expect(onLink).toHaveBeenCalledWith('p1', snap)
  })
})
