import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'

import { renderWithProviders } from '@/test/utils'
import { ReportAdsSection, type ReportAd } from '../ReportAdsSection'
import { ContentTile } from './LiveContent'

/*
 * The client's own surfaces — the live content tile and the report's ad card — print a missing
 * picture in the client's words. They printed «Never fetched / لم يُجلب» on the tile and carried our
 * «inferred … never fetched from the platform» sentence in its title and screen-reader text.
 */
const PIPELINE = /fetch|sync|جُ?لب|مزامن|مُستنتج|inferred/i

/** Everything a reader can be told: visible text, screen-reader text and hover titles — not machine attributes. */
const saidTo = (root: HTMLElement) =>
  [root.textContent ?? '', ...[...root.querySelectorAll('[title]')].map((e) => e.getAttribute('title') ?? '')].join(' ')

const content = {
  name: 'Hero Video', provider: 'snapchat', content_key: 'k1', spend: 100, impressions: 1000, clicks: 30, conversions: 3, ctr: 0.03,
  preview: {
    state: 'never_fetched', kind: 'image', image_url: null, video_url: null, thumbnail_url: null, expires_at: null, cards: null,
    note_ar: 'هذا الصف مُستنتج من أداء الإعلان، ولم يُجلب الإعلان نفسه من المنصة — فلا يوجد أصل لعرضه.',
    note_en: 'This row is inferred from the ad’s performance and the ad itself was never fetched from the platform.',
  },
} as unknown as ReportAd

describe('a missing picture on a client surface', () => {
  for (const locale of ['ar', 'en'] as const) {
    it(`is said in the client's words on the live content tile (${locale})`, () => {
      const { container } = renderWithProviders(<ContentTile content={content} locale={locale} currency="SAR" />, { locale })

      expect(saidTo(container)).not.toMatch(PIPELINE)
      expect(screen.getByText(locale === 'ar' ? 'لا تتوفر معاينة' : 'No preview available')).toBeInTheDocument()
    })

    it(`is said in the client's words on the report's ad card (${locale})`, () => {
      const { container } = renderWithProviders(<ReportAdsSection ads={[content]} locale={locale} currency="SAR" />, { locale })

      expect(saidTo(container)).not.toMatch(PIPELINE)
    })
  }
})
