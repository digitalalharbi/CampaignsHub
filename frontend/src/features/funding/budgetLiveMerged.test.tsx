import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { PlatformMarks, SpendTrack, rowPlatforms, LiveChip } from './BudgetBoard'

/** BUDGET-LIVE-MERGED-001 — «شغّالة ولا واقفة» rides the budget: one track cut by platform, one mark per platform. */
describe('budget row: live state merged into the budget', () => {
  const row = {
    by_platform: [
      { provider: 'meta', spent: 1000 },
      { provider: 'snapchat', spent: 3000 },
    ],
    delivering_platforms: { snapchat: true, meta: false, tiktok: true },
  }

  it('orders the platforms by spend, then any delivering platform with no spend, one colour each', () => {
    const list = rowPlatforms(row)
    expect(list.map((p) => p.provider)).toEqual(['snapchat', 'meta', 'tiktok'])
    expect(new Set(list.map((p) => p.color)).size).toBe(3)
    expect(list.find((p) => p.provider === 'tiktok')?.part).toBeUndefined()
  })

  it('fills the spent share of the budget, cut by the platforms’ shares', () => {
    render(<SpendTrack platforms={rowPlatforms(row)} used={0.4} red={false} tone="success" ar />)
    const track = screen.getByTestId('platform-split')
    expect((track.firstElementChild as HTMLElement).style.width).toBe('40%')
    expect(screen.getByTestId('split-snapchat').style.width).toBe('75%')
    expect(screen.getByTestId('split-meta').style.width).toBe('25%')
    expect(screen.queryByTestId('split-tiktok')).toBeNull()
  })

  it('marks each platform green when running and grey when stopped, running first', () => {
    render(<PlatformMarks platforms={rowPlatforms(row)} ar />)
    const marks = within(screen.getByTestId('live-platforms')).getAllByTestId(/^live-platform-/)
    expect(marks.map((m) => m.dataset.on)).toEqual(['true', 'true', 'false'])
    expect(screen.getByTestId('live-platform-meta').getAttribute('title')).toContain('واقفة')
    expect(screen.getByTestId('live-platform-snapchat').getAttribute('title')).toContain('شغّالة')
  })

  it('names the budget’s own state in one chip', () => {
    const { rerender } = render(<LiveChip on ar testId="chip" />)
    expect(screen.getByTestId('chip')).toHaveTextContent('شغّالة')
    rerender(<LiveChip on={false} ar testId="chip" />)
    expect(screen.getByTestId('chip')).toHaveTextContent('واقفة')
  })
})
