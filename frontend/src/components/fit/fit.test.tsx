import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { Building2 } from 'lucide-react'
import { KpiMini, KpiStrip } from './KpiMini'
import { TabStrip } from './TabStrip'
import { Pager } from './Pager'
import { InfoTip } from './InfoTip'
import { DetailPanel } from './DetailPanel'
import { ResponsiveList } from './ResponsiveList'
import { FitPage } from './FitPage'
import { PortalFrame } from '@/layouts/PortalFrame'
import { renderWithProviders } from '@/test/utils'

function mockWide(wide: boolean) {
  window.matchMedia = vi.fn().mockImplementation((q: string) => ({
    matches: q.includes('min-width: 768px') ? wide : false,
    media: q,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
  })) as unknown as typeof window.matchMedia
}

afterEach(() => { vi.restoreAllMocks(); document.body.style.overflow = '' })

describe('NOV-FIT-001 — the fitted-page primitives', () => {
  it('KpiMini draws the figure left-to-right and tabular, whatever the page direction', () => {
    render(<KpiStrip><KpiMini testid="k" label="Clients" value={6} icon={Building2} tone="brand" hint="All your clients" /></KpiStrip>)

    const figure = screen.getByText('6')
    expect(figure).toHaveAttribute('dir', 'ltr')
    expect(figure.className).toContain('tnum')
    expect(screen.getByRole('button', { name: 'All your clients' })).toBeInTheDocument()
  })

  it('TabStrip is one Tab stop and moves with the arrow keys', () => {
    const onChange = vi.fn()
    render(<TabStrip label="Views" active="a" onChange={onChange} items={[{ key: 'a', label: 'A' }, { key: 'b', label: 'B' }, { key: 'c', label: 'C' }]} />)

    const tabs = screen.getAllByRole('tab')
    expect(tabs.map((t) => t.tabIndex)).toEqual([0, -1, -1])
    fireEvent.keyDown(tabs[0], { key: 'End' })
    expect(onChange).toHaveBeenLastCalledWith('c')
    document.documentElement.dir = 'ltr'
    fireEvent.keyDown(tabs[0], { key: 'ArrowRight' })
    expect(onChange).toHaveBeenLastCalledWith('b')
    // Reading right-to-left, the arrow that points forward is the left one.
    document.documentElement.dir = 'rtl'
    fireEvent.keyDown(tabs[0], { key: 'ArrowLeft' })
    expect(onChange).toHaveBeenLastCalledWith('b')
  })

  it('Pager says where you are and stops at both ends', () => {
    const onPage = vi.fn()
    const { rerender } = render(<Pager page={1} pages={3} total={61} ar={false} onPage={onPage} />)

    expect(screen.getByText('1 / 3')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled()
    fireEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(onPage).toHaveBeenCalledWith(2)

    rerender(<Pager page={3} pages={3} ar={false} onPage={onPage} />)
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled()
  })

  it('InfoTip carries the sentence as its name and opens on press, closes on Escape', () => {
    render(<InfoTip text="What this page is for" />)
    const button = screen.getByRole('button', { name: 'What this page is for' })

    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()
    fireEvent.click(button)
    expect(screen.getByRole('tooltip')).toHaveTextContent('What this page is for')
    fireEvent.keyDown(document, { key: 'Escape' })
    expect(screen.queryByRole('tooltip')).not.toBeInTheDocument()
  })

  it('DetailPanel is a modal layer that closes on Escape and the backdrop and locks the page behind', () => {
    const onClose = vi.fn()
    render(<DetailPanel open onClose={onClose} title="Details" closeLabel="Close" testid="d"><p>body</p></DetailPanel>)

    expect(screen.getByRole('dialog', { name: 'Details' })).toHaveAttribute('aria-modal', 'true')
    expect(document.body.style.overflow).toBe('hidden')
    fireEvent.keyDown(document, { key: 'Escape' })
    fireEvent.click(screen.getByTestId('d-backdrop'))
    expect(onClose).toHaveBeenCalledTimes(2)
  })

  it('DetailPanel draws nothing while closed', () => {
    render(<DetailPanel open={false} onClose={() => undefined} title="Details" closeLabel="Close"><p>body</p></DetailPanel>)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  const rows = [{ id: '1', name: 'Alpha' }, { id: '2', name: 'Beta' }]
  const list = (onOpen?: (r: (typeof rows)[number]) => void) => (
    <ResponsiveList
      testid="l"
      rows={rows}
      rowKey={(r) => r.id}
      columns={[{ key: 'name', header: 'Name', cell: (r) => r.name }]}
      renderCard={(r) => <article>card:{r.name}</article>}
      onOpen={onOpen}
    />
  )

  it('ResponsiveList is a table of primary columns on a tablet and up, and says each name once', () => {
    mockWide(true)
    const onOpen = vi.fn()
    render(list(onOpen))

    expect(screen.getByRole('table')).toBeInTheDocument()
    expect(screen.getAllByText('Alpha')).toHaveLength(1)
    expect(screen.queryByText('card:Alpha')).not.toBeInTheDocument()
    fireEvent.click(screen.getByText('Beta'))
    expect(onOpen).toHaveBeenCalledWith(rows[1])
  })

  it('ResponsiveList is a list of cards on a phone, with no table in the document', () => {
    mockWide(false)
    render(list())

    expect(screen.queryByRole('table')).not.toBeInTheDocument()
    expect(screen.getByText('card:Alpha')).toBeInTheDocument()
    expect(screen.getAllByRole('listitem')).toHaveLength(2)
  })

  it('FitPage locks the portal frame while it is mounted and lifts the lock when it leaves', () => {
    const frame = (fitted: boolean) => (
      <PortalFrame testId="frame">{fitted ? <FitPage title="Clients" info="About clients"><p>body</p></FitPage> : <p>plain</p>}</PortalFrame>
    )
    const { rerender } = renderWithProviders(frame(true))

    expect(screen.getByTestId('frame')).toHaveAttribute('data-fit', 'true')
    expect(screen.getByRole('heading', { level: 1, name: 'Clients' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'About clients' })).toBeInTheDocument()

    rerender(frame(false))
    expect(screen.getByTestId('frame')).not.toHaveAttribute('data-fit')
  })
})
