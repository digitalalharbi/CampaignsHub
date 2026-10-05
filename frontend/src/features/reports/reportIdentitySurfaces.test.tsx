import { beforeAll, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PrintDocument } from './PrintDocument'
import { CoverSlide } from './InteractiveReport'
import { coverMeta, headerIdentity, type SharedBranding } from './sharedBranding'
import { renderWithProviders } from '@/test/utils'

/**
 * REPORT-IDENTITY-001 — every report surface says WHICH mark is which.
 *
 * Both identities were already resolved and already drawn. What no surface said is which of the two
 * prepared the report and which one it is about — and on a document a client files or forwards,
 * those are the two facts established before any figure.
 *
 * One identity, four renderings. These hold that each rendering labels both roles, so a reader
 * moving between the link, the page and the PDF meets the same hierarchy rather than three layouts.
 */
function branding(): SharedBranding {
  return {
    name: 'العميل',
    logo_url: '/client.png',
    logo_source: 'client',
    by: 'الشركة',
    agency: { name: 'الشركة', logo_url: '/agency.png' },
    client: { name: 'العميل', logo_url: '/client.png' },
  } as SharedBranding
}

/*
 * `PrintDocument` signals readiness after `document.fonts.ready`, which jsdom does not provide. The
 * same stub the other print tests use — this is about the environment, not about the document.
 */
beforeAll(() => {
  if (!('fonts' in document)) {
    Object.defineProperty(document, 'fonts', { value: { ready: Promise.resolve() }, configurable: true })
  }
})

const data = {
  period: { from: '2026-09-01', to: '2026-09-30' },
  currency: 'SAR',
  kpis: [],
  summary: [],
  platforms: [],
} as never

describe('the report surfaces name both roles', () => {
  it('labels the preparer and the subject on the printed cover', () => {
    const identity = headerIdentity(branding(), 'ar')

    render(
      <PrintDocument
        data={data}
        reportName="أداء الحملات"
        currency="SAR"
        clientName={coverMeta(identity).clientName}
        identity={identity}
      />,
    )

    expect(screen.getByTestId('print-document-by')).toHaveTextContent('Prepared by')
    expect(screen.getByTestId('print-document-by')).toHaveTextContent('الشركة')
    expect(screen.getByTestId('print-document-for')).toHaveTextContent('Prepared for')
    expect(screen.getByTestId('print-document-for')).toHaveTextContent('العميل')
  })

  /** The marks are the two separate marks, not one of them twice. */
  it('prints the company mark beside the preparer', () => {
    const identity = headerIdentity(branding(), 'ar')

    render(
      <PrintDocument data={data} reportName="R" currency="SAR" clientName={coverMeta(identity).clientName} identity={identity} />,
    )

    const logo = screen.getByTestId('print-document-agency-logo')

    expect(logo).toHaveAttribute('src', '/agency.png')
    /*
     * REPORT-IDENTITY-PROPORTION-001 — the printed cover has no Tailwind, so the mark wears the
     * plate class and the rule behind it is held by `markSpec.test.ts`, which reads the print
     * stylesheet and requires a fixed height, a width bounded at both ends, and `object-fit:
     * contain`. Asserted as the class here so a mark cannot quietly go back to an unbounded one.
     */
    expect(logo.className).toBe('doc-by-logo')
  })

  it('labels both roles on the interactive cover', () => {
    const identity = headerIdentity(branding(), 'ar')
    const meta = {
      reportName: 'أداء الحملات',
      platforms: [],
      isDemo: false,
      ...coverMeta(identity),
    }

    renderWithProviders(<CoverSlide data={data} meta={meta} />, { locale: 'ar' })

    expect(screen.getByTestId('report-cover-agency')).toHaveTextContent('من إعداد')
    expect(screen.getByTestId('report-cover-agency')).toHaveTextContent('الشركة')
    expect(screen.getByTestId('report-cover-client')).toHaveTextContent('مقدم إلى')
    expect(screen.getByTestId('report-cover-client')).toHaveTextContent('العميل')

    expect(screen.getByTestId('report-cover-agency-logo')).toHaveAttribute('src', '/agency.png')
    expect(screen.getByTestId('report-cover-client-logo')).toHaveAttribute('src', '/client.png')
  })

  /**
   * **A report the company made for itself has one identity, and no «مقدم إلى».**
   *
   * `headerIdentity` already refuses «الشركة، من إعداد الشركة». Labelling the single identity as a
   * subject would invent a client that does not exist.
   */
  it('names no subject when the report is the company its own', () => {
    const identity = headerIdentity({
      name: 'الشركة',
      logo_url: '/agency.png',
      logo_source: 'tenant',
      by: null,
      agency: { name: 'الشركة', logo_url: '/agency.png' },
      client: null,
    } as SharedBranding, 'ar')

    const meta = { reportName: 'R', platforms: [], isDemo: false, ...coverMeta(identity) }

    renderWithProviders(<CoverSlide data={data} meta={meta} />, { locale: 'ar' })

    expect(screen.getByTestId('report-cover-client').textContent).not.toContain('مقدم إلى')
  })
})
