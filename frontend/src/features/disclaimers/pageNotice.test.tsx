import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'
import { REPORT_NOTE_MORE } from '@/features/reports/reportFooter'
import analyticsPage from '@/features/analytics/AnalyticsPage.tsx?raw'
import campaignsPage from '@/features/campaigns/CampaignsPage.tsx?raw'
import creativesPage from '@/features/content/CreativesPage.tsx?raw'
import reportsPageSource from '@/features/reports/ReportsPage.tsx?raw'
import { ReportsPage } from '@/features/reports/ReportsPage'

vi.mock('@/features/taxonomy/taxonomyApi', () => ({
  useTaxonomyOptions: () => ({ options: [], isPending: false, isError: false, refetch: vi.fn() }),
}))

vi.mock('@/features/reports/api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listReports: vi.fn().mockResolvedValue({ reports: [], summary: { total: 0, completed: 0, processing: 0, failed: 0 } }), listReportSections: vi.fn() }
})

vi.mock('@/features/disclaimers/api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return {
    ...actual,
    useDisclaimer: () => ({
      data: {
        version: 1,
        locale_default: 'ar',
        enabled: {},
        sources: ['system'],
        sections: { short: { ar: 'قصير', en: 'Short note' }, full: { ar: 'كامل', en: 'Full note' } },
      },
    }),
  }
})

/*
 * DISCLAIMER-IN-SYSTEM-001 (Owner, 2026-11-08) — the same note ends every analysis surface: once per
 * page, at the bottom of the page content, from the one disclaimer provider.
 */
describe('performance note on the app’s analysis surfaces', () => {
  it.each([
    ['Analytics + Dashboard', analyticsPage],
    ['Campaigns', campaignsPage],
    ['Content', creativesPage],
    ['Reports', reportsPageSource],
  ])('%s renders the live note exactly once, from PerformanceNotice', (_name, source) => {
    expect(source.match(/<LivePerformanceNotice\b/g)).toHaveLength(1)
    expect(source).toContain("from '@/features/disclaimers/PerformanceNotice'")
    expect(source).not.toMatch(/text-\[\d+px\]\s*"[^>]*LivePerformanceNotice/)
  })

  describe('Reports page', () => {
    beforeEach(() => {
      useProject.setState({ currentProjectId: 'p1' })
      signInWith(['reports.view', 'reports.create'])
    })
    afterEach(() => signOut())

    it('shows the note once', async () => {
      renderWithProviders(<ReportsPage />, { locale: 'en' })
      expect(await screen.findAllByTestId('performance-notice-short')).toHaveLength(1)
    })
  })
})

describe('approved disclaimer copy (system default + report footer)', () => {
  it('names no source or vendor and lists the Owner’s factors, in ar and en', () => {
    for (const locale of ['ar', 'en'] as const) {
      const text = REPORT_NOTE_MORE[locale]
      expect(text).not.toMatch(/GA4|Google Analytics|جوجل أناليتكس|قوقل أناليتكس/i)
    }
    const ar = REPORT_NOTE_MORE.ar
    for (const w of ['العروض والخصومات', 'جودة المحتوى', 'سلوك العملاء', 'مستوى المنافسة', 'تكاليف المنصات', 'طبيعة المنتج والتسعير', 'المؤثرون', 'المتجر أو الموقع']) expect(ar).toContain(w)
    // ONE compact paragraph (~55 words), not a block.
    expect(ar.split(/\s+/).length).toBeLessThanOrEqual(65)
    const en = REPORT_NOTE_MORE.en
    for (const w of ['offers and discounts', 'content quality', 'customer behaviour', 'level of competition', 'platform costs', 'nature of the product and its pricing', 'influencers', 'store or website']) expect(en).toContain(w)
  })
})
