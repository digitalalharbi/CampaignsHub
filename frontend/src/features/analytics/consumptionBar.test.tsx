import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { ConsumptionBar } from './ConsumptionBar'
import { renderWithProviders } from '@/test/utils'

describe('ConsumptionBar', () => {
  it('marks where the plan expected to be today, and says so in words', () => {
    renderWithProviders(<ConsumptionBar consumed={0.75} expected={5000} total={10000} />, { locale: 'en' })

    expect(screen.getByRole('img', { name: 'Consumed 75% — expected by now 50%' })).toBeInTheDocument()
    expect(screen.getByTestId('consumption-expected')).toHaveStyle({ left: 'calc(50% - 1px)' })
  })

  it('colours by pace: 75% spent against 50% expected is running ahead; 90% against 95% is on plan', () => {
    const { unmount } = renderWithProviders(<ConsumptionBar consumed={0.75} expected={5000} total={10000} />, { locale: 'en' })
    expect(document.querySelector('.bg-danger')).not.toBeNull()
    unmount()

    renderWithProviders(<ConsumptionBar consumed={0.9} expected={9500} total={10000} />, { locale: 'en' })
    expect(document.querySelector('.bg-danger')).toBeNull()
    expect(document.querySelector('.bg-brand-500')).not.toBeNull()
  })

  it('draws no tick without an expected figure and falls back to the absolute thresholds', () => {
    renderWithProviders(<ConsumptionBar consumed={0.92} expected={null} total={10000} />, { locale: 'en' })

    expect(screen.queryByTestId('consumption-expected')).not.toBeInTheDocument()
    expect(document.querySelector('.bg-danger')).not.toBeNull()
    expect(screen.getByRole('img', { name: 'Consumed 92%' })).toBeInTheDocument()
  })

  it('speaks Arabic', () => {
    renderWithProviders(<ConsumptionBar consumed={0.5} expected={5000} total={10000} />, { locale: 'ar' })
    expect(screen.getByRole('img', { name: /المستهلك 50%/ })).toBeInTheDocument()
  })
})
