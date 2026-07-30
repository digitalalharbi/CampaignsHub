import { describe, expect, it } from 'vitest'
import { clientSpaceBaseOf, clientSpaceSlugOf } from './clientSpace'

/**
 * The slug in the URL is the ONLY thing that says which of an agency's clients the visitor is
 * looking at — it decides both the links on the page and the header sent with every request. Getting
 * it wrong shows one brand's invoices under another brand's name, so the parsing is pinned here.
 */
describe('clientSpaceSlugOf', () => {
  it.each([
    ['/portal/clients/acme', 'acme'],
    ['/portal/clients/acme/invoices', 'acme'],
    ['/portal/clients/acme/invoices/inv-1', 'acme'],
    ['/portal/clients/two%20words', 'two words'],
  ])('reads %s as %s', (path, slug) => {
    expect(clientSpaceSlugOf(path)).toBe(slug)
  })

  /** Outside a space there is NO slug — and no header, so the server keeps its existing behaviour. */
  it.each(['/client', '/client/invoices', '/portal', '/portal/clients', '/portal/clients/', '/app/clients/x'])(
    'reads no space from %s',
    (path) => {
      expect(clientSpaceSlugOf(path)).toBeNull()
    },
  )
})

describe('clientSpaceBaseOf', () => {
  it('keeps links inside the space the visitor is in', () => {
    expect(clientSpaceBaseOf('/portal/clients/acme/quotes')).toBe('/portal/clients/acme')
  })

  /** The shared view still works untouched, so no existing link or bookmark breaks. */
  it('falls back to the shared client view outside a space', () => {
    expect(clientSpaceBaseOf('/client/quotes')).toBe('/client')
  })

  it('re-encodes a slug that needed encoding', () => {
    expect(clientSpaceBaseOf('/portal/clients/two%20words/files')).toBe('/portal/clients/two%20words')
  })
})
