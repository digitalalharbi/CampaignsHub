import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { ColumnPicker } from './ColumnPicker'

describe('ColumnPicker', () => {
  it('offers every column ticked while nothing is restricted', () => {
    render(<ColumnPicker value={null} onChange={() => {}} ar={false} />)
    for (const k of ['spend', 'results', 'cost', 'impressions', 'clicks', 'ctr']) {
      expect(screen.getByTestId(`column-${k}`)).toHaveAttribute('aria-pressed', 'true')
    }
  })

  it('narrows to the remaining columns when one is unticked', () => {
    const onChange = vi.fn()
    render(<ColumnPicker value={null} onChange={onChange} ar={false} />)
    fireEvent.click(screen.getByTestId('column-cost'))
    expect(onChange).toHaveBeenCalledWith(['spend', 'results', 'impressions', 'clicks', 'ctr'])
  })

  it('goes back to «no restriction» when every column is ticked again', () => {
    const onChange = vi.fn()
    render(<ColumnPicker value={['spend', 'results', 'impressions', 'clicks', 'ctr']} onChange={onChange} ar={false} />)
    fireEvent.click(screen.getByTestId('column-cost'))
    expect(onChange).toHaveBeenCalledWith(null)
  })

  it('speaks Arabic', () => {
    render(<ColumnPicker value={null} onChange={() => {}} ar />)
    expect(screen.getByText('أعمدة جدول المنصات')).toBeInTheDocument()
  })
})
