import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, useSearchParams } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { useUrlWriter } from './filterUrlState'

/**
 * ANALYTICS-FILTER-TRUTH-001 — applying a saved view is ONE statement, and it used to be three.
 *
 * `filterUrlState` documents the rule this broke, in its own words: «Two `useUrlState` setters called
 * in the same handler do not compose: each functional update is applied against the params of the
 * render it was created in, so the second silently drops the first's change.» `applyView` called three
 * — objective, provider, days — so a reader's default view restored whichever ran last and discarded
 * the rest.
 *
 * And the write that did land was built from an earlier render's params, so anything the reader had
 * changed in between went with it. That is what made the ads-table gate intermittent: the saved views
 * request resolves asynchronously, and when it arrived after a tab click the query string was rewritten
 * without the tab — `aria-selected` stayed false while the click had certainly landed.
 *
 * The three-key case is asserted here rather than in a page render, because what has to hold is the
 * WRITER's composition: one call, three keys, and nothing else in the query string disturbed.
 */
function Harness({ write }: { write: Record<string, { value: string; fallback: string }> }) {
  const [params] = useSearchParams()
  const writer = useUrlWriter()

  return (
    <div>
      <button type="button" onClick={() => writer(write)}>apply</button>
      <output data-testid="qs">{params.toString()}</output>
    </div>
  )
}

function mount(initial: string, write: Record<string, { value: string; fallback: string }>) {
  return render(
    <MemoryRouter initialEntries={[`/x?${initial}`]}>
      <Harness write={write} />
    </MemoryRouter>,
  )
}

describe('applying a saved view', () => {
  it('lands every key in one write, not just the last one', () => {
    mount('', {
      objective: { value: 'sales', fallback: 'all' },
      provider: { value: 'meta,snapchat', fallback: '' },
      days: { value: '7', fallback: '30' },
    })

    fireEvent.click(screen.getByRole('button', { name: 'apply' }))

    const qs = screen.getByTestId('qs').textContent ?? ''
    expect(qs).toContain('objective=sales')
    expect(qs).toContain('provider=meta%2Csnapchat')
    expect(qs).toContain('days=7')
  })

  /**
   * The intermittency, reproduced as a unit: a key the reader set before the view arrived must survive.
   *
   * This is the tab. Applying a view rewrote the query string from an earlier render's params, so the
   * tab the reader had just clicked was dropped and the control snapped back to its default.
   */
  it('leaves a key it was not asked to change exactly where it was', () => {
    mount('tab=ad_sets&drill=ad_set%3As-1', {
      objective: { value: 'sales', fallback: 'all' },
      days: { value: '7', fallback: '30' },
    })

    fireEvent.click(screen.getByRole('button', { name: 'apply' }))

    const qs = screen.getByTestId('qs').textContent ?? ''
    expect(qs, 'the reader’s tab was dropped by a write that had no business touching it').toContain('tab=ad_sets')
    expect(qs).toContain('drill=ad_set%3As-1')
    expect(qs).toContain('objective=sales')
  })

  /** A default is absent rather than spelled out — rule 1 of this module. */
  it('removes a key whose value is its own default', () => {
    mount('objective=sales&days=7', {
      objective: { value: 'all', fallback: 'all' },
      days: { value: '30', fallback: '30' },
    })

    fireEvent.click(screen.getByRole('button', { name: 'apply' }))

    const qs = screen.getByTestId('qs').textContent ?? ''
    expect(qs).not.toContain('objective=')
    expect(qs).not.toContain('days=')
  })
})
