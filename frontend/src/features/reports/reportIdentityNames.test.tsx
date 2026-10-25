import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { useState } from 'react'
import { ReportIdentityCards, type IdentityNames } from './ReportIdentityCards'
import { renderWithProviders } from '@/test/utils'

/**
 * REPORT-IDENTITY-NAMES-001 — «prepared for» and «prepared by» are typed per report.
 *
 * The builder printed the stored client's name under «مقدم إلى» and gave no way to change it. Each name is now a labelled field
 * that starts on the stored name; typing a different one is an override for THIS report, and typing the stored name back (or
 * clearing the field's difference) is no override at all.
 */
vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  projectReportIdentity: () => Promise.resolve({
    name: 'CampaignsHub Advertiser', logo_url: null, logo_source: 'none', by: 'Agency Co',
    agency: { name: 'Agency Co', logo_url: null },
    client: { name: 'CampaignsHub Advertiser', logo_url: null },
    upload: { company: { scope: 'tenant', scope_id: null }, client: { scope: 'client', scope_id: 'c1' } },
  }),
}))

function Harness({ seen, ar = true }: { seen: (n: IdentityNames) => void; ar?: boolean }) {
  const [names, setNames] = useState<IdentityNames>({ for: null, by: null })
  seen(names)
  return <ReportIdentityCards projectId="p1" ar={ar} names={names} onNames={setNames} />
}

describe('the report identity names', () => {
  it('starts on the stored names and labels both fields in Arabic', async () => {
    renderWithProviders(<Harness seen={() => {}} />, { locale: 'ar' })

    const client = await screen.findByTestId('report-identity-client-name-input')
    expect(client).toHaveValue('CampaignsHub Advertiser')
    expect(screen.getByTestId('report-identity-company-name-input')).toHaveValue('Agency Co')
    expect(screen.getByTestId('report-identity-client')).toHaveTextContent('مقدم إلى')
    expect(screen.getByTestId('report-identity-company')).toHaveTextContent('مقدم من')
  })

  it('records an override for either name, independently, in Arabic or English', async () => {
    let latest: IdentityNames = { for: null, by: null }
    renderWithProviders(<Harness ar={false} seen={(n) => { latest = n }} />, { locale: 'en' })

    fireEvent.change(await screen.findByTestId('report-identity-client-name-input'), { target: { value: 'شركة النخيل' } })
    expect(latest).toEqual({ for: 'شركة النخيل', by: null })

    fireEvent.change(screen.getByTestId('report-identity-company-name-input'), { target: { value: 'Hanan Studio' } })
    expect(latest).toEqual({ for: 'شركة النخيل', by: 'Hanan Studio' })
    expect(screen.getByTestId('report-identity-client')).toHaveTextContent('Prepared for')
    expect(screen.getByTestId('report-identity-company')).toHaveTextContent('Prepared by')
  })

  it('typing the stored name back is no override', async () => {
    let latest: IdentityNames = { for: null, by: null }
    renderWithProviders(<Harness seen={(n) => { latest = n }} />, { locale: 'ar' })

    const input = await screen.findByTestId('report-identity-client-name-input')
    fireEvent.change(input, { target: { value: 'Other' } })
    expect(latest.for).toBe('Other')
    fireEvent.change(input, { target: { value: 'CampaignsHub Advertiser' } })
    expect(latest.for).toBeNull()
  })
})
