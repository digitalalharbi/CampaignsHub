import { describe, expect, it } from 'vitest'
import { PORTAL_NAV, PORTAL_SECTIONS, portalPageOf, portalSectionOf } from './portalNav'
import { PORTAL_RESERVED_SEGMENTS } from '@/lib/api/client'

/**
 * CLIENT-PORTAL-THREE-SECTIONS-001 — three sections and «المزيد» are the portal's structure, and NOTHING a
 * client used before was dropped: every page sits in exactly one section, at its old path.
 */
describe('the portal sections', () => {
  it('are الأداء، بانتظار اعتمادك، طلباتي and a compact المزيد', () => {
    expect(PORTAL_SECTIONS.map((s) => s.ar)).toEqual(['الأداء', 'بانتظار اعتمادك', 'طلباتي', 'المزيد'])
  })

  it('hold every portal page exactly once, including every page that existed before', () => {
    const before = ['', '/requests', '/campaigns', '/reports', '/quotes', '/invoices', '/messages', '/files', '/profile']
    const placed = PORTAL_SECTIONS.flatMap((s) => s.pages)

    expect(new Set(placed).size).toBe(placed.length)
    for (const page of [...before, '/approvals']) expect(placed).toContain(page)
    expect(PORTAL_NAV.map((i) => i.to).sort()).toEqual([...placed].sort())
  })

  it('nest pages where the Owner asked: performance, approvals, requests', () => {
    const of = (k: string) => PORTAL_SECTIONS.find((s) => s.key === k)?.pages
    expect(of('performance')).toEqual(['', '/campaigns', '/reports'])
    expect(of('approvals')).toEqual(['/approvals', '/quotes'])
    expect(of('requests')).toEqual(['/requests', '/messages', '/files'])
  })

  it('resolve the section of any path, in a space or in the merged view, detail pages included', () => {
    expect(portalSectionOf('/portal/razzah')).toBe('performance')
    expect(portalSectionOf('/portal/razzah/reports')).toBe('performance')
    expect(portalSectionOf('/portal/razzah/quotes/12')).toBe('approvals')
    expect(portalSectionOf('/portal/approvals')).toBe('approvals')
    expect(portalSectionOf('/portal/razzah/requests/new')).toBe('requests')
    expect(portalSectionOf('/portal/messages/abc')).toBe('requests')
    expect(portalSectionOf('/portal/razzah/invoices/3')).toBe('more')
    expect(portalPageOf('/portal/clients/razzah/files')).toBe('/files')
  })

  it('reserve the new page’s path so it never reads as a client space', () => {
    expect(PORTAL_RESERVED_SEGMENTS.has('approvals')).toBe(true)
  })
})
