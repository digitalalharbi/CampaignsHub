import axios from 'axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { api } from './client'

/** CSRF-SELF-HEAL-001 — a refused token gets one fresh one, once; a second refusal reaches the page. */
describe('a 419 response', () => {
  const adapter = api.defaults.adapter

  afterEach(() => {
    api.defaults.adapter = adapter
    vi.restoreAllMocks()
  })

  const answering = (...statuses: number[]) => {
    const sent = vi.fn()
    api.defaults.adapter = async (config) => {
      sent()
      const status = statuses.shift() ?? 200
      const response = { data: {}, status, statusText: '', headers: {}, config }
      if (status >= 400) throw new axios.AxiosError('refused', undefined, config, null, response)

      return response
    }

    return sent
  }

  it('primes a fresh token and sends the request once more', async () => {
    const prime = vi.spyOn(axios, 'get').mockResolvedValue({})
    Object.defineProperty(document, 'cookie', { writable: true, configurable: true, value: 'XSRF-TOKEN=abc' })
    const sent = answering(419, 200)

    await expect(api.post('/auth/login', { email: 'a@b.c' })).resolves.toMatchObject({ status: 200 })

    expect(prime).toHaveBeenCalledWith('/sanctum/csrf-cookie', { withCredentials: true })
    expect(sent).toHaveBeenCalledTimes(2)
  })

  it('reaches the page on a second refusal, with no third request', async () => {
    vi.spyOn(axios, 'get').mockResolvedValue({})
    Object.defineProperty(document, 'cookie', { writable: true, configurable: true, value: 'XSRF-TOKEN=abc' })
    const sent = answering(419, 419, 200)

    await expect(api.post('/auth/login', {})).rejects.toMatchObject({ response: { status: 419 } })

    expect(sent).toHaveBeenCalledTimes(2)
  })

  it('leaves every other failure alone', async () => {
    const prime = vi.spyOn(axios, 'get').mockResolvedValue({})
    const sent = answering(422)

    await expect(api.post('/auth/login', {})).rejects.toMatchObject({ response: { status: 422 } })

    expect(sent).toHaveBeenCalledTimes(1)
    expect(prime).not.toHaveBeenCalled()
  })
})
