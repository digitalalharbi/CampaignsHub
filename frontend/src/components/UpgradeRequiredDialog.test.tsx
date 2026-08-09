import { describe, expect, it, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { UpgradeRequiredDialog } from './UpgradeRequiredDialog'
import { useUpgrade, type UpgradeRefusal } from '@/stores/upgrade'
import { useUi } from '@/stores/ui'

/** PAY-AUDIT-004 — what a customer refused for a commercial reason actually sees. */
describe('UpgradeRequiredDialog', () => {
  beforeEach(() => {
    useUpgrade.setState({ refusal: null })
    useUi.setState({ locale: 'ar' })
  })

  const show = (over: Partial<UpgradeRefusal> = {}) =>
    useUpgrade.setState({
      refusal: {
        message: 'لقد بلغت الحد الأقصى من المشاريع (3 من 3).',
        reason: 'plan_limit',
        subject: 'projects',
        used: 3,
        limit: 3,
        plan: 'starter',
        upgradePath: '/app/subscriptions',
        ...over,
      },
    })

  /*
   * Rendered BARE, with no router around it — the way it really mounts.
   *
   * This used to wrap the component in a `MemoryRouter`, and that is precisely what hid the defect:
   * the dialog is mounted in `Providers`, above the router, where `<Link>` throws. The suite was
   * green while the live app logged «An error occurred in the <Link> component» and showed nothing.
   * A test that supplies context the application does not is a test of a different component.
   */
  const view = () => render(<UpgradeRequiredDialog />)

  it('shows nothing at all until something is refused', () => {
    view()
    expect(screen.queryByTestId('upgrade-required')).not.toBeInTheDocument()
  })

  /** The numbers are the point: «3 / 3» answers «would upgrading help?» and «not allowed» does not. */
  it('names the axis and shows the usage against the cap', () => {
    show()
    view()

    expect(screen.getByTestId('upgrade-required')).toBeInTheDocument()
    expect(screen.getByText('لقد بلغت الحد الأقصى من المشاريع (3 من 3).')).toBeInTheDocument()
    // Scoped to the usage row: «المشاريع» also appears inside the server's sentence above it.
    const usage = screen.getByText('3 / 3')
    expect(usage).toBeInTheDocument()
    expect(usage.parentElement?.textContent).toContain('المشاريع')
  })

  /** Nobody blocked mid-task should have to wonder whether their work survived. */
  it('says that nothing was deleted', () => {
    show()
    view()

    expect(screen.getByText(/لم يُحذف شيء/)).toBeInTheDocument()
  })

  it('offers the upgrade path the server named', () => {
    show()
    view()

    expect(screen.getByTestId('upgrade-link')).toHaveAttribute('href', '/app/subscriptions')
  })

  /** A section refusal has no cap to report, and «0 / 0» would be a lie about the reason. */
  it('omits the usage row for an entitlement refusal', () => {
    show({ reason: 'entitlement', subject: 'clients', used: null, limit: null })
    view()

    expect(screen.getByTestId('upgrade-required')).toBeInTheDocument()
    expect(screen.queryByText(/\d+ \/ \d+/)).not.toBeInTheDocument()
  })
})
