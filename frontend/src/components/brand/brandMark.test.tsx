import { describe, expect, it } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { BrandMark } from './BrandMark'
import { ReportIdentity } from '@/features/reports/ReportIdentity'

/**
 * REPORT-IDENTITY-PROPORTION-001 — a mark gets a plate, and two marks share it.
 *
 * Every surface sized its marks by HEIGHT with the width left to the artwork. Measured on the shared
 * report: a 120×600 client crest rendered 6×28 beside a 600×120 company wordmark at 72×16 — a
 * coloured sliver and a wordmark, at two different heights, on one line. The owner's reading of it
 * («الابعاد لليمين غير مناسب») is the plain one.
 *
 * These hold the three properties the plate exists for: a shared band, a floor under the width, and
 * no reserved hole when there is nothing to put in it.
 */
describe('a brand mark is drawn on a plate', () => {
  it('fixes the height and bounds the width at both ends', () => {
    render(<BrandMark src="/l/a" testid="mark" />)

    const plate = screen.getByTestId('mark-plate')

    expect(plate.className).toContain('h-8')
    expect(plate.className).toMatch(/min-w-\[\d+px\]/)
    expect(plate.className).toMatch(/max-w-\[\d+px\]/)
  })

  /** Contain, never cover: a mark that is cropped is a mark the brand did not approve. */
  it('contains the artwork rather than cropping or stretching it', () => {
    render(<BrandMark src="/l/a" testid="mark" />)

    const img = screen.getByTestId('mark')

    expect(img.className).toContain('object-contain')
    expect(img.className).toContain('max-h-full')
    expect(img.className).toContain('max-w-full')
    expect(img.className).not.toContain('object-cover')
  })

  /**
   * A broken mark takes its plate with it.
   *
   * Hiding only the image would leave the reserved rectangle behind — a hole beside the name, which
   * is worse than the name alone and is exactly what the fallback rule exists to prevent. A row can
   * outlive its file, so this is a real state, not a defensive one.
   */
  it('leaves nothing behind when the image fails to load', () => {
    render(<BrandMark src="/l/gone" testid="mark" />)

    fireEvent.error(screen.getByTestId('mark'))

    expect(screen.queryByTestId('mark')).toBeNull()
    expect(screen.queryByTestId('mark-plate')).toBeNull()
  })

  /**
   * The two identities on a report share one band.
   *
   * This is the parallelism the owner asked for: «مقدم إلى» and «من إعداد» are the same kind of
   * fact, and two marks at two different heights say they are not.
   */
  it('gives the subject and the preparer the same plate', () => {
    render(
      <ReportIdentity
        ar
        identity={{ name: 'Acme', logoUrl: '/l/client', by: 'Demo Agency', byLogoUrl: '/l/agency' }}
      />,
    )

    const subject = screen.getByTestId('report-identity-subject-logo-plate')
    const preparer = screen.getByTestId('report-identity-preparer-logo-plate')

    expect(subject.className).toBe(preparer.className)
  })

  /** A client with no mark is still named — the mark is what is optional, never the identity. */
  it('names an identity that has no mark at all', () => {
    render(
      <ReportIdentity
        ar
        identity={{ name: 'Nova', logoUrl: null, by: 'Demo Agency', byLogoUrl: '/l/agency' }}
      />,
    )

    expect(screen.getByTestId('report-identity-subject-name').textContent).toBe('Nova')
    expect(screen.queryByTestId('report-identity-subject-logo-plate')).toBeNull()
  })
})
