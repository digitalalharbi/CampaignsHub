import { useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'

/**
 * NOTIF-PUSH-001 — a push that arrives while the app is open refreshes the bell at once.
 *
 * The service worker tells every open window (`{ type: 'push' }`); the bell's summary is refetched, so the number moves without
 * waiting for the 30-second poll. No socket, no extra polling — the push IS the realtime channel when it is on.
 */
export function usePushRefresh(): void {
  const qc = useQueryClient()

  useEffect(() => {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return
    const onMessage = (e: MessageEvent) => {
      if ((e.data as { type?: string } | null)?.type === 'push') void qc.invalidateQueries({ queryKey: ['notifications'] })
    }
    navigator.serviceWorker.addEventListener('message', onMessage)

    return () => navigator.serviceWorker.removeEventListener('message', onMessage)
  }, [qc])
}
