import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'

import { FilterMulti } from './FilterBar'

/**
 * UX-MULTISELECT-SCALE-002 — the selector when the option list lives on the SERVER.
 *
 * The bounded-DOM work (SCALE-001) fixed what the control renders. It did not fix where the options
 * came from: the breakdown, windowed by the period, one full metrics row per campaign. This covers
 * the server-search mode, and every case here is one where doing the obvious thing would leave the
 * reader stuck rather than merely slow:
 *
 *   1. The control must NOT filter again on top of the server. Between a keystroke and its response
 *      the list on screen answers the PREVIOUS term, and re-filtering it against the new one reports
 *      «nothing matched» while the request that would say otherwise is still open.
 *   2. A term that matches nothing must leave the control OPEN. Disabling on an empty result takes
 *      away the input holding the term that caused it.
 *   3. A selected campaign keeps its NAME after it drops out of the option list — which it routinely
 *      does, because the list is the current term's matches, not the reader's selection.
 *   4. «There are more» states no total, because the endpoint counts nothing.
 */
const server = (over: Partial<Parameters<typeof FilterMulti>[0]['search']> = {}) => ({
  term: '',
  onTerm: vi.fn(),
  hasMore: false,
  loading: false,
  ...over,
})

const renderMulti = (
  options: Array<{ value: string; label: string }>,
  search: NonNullable<Parameters<typeof FilterMulti>[0]['search']>,
  values: string[] = [],
) => {
  const onChange = vi.fn()
  const view = render(
    <FilterMulti
      label="Campaign"
      testid="sel"
      ar={false}
      values={values}
      options={options}
      search={search}
      onChange={onChange}
    />,
  )
  return { onChange, view }
}

const open = () => fireEvent.click(screen.getByTestId('sel'))

describe('the multi-select reading options from the server', () => {
  it('renders what the server returned without filtering it again', () => {
    /*
     * The term matches NONE of these labels. A client-side filter would render zero options; the
     * server already decided this page is the answer, so all three must survive.
     */
    renderMulti(
      [
        { value: 'a', label: 'Ramadan Retargeting' },
        { value: 'b', label: 'Always-On Search' },
        { value: 'c', label: 'Riyadh Season' },
      ],
      server({ term: 'zzzz' }),
    )
    open()

    expect(screen.getAllByRole('option')).toHaveLength(3)
  })

  it('lifts the term to the caller instead of filtering in place', () => {
    const search = server()
    renderMulti([{ value: 'a', label: 'Ramadan Retargeting' }], search)
    open()

    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'ram' } })

    expect(search.onTerm).toHaveBeenCalledWith('ram')
  })

  it('offers the search box even when the page came back short', () => {
    /*
     * `options.length` is a bounded page, not the size of the estate, so it cannot answer «is this
     * long enough to need search». A two-result page is exactly when removing the input would
     * strand a reader mid-search.
     */
    renderMulti([{ value: 'a', label: 'A' }, { value: 'b', label: 'B' }], server({ term: 'a' }))
    open()

    expect(screen.getByRole('searchbox')).toBeInTheDocument()
  })

  it('stays usable when a term matches nothing', () => {
    renderMulti([], server({ term: 'no such campaign' }))

    expect(screen.getByTestId('sel')).not.toBeDisabled()
    open()
    expect(screen.getByRole('searchbox')).toHaveValue('no such campaign')
  })

  it('says it is searching rather than saying nothing matched', () => {
    renderMulti([], server({ term: 'ram', loading: true }))
    open()

    expect(screen.getByTestId('sel-loading')).toHaveTextContent('Searching')
  })

  it('is disabled only when the project genuinely has no campaigns', () => {
    renderMulti([], server({ term: '', loading: false }))

    expect(screen.getByTestId('sel')).toBeDisabled()
  })

  it('keeps a selected campaign named after it leaves the option list', () => {
    /* A real id, because the fallback this guards against is rendering exactly that. */
    const id = '9f2c1d84-6b3e-4a17-9c55-0f7e2ab41d63'
    const { view } = renderMulti(
      [{ value: id, label: 'Ramadan Retargeting' }],
      server(),
      [id],
    )
    expect(screen.getByTestId('sel')).toHaveTextContent('Ramadan Retargeting')

    /* The reader types something that does not match their own selection. */
    view.rerender(
      <FilterMulti
        label="Campaign"
        testid="sel"
        ar={false}
        values={[id]}
        options={[{ value: 'z', label: 'Riyadh Season' }]}
        search={server({ term: 'riyadh' })}
        onChange={vi.fn()}
      />,
    )

    expect(screen.getByTestId('sel')).toHaveTextContent('Ramadan Retargeting')
    expect(screen.getByTestId('sel')).not.toHaveTextContent(id)
  })

  it('reports that more exist without inventing a total', () => {
    renderMulti(
      Array.from({ length: 120 }, (_, i) => ({ value: `c-${i}`, label: `Campaign ${i}` })),
      server({ hasMore: true }),
    )
    open()

    const line = screen.getByTestId('sel-has-more')
    expect(line).toHaveTextContent('Showing 120')
    expect(line).toHaveTextContent('narrow the search')
    /* No «of N»: the server fetches one past the cap and never counts the rest. */
    expect(line.textContent).not.toMatch(/\bof\b/)
  })

  it('says nothing about more when the server said there are none', () => {
    renderMulti([{ value: 'a', label: 'A' }], server())
    open()

    expect(screen.queryByTestId('sel-has-more')).not.toBeInTheDocument()
  })
})
