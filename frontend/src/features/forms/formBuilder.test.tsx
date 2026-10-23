import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { FormBuilderPage } from './FormBuilderPage'
import { FormsPage } from './FormsPage'
import { starterDefinition } from './builderState'
import { renderWithProviders, signInWith } from '@/test/utils'
import { useProject } from '@/stores/project'

vi.mock('@/features/projects/api', async (orig) => ({ ...(await orig<Record<string, unknown>>()), listUsers: vi.fn().mockResolvedValue([{ id: 7, name: 'Huda', email: 'h@x.test' }]), listProjects: vi.fn().mockResolvedValue([]), listClientWorkspaces: vi.fn().mockResolvedValue([]) }))
vi.mock('@/lib/api/client', async (orig) => ({ ...(await orig<typeof import('@/lib/api/client')>()), getData: vi.fn(), postData: vi.fn(), patchData: vi.fn() }))
import { getData, patchData, postData } from '@/lib/api/client'

const detail = (over: Record<string, unknown> = {}) => ({
  id: 'f1', name: 'Villa enquiry', status: 'draft', slug: 'abcdefgh1234', public_url: 'https://app.test/f/abcdefgh1234', default_owner_id: null, retention_days: null,
  published_version_id: null, created_at: null, definition: starterDefinition(), definition_version: 1, definition_published: false, versions: [], ...over,
})

describe('the forms list and the builder', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['leads.view', 'leads.update'])
    vi.mocked(getData).mockImplementation((path: string) => {
      if (path === '/projects/p1/forms') return Promise.resolve([{ ...detail(), status: 'published', submissions: 3 }])
      if (path === '/projects/p1/forms/f1') return Promise.resolve(detail())
      return Promise.resolve([])
    })
  })

  it('lists the forms with their state, count and public address', async () => {
    renderWithProviders(<FormsPage />, { route: '/agency/forms' })
    expect(await screen.findByTestId('form-card-f1')).toHaveTextContent('3 submissions')
    expect(screen.getByTestId('form-card-f1')).toHaveTextContent('https://app.test/f/abcdefgh1234')
  })

  it('creates a form from the starter and opens its builder', async () => {
    vi.mocked(postData).mockResolvedValue(detail())
    renderWithProviders(<FormsPage />, { route: '/agency/forms' })
    fireEvent.click(await screen.findByTestId('forms-new'))
    await waitFor(() => expect(postData).toHaveBeenCalled())
    const [path, body] = vi.mocked(postData).mock.calls[0] as [string, { name: string; definition: { steps: unknown[] } }]
    expect(path).toBe('/projects/p1/forms')
    expect(body.definition.steps).toHaveLength(1)
  })

  it('edits a field and saves the whole definition as a draft', async () => {
    vi.mocked(patchData).mockResolvedValue(detail())
    renderWithProviders(<FormBuilderPage />, { route: '/agency/forms/f1', path: '/agency/forms/:formId' })
    fireEvent.click(await screen.findByTestId('add-0-select'))
    fireEvent.change(screen.getByTestId('field-editor-0-2-label'), { target: { value: 'Interest' } })
    fireEvent.change(screen.getByTestId('field-editor-0-2-options'), { target: { value: 'villa|Villa\nland|Land' } })
    fireEvent.click(screen.getByTestId('form-save'))

    await waitFor(() => expect(patchData).toHaveBeenCalled())
    const [path, body] = vi.mocked(patchData).mock.calls[0] as [string, { definition: { steps: Array<{ fields: Array<{ id: string; options?: Array<{ value: string }> }> }> } }]
    expect(path).toBe('/projects/p1/forms/f1')
    const added = body.definition.steps[0].fields[2]
    expect(added.options?.map((o) => o.value)).toEqual(['villa', 'land'])
    expect(await screen.findByTestId('builder-message')).toHaveTextContent('Draft saved')
  })

  it('shows the server’s reason when a definition is refused, and previews what the visitor sees', async () => {
    vi.mocked(patchData).mockRejectedValue({ isAxiosError: true, response: { data: { message: 'A form needs a field mapped to the lead\'s name.' } } })
    renderWithProviders(<FormBuilderPage />, { route: '/agency/forms/f1', path: '/agency/forms/:formId' })
    expect(await screen.findByTestId('form-preview')).toHaveTextContent('Name')
    fireEvent.click(screen.getByTestId('form-save'))
    expect(await screen.findByTestId('builder-message')).toHaveTextContent(/Could not save|mapped/)
  })

  it('publishes after saving', async () => {
    vi.mocked(patchData).mockResolvedValue(detail())
    vi.mocked(postData).mockResolvedValue(detail({ status: 'published' }))
    renderWithProviders(<FormBuilderPage />, { route: '/agency/forms/f1', path: '/agency/forms/:formId' })
    fireEvent.click(await screen.findByTestId('form-publish'))
    await waitFor(() => expect(postData).toHaveBeenCalledWith('/projects/p1/forms/f1/publish', {}))
  })

  it('turns on the optional measurement consent with its own wording and saves it in the definition', async () => {
    vi.mocked(patchData).mockResolvedValue(detail())
    renderWithProviders(<FormBuilderPage />, { route: '/agency/forms/f1', path: '/agency/forms/:formId' })
    fireEvent.click(await screen.findByTestId('measurement-enabled'))
    fireEvent.change(screen.getByTestId('measurement-text'), { target: { value: 'Report my hashed details.' } })
    fireEvent.click(screen.getByTestId('form-save'))

    await waitFor(() => expect(patchData).toHaveBeenCalled())
    const body = vi.mocked(patchData).mock.calls[0][1] as { definition: { consent: { measurement: { enabled: boolean; text: { en: string } } } } }
    expect(body.definition.consent.measurement.enabled).toBe(true)
    expect(body.definition.consent.measurement.text.en).toBe('Report my hashed details.')
    expect(await screen.findByTestId('form-preview')).toBeInTheDocument()
  })
})
