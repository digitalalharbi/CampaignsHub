import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { AnalyticsBoardRedirect } from './legacyRedirects'
import { agencyNavLeafPaths } from '@/layouts/agencyNav'
import { appNavLeafPaths } from '@/layouts/appNav'

/**
 * ANALYTICS-ONE-BOARD-001 — «لوحة التحليلات» is gone from the rail and its old address lands on «التحليلات», carrying the
 * query (project, tab, period) and the hash, so a bookmark or an emailed button still opens the same project.
 */
function Where() {
  const l = useLocation()

  return <p data-testid="where">{`${l.pathname}${l.search}${l.hash}`}</p>
}

function at(route: string, portal: 'app' | 'agency') {
  render(
    <MemoryRouter initialEntries={[route]}>
      <Routes>
        <Route path={`/${portal}/dashboard`} element={<AnalyticsBoardRedirect portal={portal} />} />
        <Route path={`/${portal}/analytics`} element={<Where />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('the old analytics board address', () => {
  it('redirects the advertiser portal and keeps ?project, ?tab and the hash', () => {
    at('/app/dashboard?project=p1&tab=budget#x', 'app')
    expect(screen.getByTestId('where').textContent).toBe('/app/analytics?project=p1&tab=budget#x')
  })

  it('redirects the agency portal the same way', () => {
    at('/agency/dashboard?project=p9', 'agency')
    expect(screen.getByTestId('where').textContent).toBe('/agency/analytics?project=p9')
  })

  it('is not offered in either rail any more, while «التحليلات» is', () => {
    expect(appNavLeafPaths).not.toContain('/app/dashboard')
    expect(agencyNavLeafPaths).not.toContain('/agency/dashboard')
    expect(appNavLeafPaths).toContain('/app/analytics')
    expect(agencyNavLeafPaths).toContain('/agency/analytics')
  })
})
