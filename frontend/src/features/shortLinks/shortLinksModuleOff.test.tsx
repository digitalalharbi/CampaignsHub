import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (orig) => ({ ...(await orig<typeof import('./api')>()), listShortLinksWithMeta: vi.fn() }))

import { ShortLinksPage } from './ShortLinksPage'
import { listShortLinksWithMeta } from './api'

/** CAP-REGISTRY-001 — a refusal that names the module is explained, not drawn as an empty library. */
describe('ShortLinksPage when its module is switched off', () => {
  beforeEach(() => vi.clearAllMocks())

  it('says the module is off, that printed links keep working, and where to switch it on', async () => {
    vi.mocked(listShortLinksWithMeta).mockRejectedValue({ response: { status: 403, data: { errors: { module: 'smart_links', reason: 'tenant_off' } } } })
    renderWithProviders(<ShortLinksPage />, { locale: 'en' })

    // A generous wait: the page is a lazy chunk, and under the full suite's load its first render has taken longer than findBy's 1 s default (it failed once there and passed alone).
    expect(await screen.findByText('Smart links are switched off for this organisation', undefined, { timeout: 8000 })).toBeTruthy()
    expect(screen.getByText(/Links already shared keep working/)).toBeTruthy()
    expect(screen.getByText(/Settings → Modules/)).toBeTruthy()
  })

  it('says it in Arabic', async () => {
    vi.mocked(listShortLinksWithMeta).mockRejectedValue({ response: { status: 403, data: { errors: { module: 'smart_links', reason: 'tenant_off' } } } })
    renderWithProviders(<ShortLinksPage />, { locale: 'ar' })

    expect(await screen.findByText('الروابط الذكية متوقفة لهذه المنظمة', undefined, { timeout: 8000 })).toBeTruthy()
  })
})
