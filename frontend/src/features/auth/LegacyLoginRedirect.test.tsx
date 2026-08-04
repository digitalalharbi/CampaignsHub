import { describe, expect, it } from 'vitest'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { render, screen } from '@testing-library/react'
import { LegacyLoginRedirect } from './LegacyLoginRedirect'

/** Renders wherever the router ended up, so the assertion is on the real outcome. */
function Landing() {
  const { pathname, search } = useLocation()
  return <span data-testid="landed">{pathname + search}</span>
}

function openAt(path: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/login" element={<Landing />} />
        <Route path="/admin/login" element={<LegacyLoginRedirect />} />
        <Route path="/app/login" element={<LegacyLoginRedirect />} />
        <Route path="/agency/login" element={<LegacyLoginRedirect />} />
        <Route path="/portal/login" element={<LegacyLoginRedirect />} />
        <Route path="/influencers/login" element={<LegacyLoginRedirect />} />
      </Routes>
    </MemoryRouter>,
  )
}

/**
 * LOGIN-UNIFIED-001 — every old door leads to the only door, and none of them loops.
 *
 * These addresses are live in the wild: bookmarked, pasted into chats, printed in handover
 * documents. Answering 404 would be a dead end of exactly the kind ACCESS-EXIT-001 removes, so they
 * redirect — and the redirect has to carry the destination through, or somebody stopped at the auth
 * gate on `/agency/clients` gets returned to a portal home and reads the link as having been wrong.
 */
describe('the old per-portal doors (LOGIN-UNIFIED-001)', () => {
  it.each([
    '/admin/login',
    '/app/login',
    '/agency/login',
    '/portal/login',
    '/influencers/login',
  ])('%s lands on /login', (path) => {
    openAt(path)
    expect(screen.getByTestId('landed')).toHaveTextContent('/login')
  })

  it('carries the post-auth destination through', () => {
    openAt('/agency/login?redirect=%2Fagency%2Fclients')
    expect(screen.getByTestId('landed')).toHaveTextContent('/login?redirect=%2Fagency%2Fclients')
  })

  /**
   * The portal in the PATH is deliberately dropped rather than translated into `?portal=`.
   *
   * A path segment claiming a portal was only ever a request the server had to check anyway, and
   * turning it into a query parameter would put the portal back in the URL's hands — which is the
   * thing this whole change removes.
   */
  it('does not turn the old path into a portal claim', () => {
    openAt('/admin/login')
    expect(screen.getByTestId('landed')).not.toHaveTextContent('portal=')
  })
})
