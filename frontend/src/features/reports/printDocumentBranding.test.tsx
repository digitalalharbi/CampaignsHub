import { beforeAll, describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { PrintDocument } from './PrintDocument'
import { renderWithProviders } from '@/test/utils'

/**
 * BRANDING-RENDER-EVIDENCE-001 — the document PDF carries the brand its payload already resolved.
 *
 * The cover printed the word «CampaignsHub», hard-coded, on every document PDF generated — so an
 * agency's white-labelled client report announced the product across the top of its first page, and
 * the file's own title carried it into the mail client beside the attachment.
 *
 * The payload has carried the resolved identity all along. `PrintReport` was already using it for
 * the PDF metadata and for the slide deck; the document layout was the one surface never given it.
 * `PrintReportBrandingTest` proves the payload — and passed throughout, because a payload carrying a
 * logo and a document rendering one are different claims. That is the row's own warning: code
 * containing `logo_url` is not completion.
 */
/*
 * `document.fonts` does not exist in jsdom, and the component awaits `fonts.ready` before it
 * publishes its print-readiness signals. Stubbed rather than guarded in the component: the wait is
 * real and load-bearing for the printer, and weakening it to satisfy a test would trade a correct
 * PDF for a convenient one.
 */
beforeAll(() => {
  if (!(document as Document & { fonts?: unknown }).fonts) {
    Object.defineProperty(document, 'fonts', { value: { ready: Promise.resolve() }, configurable: true })
  }
})

const data = { period: { from: '2026-08-01', to: '2026-08-31' }, platforms: [], objective: 'Sales' } as never

const render = (identity?: { name: string; logoUrl: string | null; by: string | null }) =>
  renderWithProviders(
    <PrintDocument data={data} reportName="August" currency="SAR" identity={identity} />,
    { locale: 'en' },
  )

describe('the document PDF’s cover', () => {
  it('shows the client’s mark when one is configured', () => {
    render({ name: 'Nakheel', logoUrl: 'https://example.test/logo.png', by: null })

    const logo = screen.getByTestId('print-document-logo')
    expect(logo).toHaveAttribute('src', 'https://example.test/logo.png')
    expect(logo).toHaveAttribute('alt', 'Nakheel')
    expect(screen.queryByTestId('print-document-brand')).not.toBeInTheDocument()
  })

  /** A name, not a broken image: `<img src="">` reads as «the report failed» on a client's PDF. */
  it('shows the name when there is no mark, and never an empty image', () => {
    render({ name: 'Nakheel', logoUrl: null, by: null })

    expect(screen.getByTestId('print-document-brand')).toHaveTextContent('Nakheel')
    expect(screen.queryByTestId('print-document-logo')).not.toBeInTheDocument()
  })

  /** The agency is named secondarily — the same rule the shared link follows. */
  it('names the agency beside the client, never in place of it', () => {
    render({ name: 'Nakheel', logoUrl: null, by: 'Demo Agency' })

    expect(screen.getByTestId('print-document-brand')).toHaveTextContent('Nakheel')
    expect(screen.getByTestId('print-document-by')).toHaveTextContent('Demo Agency')
  })

  /**
   * The product's name is the LAST link of client → agency → CampaignsHub, not a competing default.
   * A payload with no branding at all must still produce a cover, and it must say CampaignsHub.
   */
  it('falls back to the product when no identity was resolved', () => {
    render(undefined)

    expect(screen.getByTestId('print-document-brand')).toHaveTextContent('CampaignsHub')
  })

  it('puts the resolved name in the file’s own title, not the product’s', () => {
    render({ name: 'Nakheel', logoUrl: null, by: null })

    expect(document.title).toContain('Nakheel')
    expect(document.title).not.toContain('CampaignsHub')
  })
})
