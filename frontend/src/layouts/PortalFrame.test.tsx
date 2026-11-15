import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { PortalFrame } from './PortalFrame'
import { renderWithProviders } from '@/test/utils'

function frame(onClose = vi.fn()) {
  renderWithProviders(
    <PortalFrame
      drawerOpen
      onDrawerClose={onClose}
      drawerLabel="Menu"
      drawer={<><button>first</button><a href="/x">second</a><button>last</button></>}
      rail={<span>rail</span>}
    >
      <p>page</p>
    </PortalFrame>,
  )

  return onClose
}

describe('the phone navigation layer (NOV-SIDEBAR-001)', () => {
  it('is a modal dialog above the page with a backdrop', () => {
    frame()

    const dialog = screen.getByRole('dialog', { name: 'Menu' })
    expect(dialog).toHaveAttribute('aria-modal', 'true')
    expect(screen.getByTestId('mobile-drawer-backdrop')).toBeInTheDocument()
  })

  it('closes on Escape and on a press on the backdrop', () => {
    const onClose = frame()

    fireEvent.keyDown(document, { key: 'Escape' })
    expect(onClose).toHaveBeenCalledTimes(1)
    fireEvent.click(screen.getByTestId('mobile-drawer-backdrop'))
    expect(onClose).toHaveBeenCalledTimes(2)
  })

  it('moves focus in and keeps Tab inside', () => {
    frame()
    const first = screen.getByRole('button', { name: 'first' })
    const last = screen.getByRole('button', { name: 'last' })

    last.focus()
    fireEvent.keyDown(document, { key: 'Tab' })
    expect(document.activeElement).toBe(first)

    fireEvent.keyDown(document, { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(last)
  })

  it('does not scroll the page behind it', () => {
    frame()
    expect(document.body.style.overflow).toBe('hidden')
  })
})
