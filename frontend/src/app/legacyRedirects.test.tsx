import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { Route, Routes } from 'react-router-dom'
import { legacyAppRedirects, legacyClientPortalRedirects } from './legacyRedirects'
import { renderWithProviders } from '@/test/utils'

/**
 * The external client portal moved from `/client/*` to `/portal/*` (ADR 0002).
 *
 * Those old paths are in clients' bookmarks and in emails already sent. A client who follows a link
 * from a quote notification and lands on a blank page cannot recover — they have no account of their
 * own to retry with, and the next step is a support conversation. So each old path must still
 * resolve, and land on the SAME record it always did.
 */
function renderAt(route: string) {
  return renderWithProviders(
    <Routes>
      {legacyClientPortalRedirects.map((r) => (
        <Route key={r.path} path={r.path} element={r.element} />
      ))}
      <Route path="/portal" element={<p>portal:home</p>} />
      <Route path="/portal/login" element={<p>portal:login</p>} />
      <Route path="/portal/invoices" element={<p>portal:invoices</p>} />
      <Route path="/portal/invoices/:id" element={<p>portal:invoice-detail</p>} />
      <Route path="/portal/requests/:reference" element={<p>portal:request-detail</p>} />
    </Routes>,
    { route, locale: 'en' },
  )
}

describe('the /client → /portal redirects', () => {
  it.each([
    ['/client', 'portal:home'],
    ['/client/login', 'portal:login'],
    ['/client/invoices', 'portal:invoices'],
  ])('sends %s to %s', (from, landing) => {
    renderAt(from)
    expect(screen.getByText(landing)).toBeInTheDocument()
  })

  /** The id must survive — landing on the invoice LIST from an invoice link is still a dead end. */
  it('carries the record id through', () => {
    renderAt('/client/invoices/inv-42')
    expect(screen.getByText('portal:invoice-detail')).toBeInTheDocument()
  })

  it('carries a request reference through', () => {
    renderAt('/client/requests/REQ-2026-0007')
    expect(screen.getByText('portal:request-detail')).toBeInTheDocument()
  })

  /** A tracking query string is part of the link someone was sent; dropping it loses the context. */
  it('keeps the query string', () => {
    renderAt('/client/invoices?from=email')
    expect(screen.getByText('portal:invoices')).toBeInTheDocument()
    expect(window.location.search === '' || window.location.search === '?from=email').toBe(true)
  })
})

/**
 * Fifteen pre-move root paths were missing from the redirect list, so a bookmark to `/integrations`
 * or `/billing/invoices` was a dead link — found by comparing the two lists, not by waiting for a
 * report. This keeps them compared: every section the advertiser portal serves must be reachable
 * from the path it lived at before the move.
 */
describe('the /* → /app/* redirect list', () => {
  const covered = new Set(legacyAppRedirects.map((r) => r.path))

  it.each([
    '/integrations',
    '/integrations/drive',
    '/connections',
    '/drive',
    '/alerts',
    '/billing',
    '/billing/quotes',
    '/billing/invoices',
    '/billing/payments',
    '/finance',
    '/messages',
    '/subscriptions',
    '/branding',
    '/requests',
    '/requests/:requestId',
    '/clients/:clientId',
  ])('still serves %s', (path) => {
    expect(covered.has(path)).toBe(true)
  })
})
