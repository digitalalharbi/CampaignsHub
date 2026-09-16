import { beforeAll, describe, expect, it } from 'vitest'
import { PrintDocument } from './PrintDocument'
import { renderWithProviders } from '@/test/utils'

/*
 * CLIENT-DIAGNOSTIC-SEPARATION-001 — the PDF a client keeps says a missing picture in their words.
 *
 * The document layout printed the server's operator note, or its own map of platform-link states
 * («Preview link carries a credential», «Platform does not expose the file»), in the ad table's
 * preview cell. Every other client surface now says «not shown on this link», «preview unavailable
 * for now» or «no preview available»; the file is the copy that is forwarded, so it says the same.
 */
beforeAll(() => {
  if (!(document as Document & { fonts?: unknown }).fonts) {
    Object.defineProperty(document, 'fonts', { value: { ready: Promise.resolve() }, configurable: true })
  }
})

const PIPELINE = /fetch|sync|credential|expose|inferred|platform link|platform sent/i

const ad = (name: string, state: string) => ({
  name, provider: 'snapchat', spend: 100, conversions: 3,
  preview: {
    state, kind: 'image', image_url: null, thumbnail_url: null, video_url: null, expires_at: null, cards: null,
    note_en: 'This row is inferred from the ad’s performance and the ad itself was never fetched from the platform.',
  },
})

describe('the document PDF’s ad table', () => {
  it('states a missing picture in the client’s words, never the pipeline’s', () => {
    const data = {
      period: { from: '2026-08-01', to: '2026-08-31' }, platforms: [], objective: 'Sales',
      ads: [ad('A', 'never_fetched'), ad('B', 'withheld'), ad('C', 'expired'), ad('D', 'unavailable')],
    } as never

    const { container } = renderWithProviders(<PrintDocument data={data} reportName="August" currency="SAR" />, { locale: 'en' })

    const cells = [...container.querySelectorAll('.doc-ad-absent')].map((e) => e.textContent ?? '')
    expect(cells, 'the ad table drew no absence cells — the guard is not reading the table').toHaveLength(4)
    for (const text of cells) expect(text).not.toMatch(PIPELINE)
    expect(cells).toEqual([
      'No preview is available for this content.',
      'This content’s preview is not shown on this link.',
      'This content’s preview is not available right now.',
      'No preview is available for this content.',
    ])
  })
})
