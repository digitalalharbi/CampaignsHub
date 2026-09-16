import { useCallback, useEffect, useRef, useState } from 'react'
import { fetchLiveShared, type LivePayload } from '../api'

/**
 * One live request, and the states a reader must never see collapsed into one.
 *
 * - `pending`  no answer yet and nothing to show — a skeleton.
 * - `failed`   refused or broken, and nothing was ever shown — a truthful refusal.
 * - `ready`    figures on screen. A refresh keeps them there, dimmed, rather than blanking them; and a
 *              refresh that FAILS keeps them too and says so, because figures already shown are still
 *              true of the moment they were computed.
 *
 * Found three times in this repository in one day: a pending request and a failed one rendered the
 * same thing. Here they are different values of one field, so a view cannot confuse them without
 * writing the confusion down.
 *
 * A request counter keeps a slow answer from overwriting a fast one: «7 days» then «90 days» must never
 * leave the 7-day figures under a 90-day label.
 */
export type LiveLoad =
  | { state: 'pending' }
  | { state: 'failed'; message: string; status: number }
  | { state: 'ready'; payload: LivePayload; refreshing: boolean; refreshError: string | null; computedAt: Date }

export const isoDaysAgo = (days: number, now = new Date()) => {
  const d = new Date(now)
  d.setDate(d.getDate() - days + 1)
  return d.toISOString().slice(0, 10)
}

export function useLivePayload({
  token,
  secret,
  days,
  providers,
  enabled = true,
  failedMessage,
  refreshEveryMs,
}: {
  token: string
  secret?: string
  days: number
  providers: string[]
  enabled?: boolean
  failedMessage: string
  /**
   * Recompute on a clock while the page is VISIBLE — «live» for a client who leaves the link open.
   * A hidden tab asks nothing (a rationed public endpoint, and nobody is looking), and becoming
   * visible again after a long absence recomputes at once rather than showing hours-old figures.
   */
  refreshEveryMs?: number
}): { load: LiveLoad; reload: () => void } {
  const [load, setLoad] = useState<LiveLoad>({ state: 'pending' })
  const latest = useRef(0)
  const key = providers.join(',')

  const run = useCallback(async () => {
    const ticket = ++latest.current
    setLoad((prev) => (prev.state === 'ready' ? { ...prev, refreshing: true, refreshError: null } : { state: 'pending' }))

    let status = 0
    let body: { data?: unknown; message?: string } = {}
    try {
      const res = await fetchLiveShared(token, {
        from: isoDaysAgo(days),
        to: new Date().toISOString().slice(0, 10),
        providers: key === '' ? [] : key.split(','),
        campaigns: [],
        password: secret,
      })
      status = res.status
      body = (res.envelope ?? {}) as typeof body
    } catch {
      status = 0
    }
    if (ticket !== latest.current) return

    if (status === 200 && body.data) {
      setLoad({ state: 'ready', payload: body.data as LivePayload, refreshing: false, refreshError: null, computedAt: new Date() })
      return
    }

    const message = body.message ?? failedMessage
    setLoad((prev) => (prev.state === 'ready'
      ? { ...prev, refreshing: false, refreshError: message }
      : { state: 'failed', message, status }))
  }, [token, secret, days, key, failedMessage])

  useEffect(() => {
    if (enabled) void run()
  }, [run, enabled])

  const lastRun = useRef(0)
  useEffect(() => {
    lastRun.current = Date.now()
  }, [load])

  useEffect(() => {
    if (!enabled || !refreshEveryMs) return undefined

    const due = () => document.visibilityState === 'visible' && Date.now() - lastRun.current >= refreshEveryMs
    const tick = window.setInterval(() => { if (due()) void run() }, Math.min(refreshEveryMs, 60_000))
    const onVisible = () => { if (due()) void run() }
    document.addEventListener('visibilitychange', onVisible)

    return () => {
      window.clearInterval(tick)
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [enabled, refreshEveryMs, run])

  return { load, reload: () => void run() }
}
