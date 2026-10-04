import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ReportIdentity } from './ReportIdentity'
import { headerIdentity, type SharedBranding } from './sharedBranding'

/**
 * REPORT-IDENTITY-001 — who prepared this report, and who it is for.
 *
 * Both marks were already resolved and already drawn. What no surface said is WHICH IS WHICH: two
 * logos with a name under each is a layout, not an answer, and on a report that goes to a client
 * those are the two facts the page establishes before any figure.
 *
 * The isolation these rest on is the server's and is tested there — a link serves only its own
 * tenant's bytes. What is testable here is that the two identities stay SEPARATE on the way to the
 * page, and that neither can disappear.
 */
function branding(over: Partial<SharedBranding> = {}): SharedBranding {
  return {
    name: 'عميل',
    logo_url: '/client.png',
    logo_source: 'client',
    by: 'الشركة',
    agency: { name: 'الشركة', logo_url: '/agency.png' },
    client: { name: 'عميل', logo_url: '/client.png' },
    ...over,
  } as SharedBranding
}

describe('the report identity', () => {
  it('names each side by its role rather than leaving two logos to be told apart', () => {
    render(<ReportIdentity identity={headerIdentity(branding(), 'ar')} ar />)

    expect(screen.getByTestId('report-identity-preparer')).toHaveTextContent('من إعداد')
    expect(screen.getByTestId('report-identity-preparer-name')).toHaveTextContent('الشركة')
    expect(screen.getByTestId('report-identity-subject')).toHaveTextContent('مقدم إلى')
    expect(screen.getByTestId('report-identity-subject-name')).toHaveTextContent('عميل')
  })

  /** **The two marks are different marks.** The preparer's is never shown as the subject's. */
  it('draws the company mark and the client mark in their own places', () => {
    render(<ReportIdentity identity={headerIdentity(branding(), 'ar')} ar />)

    expect(screen.getByTestId('report-identity-preparer-logo')).toHaveAttribute('src', '/agency.png')
    expect(screen.getByTestId('report-identity-subject-logo')).toHaveAttribute('src', '/client.png')
  })

  /**
   * **A missing mark is a name, never a gap.**
   *
   * A client who has uploaded nothing is still named on the report that is about them — and the
   * report is still issued, because an identity block is not a gate.
   */
  it('falls back to the name when a side has no mark', () => {
    const identity = headerIdentity(branding({ client: { name: 'عميل', logo_url: null } }), 'ar')

    render(<ReportIdentity identity={identity} ar />)

    expect(screen.getByTestId('report-identity-subject-name')).toHaveTextContent('عميل')
    expect(screen.queryByTestId('report-identity-subject-logo')).toBeNull()
    // The preparer is untouched by the client's missing mark.
    expect(screen.getByTestId('report-identity-preparer-logo')).toHaveAttribute('src', '/agency.png')
  })

  /** And with neither mark, both names still stand. */
  it('still says who prepared it and who it is for with no marks at all', () => {
    const identity = headerIdentity(branding({
      logo_url: null,
      agency: { name: 'الشركة', logo_url: null },
      client: { name: 'عميل', logo_url: null },
    }), 'ar')

    render(<ReportIdentity identity={identity} ar />)

    expect(screen.getByTestId('report-identity-preparer-name')).toHaveTextContent('الشركة')
    expect(screen.getByTestId('report-identity-subject-name')).toHaveTextContent('عميل')
    expect(screen.queryByTestId('report-identity-preparer-logo')).toBeNull()
    expect(screen.queryByTestId('report-identity-subject-logo')).toBeNull()
  })

  /**
   * A report the company made for itself has one identity, and it is the PREPARER.
   *
   * `headerIdentity` already refuses «الشركة، من إعداد الشركة»; drawing a «مقدم إلى» block for it
   * would invent a client that does not exist.
   */
  it('draws no client block when the report is the company its own', () => {
    const identity = headerIdentity(branding({ by: null, client: null, name: 'الشركة', logo_url: '/agency.png' }), 'ar')

    render(<ReportIdentity identity={identity} ar />)

    expect(screen.getByTestId('report-identity-preparer-name')).toHaveTextContent('الشركة')
    expect(screen.queryByTestId('report-identity-subject')).toBeNull()
  })

  /** **Contain, never cover.** A wide wordmark and a tall crest both arrive whole. */
  it('never crops a mark', () => {
    render(<ReportIdentity identity={headerIdentity(branding(), 'ar')} ar />)

    for (const id of ['report-identity-preparer-logo', 'report-identity-subject-logo']) {
      const img = screen.getByTestId(id)

      expect(img.className).toContain('object-contain')
      expect(img.className).not.toContain('object-cover')
      // Height fixed, width free — the shape the mark itself decides.
      expect(img.className).toMatch(/\bh-\d/)
      expect(img.className).toContain('w-auto')
    }
  })

  it('says the same thing in English', () => {
    render(<ReportIdentity identity={headerIdentity(branding(), 'en')} ar={false} />)

    expect(screen.getByTestId('report-identity-preparer')).toHaveTextContent('Prepared by')
    expect(screen.getByTestId('report-identity-subject')).toHaveTextContent('Prepared for')
  })
})
