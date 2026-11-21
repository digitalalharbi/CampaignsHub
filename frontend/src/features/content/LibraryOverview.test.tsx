import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { renderWithProviders, signOut } from '@/test/utils'
import { LibraryOverview } from './LibraryOverview'
import type { ContentOverview } from './intake/api'

vi.mock('./intake/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./intake/api')>()
  return { ...actual, getContentOverview: vi.fn() }
})
import { getContentOverview } from './intake/api'

const overview: ContentOverview = {
  matrix: [
    { provider: 'snapchat', family: 'sales', count: 6, fatigued: 1, watch: 1, objectives: ['conversions'], metric: 'roas', higher_is_better: true, average: 2.4, previous_average: 2, ranked: 6,
      best: { id: 'c1', name: 'Best story', format: 'video', kind: 'video', value: 3.5, average: 2.2, delta_percent: 59.1, spend_share: 0.31 },
      worst: { id: 'c2', name: 'Weak static', format: 'image', kind: 'image', value: 1.1, average: 2.6, delta_percent: -57.7, spend_share: null } },
    { provider: 'meta', family: 'traffic', count: 2, fatigued: 0, watch: 0, objectives: ['traffic'], metric: null, average: null, previous_average: null, ranked: 0, best: null, worst: null },
  ],
  fatigue: { fatigued: 1, watch: 1, stable: 4, improving: 2, insufficient_data: 2 },
  flagged: [{ id: 'c3', name: 'Tired ad', provider: 'snapchat', status: 'fatigued', frequency: 4.2, ctr: 0.01, signals: [], reason_ar: 'ar', reason_en: 'CTR fell 30% while frequency rose' }],
  read: 10, in_scope: 10, window: { from: '2026-10-20', to: '2026-11-18' },
}

describe('LibraryOverview', () => {
  afterEach(() => signOut())

  it('draws the matrix and narrows the page to a cell on click, lifting it on the second click', async () => {
    vi.mocked(getContentOverview).mockResolvedValue(overview)
    const setAxis = vi.fn()
    const { rerender } = renderWithProviders(<LibraryOverview query={{ from: 'a', to: 'b' }} ar={false} axes={{}} setAxis={setAxis} health="" setHealth={() => {}} />)
    const cell = await screen.findByTestId('matrix-snapchat-sales')
    expect(cell).toHaveTextContent('6')
    fireEvent.click(cell)
    expect(setAxis).toHaveBeenCalledWith('providers', ['snapchat'])
    expect(setAxis).toHaveBeenCalledWith('objectives', ['conversions'])
    setAxis.mockClear()
    rerender(<LibraryOverview query={{ from: 'a', to: 'b' }} ar={false} axes={{ providers: ['snapchat'], objectives: ['conversions'] }} setAxis={setAxis} health="" setHealth={() => {}} />)
    fireEvent.click(await screen.findByTestId('matrix-snapchat-sales'))
    expect(setAxis).toHaveBeenCalledWith('providers', [])
    expect(setAxis).toHaveBeenCalledWith('objectives', [])
  })

  it('shows best and weakest as a measured gap, and withholds a spend share it was not given', async () => {
    vi.mocked(getContentOverview).mockResolvedValue(overview)
    renderWithProviders(<LibraryOverview query={{}} ar={false} axes={{}} setAxis={() => {}} health="" setHealth={() => {}} />)
    const best = await screen.findByTestId('why-best-c1')
    expect(best).toHaveTextContent('Best story')
    expect(best).toHaveTextContent('+59.1%')
    expect(best).toHaveTextContent('31%')
    const worst = screen.getByTestId('why-worst-c2')
    expect(worst).toHaveTextContent('-57.7%')
    expect(worst).not.toHaveTextContent(/carried/)
  })

  it('filters by fatigue state and explains an unranked cell instead of ranking it', async () => {
    vi.mocked(getContentOverview).mockResolvedValue(overview)
    const setHealth = vi.fn()
    renderWithProviders(<LibraryOverview query={{}} ar={false} axes={{}} setAxis={() => {}} health="" setHealth={setHealth} />)
    fireEvent.click(await screen.findByTestId('fatigue-chip-fatigued'))
    expect(setHealth).toHaveBeenCalledWith('fatigued')
    fireEvent.mouseEnter(screen.getByTestId('matrix-meta-traffic'))
    expect(screen.getByTestId('matrix-hover')).toHaveTextContent(/Fewer than three measured items/)
    expect(screen.getByTestId('overview-flagged')).toHaveTextContent('CTR fell 30% while frequency rose')
  })

  it('renders Arabic labels', async () => {
    vi.mocked(getContentOverview).mockResolvedValue(overview)
    renderWithProviders(<LibraryOverview query={{}} ar axes={{}} setAxis={() => {}} health="" setHealth={() => {}} />, { locale: 'ar' })
    expect(await screen.findByText('نظرة على المحتوى')).toBeInTheDocument()
    expect(screen.getByTestId('fatigue-chip-fatigued')).toHaveTextContent('مرهق')
  })
})
