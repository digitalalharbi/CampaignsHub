import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { Route, Routes } from 'react-router-dom'
import { ProjectIntegrationsPage } from './ProjectIntegrationsPage'
import { renderWithProviders, signInWith } from '@/test/utils'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
  postData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * A project that is not there gets ONE answer, not two.
 *
 * Opening `/projects/<gone>/integrations` drew «العنصر المطلوب غير موجود» from the platform panel
 * and then carried on rendering the working surface underneath it — bound accounts 0, campaigns 0,
 * a «manage data sources» button. So the page said the item does not exist and, more prominently,
 * that it exists and has nothing bound to it. Those are different claims and only one is true.
 *
 * Reproduced in a browser against a UUID no row can hold, which is the route below.
 *
 * The param comes from a REAL route rather than a mocked `useParams`: replacing react-router-dom
 * for one export made this file fail at import when the whole suite ran, while passing alone.
 */
const GONE = '00000000-0000-4000-8000-000000000000'

/* The axios envelope `toApiError` reads — a bare Error with a status reaches its «unexpected» case. */
const notFound = Object.assign(new Error('not found'), {
  isAxiosError: true,
  response: { status: 404, data: { message: 'not found' } },
})

describe('the project integrations page for a project that is not there', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['projects.view'])
    vi.mocked(getData).mockRejectedValue(notFound)
  })

  it('says the project does not exist, and does not also describe it', async () => {
    renderWithProviders(
      <Routes>
        <Route path="/projects/:projectId/integrations" element={<ProjectIntegrationsPage />} />
      </Routes>,
      { locale: 'en', route: `/projects/${GONE}/integrations` },
    )

    expect(await screen.findByText('This project does not exist')).toBeInTheDocument()

    /* The working surface is absent — not merely pushed below the error. */
    expect(screen.queryByText('Account bindings')).not.toBeInTheDocument()
    expect(screen.queryByText(/manage data sources/i)).not.toBeInTheDocument()
  })
})
