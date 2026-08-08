import { describe, expect, it } from 'vitest'
import { brand } from './brand'
// Vite's raw import rather than `node:fs`: this suite's tsconfig has no Node types, and adding them
// to type one `readFileSync` would widen the app's type surface to buy nothing.
import html from '../../index.html?raw'

/**
 * BRAND-001 — the product calls itself one thing, everywhere.
 *
 * The official identity is:
 *
 *   CampaignsHub — كل حملاتك الإعلانية المدفوعة في مكان واحد
 *
 * Before this it lived in eight code comments explaining decisions and one marketing heading, while
 * the value the product actually rendered was a different sentence in each language. So the title
 * tag, the Open Graph card and the sign-in panel each described the product as something it does not
 * call itself — and nothing failed, because nothing checked.
 *
 * These assertions are what make «one name» a property of the build rather than a habit.
 */

describe('the brand identity', () => {
  it('carries the official tagline in both languages', () => {
    expect(brand.taglineAr).toBe('كل حملاتك الإعلانية المدفوعة في مكان واحد')
    expect(brand.tagline).toBe('All your paid campaigns in one place')
  })

  it('describes what the product is without claiming what it achieves', () => {
    // A description that promises performance is one that has to be defended.
    for (const text of [brand.description, brand.descriptionAr]) {
      expect(text.length).toBeGreaterThan(30)
      expect(text).not.toMatch(/best|fastest|guarantee|#1|الأفضل|الأسرع|نضمن/i)
    }
  })

  it('puts the same sentence in the title, the description and the social card', () => {
    expect(html).toContain('<title>CampaignsHub — All your paid campaigns in one place</title>')
    expect(html).toContain('og:title" content="CampaignsHub — All your paid campaigns in one place"')
    expect(html).toContain(`og:description" content="${brand.description}"`)
  })

  /** Structured data a search engine will actually parse, naming both languages. */
  it('ships valid structured data that names the product and its publisher', () => {
    const block = html.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/)
    expect(block).not.toBeNull()

    const data = JSON.parse(block![1])
    expect(data['@type']).toBe('SoftwareApplication')
    expect(data.name).toBe('CampaignsHub')
    expect(data.alternateName).toBe(brand.taglineAr)
    expect(data.inLanguage).toEqual(['ar', 'en'])
    expect(data.publisher.email).toBe(brand.supportEmail)
  })

  /**
   * The name this platform used to have.
   *
   * It survived in a health endpoint — `mediabuying-api` — which is a public surface: a monitor, a
   * status page and an uptime checker all quote it back.
   */
  it('never ships the old name', () => {
    expect(html).not.toMatch(/mediabuying|media[- ]buying/i)
    expect(brand.name).toBe('CampaignsHub')
    expect(brand.supportEmail).toBe('info@campaignshub.io')
  })
})
