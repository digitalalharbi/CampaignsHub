import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { router } from './router'
import { NotFoundPage } from './NotFoundPage'
import { useUi } from '@/stores/ui'

/**
 * ROUTE-BOUNDARY-001 — a crash inside the app lands on a page, not on a stack trace.
 *
 * Only the `*` route declared an `errorElement`, so a render error anywhere in the authenticated tree
 * bubbled past every route to React Router's DEFAULT boundary: «Unexpected Application Error!» over a
 * raw English stack trace, in a customer's portal. That is how the campaign Funnel tab's crash
 * presented before `0490892`; the crash is fixed, but the presentation belonged to the router, and
 * the next one would have looked identical.
 */
describe('the router catches what the pages throw', () => {
  it('gives every top-level branch a boundary to land on', () => {
    const naked = router.routes.filter((r) => !r.errorElement)

    expect(
      naked.map((r) => r.path ?? '(index)'),
      'these branches would render React Router’s raw stack trace to a customer',
    ).toEqual([])
  })

  /**
   * And the boundary is a real page. `NotFoundPage` doubles as the error element, and it must say
   * something went wrong rather than «that page does not exist» — a visitor told their address is
   * wrong, when it is not, goes looking for a mistake they did not make.
   */
  it('renders a page that admits the error instead of denying the address', () => {
    const Boom = () => {
      throw new Error('the funnel tab exploded')
    }
    const memory = createMemoryRouter(
      [{ path: '/app/campaigns/:projectId/:campaignId', element: <Boom />, errorElement: <NotFoundPage /> }],
      { initialEntries: ['/app/campaigns/p1/c1'] },
    )

    // Rendered bare rather than through `renderWithProviders`: that helper supplies its own router,
    // and React Router refuses to nest one inside another.
    useUi.setState({ locale: 'en' })
    render(<RouterProvider router={memory} />)

    expect(screen.getByTestId('not-found')).toHaveTextContent('Something went wrong on this page')
    expect(screen.getByTestId('not-found')).not.toHaveTextContent('That page does not exist')
    // The address is shown so a support report can name it, and the way back is a real link.
    expect(screen.getByText('/app/campaigns/p1/c1')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Back to the home page/ })).toBeInTheDocument()
  })
})
