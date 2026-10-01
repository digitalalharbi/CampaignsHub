import { describe, expect, it, vi } from 'vitest'
import type { QueryClient } from '@tanstack/react-query'
import { refreshCampaignList } from './refreshCampaignList'

/**
 * TWICE is the fix — CAMPAIGN-LIST-REFRESH-001.
 *
 * Invalidating a query that is already fetching does not start a second request; it adopts the one
 * in flight, which was composed before the new campaign existed. The second invalidation runs once
 * that has settled, so it always starts a request that is composed after the write. A test that
 * only asserted «it was invalidated» would pass against the bug.
 */
describe('refreshing the campaign list after a write', () => {
  function spy() {
    const client = {
      invalidateQueries: vi.fn(async () => {}),
      cancelQueries: vi.fn(async () => {}),
    }

    return { client, as: client as unknown as QueryClient }
  }

  it('invalidates twice, so one fetch is guaranteed to start after the write', async () => {
    const { client, as } = spy()

    await refreshCampaignList(as, 'p1')

    expect(client.invalidateQueries).toHaveBeenCalledTimes(2)
  })

  it('names the project’s own list, so another project’s rows are left alone', async () => {
    const { client, as } = spy()

    await refreshCampaignList(as, 'p1')

    expect(client.invalidateQueries).toHaveBeenCalledWith({ queryKey: ['project', 'p1', 'campaigns'] })
  })

  /**
   * Cancelling was tried and measured breaking the next campaign created in the same session — its
   * request was never sent and its dialog returned an error on an untouched field. The absence is
   * asserted so it is not reintroduced as an obvious-looking improvement.
   */
  it('does not cancel anything', async () => {
    const { client, as } = spy()

    await refreshCampaignList(as, 'p1')

    expect(client.cancelQueries).not.toHaveBeenCalled()
  })
})
