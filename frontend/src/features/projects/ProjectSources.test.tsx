import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { PlatformIntegrationsPanel } from './PlatformIntegrationsPanel'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('@/lib/api/client', async (orig) => ({
  ...(await orig<Record<string, unknown>>()),
  getData: vi.fn(),
  postData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §12 — a project screen answers the project reader's questions.
 *
 * The panel used to open with «لديها بيانات اعتماد: 0» — a count of how many platforms THIS INSTALL
 * holds keys for. It is the platform operator's number, no action on this page can change it, and on
 * a customer's own project it reads as «none of your platforms work». Under each card it then
 * described the product's build state to them: the structure is complete, OAuth included, and works
 * once the keys are added.
 *
 * Both are true. Neither is an answer to «why is there nothing from Meta on my project».
 */
const platform = (over: Record<string, unknown> = {}) => ({
  key: 'meta',
  label_ar: 'ميتا',
  label_en: 'Meta',
  connector_label: 'Meta Marketing API',
  status: 'awaiting_credentials',
  has_credentials: false,
  capabilities: [{ key: 'metrics', ar: 'المقاييس', en: 'Metrics', enabled: false }],
  connections: [],
  accounts: [],
  discovered_campaigns: 0,
  linked_campaigns: 0,
  last_sync: null,
  ...over,
})

describe('the platforms panel on a project', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    signInWith(['integrations.view'])
    vi.mocked(getData).mockResolvedValue({
      platforms: [platform()],
      summary: { total: 6, with_credentials: 0, with_accounts: 0, discovered_campaigns: 0 },
    } as never)
  })
  afterEach(() => signOut())

  it('counts what this project has, not what the install has keys for', async () => {
    renderWithProviders(<PlatformIntegrationsPanel projectId="p1" />, { locale: 'en' })

    expect(await screen.findByText('Platforms feeding this project')).toBeInTheDocument()
    expect(screen.getAllByText('Accounts linked').length).toBeGreaterThan(0)
    expect(screen.queryByText(/credentials/i)).not.toBeInTheDocument()
  })

  it('says why a platform is silent in terms of this project, not of our build', async () => {
    renderWithProviders(<PlatformIntegrationsPanel projectId="p1" />, { locale: 'en' })

    expect(await screen.findByText(/is not feeding this project yet/)).toBeInTheDocument()
    expect(screen.queryByText(/OAuth/)).not.toBeInTheDocument()
  })

  /** An English reader saw an Arabic panel — every string in it was hard-coded. */
  it('reads in the language the reader chose', async () => {
    vi.mocked(getData).mockResolvedValue({
      platforms: [platform({ accounts: [{
        id: 'a1', provider: 'meta', account_type: 'ad_account', name: 'Riyadh', external_id: 'act-1',
        parent_name: null, parent_external_id: null, currency: 'SAR', timezone: null, status: 'active',
        health: 'healthy', last_synced_at: null,
      }] })],
      summary: { total: 6, with_credentials: 1, with_accounts: 1, discovered_campaigns: 0 },
    } as never)

    renderWithProviders(<PlatformIntegrationsPanel projectId="p1" />, { locale: 'en' })

    expect(await screen.findByText('Feeding this project')).toBeInTheDocument()
    expect(screen.getByText('Healthy')).toBeInTheDocument()
  })
})
