import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { LiveLinkBuilder } from './LiveLinkBuilder'
import { renderWithProviders } from '@/test/utils'

/**
 * REPORT-IDENTITY-001 and REPORT-SCOPE-SELECTION-001 — the two things the setup screen could not do.
 *
 * It could not say whose marks a client would see, so an operator found out by making the link and
 * opening it. And it offered every campaign in the project as one flat column of names, so a project
 * with four bound ad accounts offered four accounts' campaigns with nothing to tell them apart.
 */
const state = vi.hoisted(() => ({
  identity: {
    name: 'العميل',
    logo_url: null,
    logo_source: 'none',
    by: 'الشركة',
    agency: { name: 'الشركة', logo_url: null },
    client: { name: 'العميل', logo_url: null },
    upload: {
      company: { scope: 'tenant', scope_id: null },
      client: { scope: 'client', scope_id: 'client-1' },
    },
  } as never,
  uploads: [] as Array<{ scope: string; scopeId: string | null; kind: string }>,
}))

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  projectReportIdentity: () => Promise.resolve(state.identity),
  liveBuilderOptions: () => Promise.resolve({
    campaigns: [
      { id: 'c1', name: 'حملة سناب', status: 'active', last_active_on: null, platforms: ['snapchat'] },
      { id: 'c2', name: 'حملة ميتا', status: 'active', last_active_on: null, platforms: ['meta'] },
      { id: 'c3', name: 'حملة مشتركة', status: 'active', last_active_on: null, platforms: ['snapchat', 'meta'] },
    ],
    providers: ['snapchat', 'meta'],
    metrics: [{ key: 'spend', ar: 'الإنفاق', en: 'Spend' }],
  }),
  reportSectionRegistry: () => Promise.resolve({ sections: [] }),
}))

vi.mock('@/features/branding/api', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  uploadBrandingAsset: (input: { scope: string; scopeId: string | null; kind: string }) => {
    state.uploads.push({ scope: input.scope, scopeId: input.scopeId, kind: input.kind })
    return Promise.resolve({} as never)
  },
}))

describe('the report link builder', () => {
  beforeEach(() => { state.uploads = [] })

  /** **Who prepares it and who it is for, before it is sent.** */
  it('shows both identities', async () => {
    renderWithProviders(<LiveLinkBuilder projectId="p1" onClose={vi.fn()} />, { locale: 'ar' })

    // Awaited: the block arrives with the query, so asserting synchronously would assert the loader.
    expect(await screen.findByTestId('report-identity-cards')).toHaveTextContent('هوية التقرير')
    expect(screen.getByTestId('report-identity-company')).toHaveTextContent('من إعداد')
    expect(screen.getByTestId('report-identity-company-name')).toHaveTextContent('الشركة')
    expect(screen.getByTestId('report-identity-client')).toHaveTextContent('مقدم إلى')
    expect(screen.getByTestId('report-identity-client-name')).toHaveTextContent('العميل')
  })

  /**
   * **A mark can be set here, and it is not set per report.**
   *
   * It writes the slot the Branding Center writes — tenant for the company, this project's client
   * for the client — so one upload serves every report rather than being attached to this link.
   */
  it('uploads each mark into the slot that is configured once', async () => {
    renderWithProviders(<LiveLinkBuilder projectId="p1" onClose={vi.fn()} />, { locale: 'ar' })

    const company = await screen.findByTestId('report-identity-company-upload')
    const client = screen.getByTestId('report-identity-client-upload')

    const png = new File([new Uint8Array([137, 80, 78, 71])], 'mark.png', { type: 'image/png' })

    fireEvent.change(company.querySelector('input')!, { target: { files: [png] } })
    await waitFor(() => expect(state.uploads).toHaveLength(1))

    fireEvent.change(client.querySelector('input')!, { target: { files: [png] } })
    await waitFor(() => expect(state.uploads).toHaveLength(2))

    expect(state.uploads[0]).toEqual({ scope: 'tenant', scopeId: null, kind: 'report_logo' })
    expect(state.uploads[1]).toEqual({ scope: 'client', scopeId: 'client-1', kind: 'report_logo' })
  })

  /** And it is not offered at all to somebody who may not manage branding — the server omits it. */
  it('offers no upload when the server sends no target', async () => {
    const withUpload = state.identity
    state.identity = { ...(withUpload as object), upload: null } as never

    renderWithProviders(<LiveLinkBuilder projectId="p1" onClose={vi.fn()} />, { locale: 'ar' })

    await screen.findByTestId('report-identity-cards')
    expect(screen.queryByTestId('report-identity-company-upload')).toBeNull()
    // And the identities are still SHOWN — what is withheld is the ability to change them.
    expect(screen.getByTestId('report-identity-company-name')).toHaveTextContent('الشركة')

    state.identity = withUpload
  })

  /**
   * **The campaign picker narrows by platform.**
   *
   * Three campaigns across two platforms; choosing one platform leaves the campaigns that ran on it,
   * including the one that ran on both.
   */
  it('narrows the campaigns to one platform', async () => {
    renderWithProviders(<LiveLinkBuilder projectId="p1" onClose={vi.fn()} />, { locale: 'ar' })

    const list = await screen.findByTestId('live-builder-campaigns')
    expect(list).toHaveTextContent('حملة سناب')
    expect(list).toHaveTextContent('حملة ميتا')

    fireEvent.click(screen.getByTestId('live-builder-campaign-platform-meta'))

    await waitFor(() => expect(screen.getByTestId('live-builder-campaigns')).not.toHaveTextContent('حملة سناب'))
    expect(screen.getByTestId('live-builder-campaigns')).toHaveTextContent('حملة ميتا')
    // A campaign that ran on both belongs to both.
    expect(screen.getByTestId('live-builder-campaigns')).toHaveTextContent('حملة مشتركة')
  })

  /**
   * **Narrowing never unselects.**
   *
   * A filter that silently dropped a ticked campaign would be the worse version of the problem it
   * was added to solve.
   */
  it('keeps a selected campaign selected when the filter moves', async () => {
    renderWithProviders(<LiveLinkBuilder projectId="p1" onClose={vi.fn()} />, { locale: 'ar' })

    const list = await screen.findByTestId('live-builder-campaigns')
    const snapRow = [...list.querySelectorAll('label')].find((l) => l.textContent?.includes('حملة سناب'))!
    fireEvent.click(snapRow.querySelector('input')!)
    expect(snapRow.querySelector('input')).toBeChecked()

    fireEvent.click(screen.getByTestId('live-builder-campaign-platform-meta'))
    await waitFor(() => expect(screen.getByTestId('live-builder-campaigns')).not.toHaveTextContent('حملة سناب'))

    fireEvent.click(screen.getByTestId('live-builder-campaign-platform-meta'))
    await waitFor(() => expect(screen.getByTestId('live-builder-campaigns')).toHaveTextContent('حملة سناب'))

    const again = [...screen.getByTestId('live-builder-campaigns').querySelectorAll('label')]
      .find((l) => l.textContent?.includes('حملة سناب'))!

    expect(again.querySelector('input')).toBeChecked()
  })
})
