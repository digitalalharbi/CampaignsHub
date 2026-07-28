import { afterEach, describe, expect, it, vi } from 'vitest'
import { formatDateTime, listThreads, markThreadRead, postTeamReply } from './api'
import { api } from '@/lib/api/client'

function ok<T>(data: T) {
  return { data: { success: true, message: '', data, meta: {}, errors: null } }
}

describe('messaging/api mapping', () => {
  afterEach(() => vi.restoreAllMocks())

  it('unwraps the envelope for listThreads and passes the status filter', async () => {
    const spy = vi.spyOn(api, 'get').mockResolvedValue(ok([{ id: 't1', subject: 'Hi' }]) as never)
    const rows = await listThreads('open')
    expect(rows).toHaveLength(1)
    expect(rows[0].subject).toBe('Hi')
    expect(spy).toHaveBeenCalledWith('/messaging/threads', { params: { status: 'open' } })
  })

  it('omits the status param when none is given', async () => {
    const spy = vi.spyOn(api, 'get').mockResolvedValue(ok([]) as never)
    await listThreads()
    expect(spy).toHaveBeenCalledWith('/messaging/threads', { params: {} })
  })

  it('posts a team reply to the thread messages endpoint', async () => {
    const spy = vi.spyOn(api, 'post').mockResolvedValue(ok({ id: 'm1', body: 'Thanks' }) as never)
    const msg = await postTeamReply('t1', 'Thanks')
    expect(msg.body).toBe('Thanks')
    expect(spy).toHaveBeenCalledWith('/messaging/threads/t1/messages', { author_type: 'team', body: 'Thanks' })
  })

  it('marks a thread read from the team side by default', async () => {
    const spy = vi.spyOn(api, 'post').mockResolvedValue(ok({ cleared: 2, unread: 0 }) as never)
    const res = await markThreadRead('t1')
    expect(res).toEqual({ cleared: 2, unread: 0 })
    expect(spy).toHaveBeenCalledWith('/messaging/threads/t1/read', { side: 'team' })
  })

  it('formats a datetime with Latin digits and null as an em dash', () => {
    expect(formatDateTime(null)).toBe('—')
    expect(formatDateTime('not-a-date')).toBe('—')
    expect(formatDateTime('2026-04-01T09:30:00Z')).toMatch(/^2026-04-01 \d{2}:\d{2}$/)
  })
})
