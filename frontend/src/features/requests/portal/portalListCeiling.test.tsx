import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PortalListCeiling } from './PortalListCeiling'

/**
 * A ceiling the client is not told about is indistinguishable from the whole truth.
 *
 * Every portal list is capped at two hundred rows on the server. The cap kept the NEWEST rows, which
 * is the safe direction, and said nothing — so a client with two hundred and forty invoices saw two
 * hundred and had every reason to read that as all of them.
 *
 * The three silent cases are the point of this file. Zero withheld is a real answer and must not put
 * «older ones are not shown» under every short list; `undefined` is a response cached from before the
 * server sent the counts, and a page that cannot tell must say nothing rather than something false.
 */
describe('the portal list ceiling', () => {
  it('says how many of how many, when the ceiling really held something back', () => {
    render(<PortalListCeiling withheld={40} total={240} ar={false} />)

    const note = screen.getByTestId('portal-list-ceiling')

    expect(note).toHaveTextContent('200')
    expect(note).toHaveTextContent('240')
  })

  it('says it in Arabic, with Latin digits', () => {
    render(<PortalListCeiling withheld={40} total={240} ar />)

    const note = screen.getByTestId('portal-list-ceiling')

    expect(note.textContent).toMatch(/هذه أحدث/)
    expect(note).toHaveTextContent('240')
  })

  it('says nothing when the whole list is on the page', () => {
    render(<PortalListCeiling withheld={0} total={12} ar={false} />)

    expect(screen.queryByTestId('portal-list-ceiling')).toBeNull()
  })

  it('says nothing when the server did not send the counts', () => {
    render(<PortalListCeiling ar={false} />)

    expect(screen.queryByTestId('portal-list-ceiling')).toBeNull()
  })
})
