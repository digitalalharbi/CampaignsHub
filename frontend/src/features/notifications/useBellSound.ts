import { useEffect, useRef } from 'react'
import { decidePing, installAudioUnlock, playPing } from '@/lib/alertSound'
import type { BellSummary } from './api'

/**
 * NOTIF-SOUND-001 — plays the ping for a new critical / warning notification, once per refresh however many arrived.
 *
 * Fed by the bell's own summary query, so there is no second poll. `seen` starts null: the first summary only records what was
 * already waiting. `decidePing` holds the rules; this is the wiring.
 */
export function useBellSound(summary: BellSummary | undefined): void {
  const seen = useRef<Set<string> | null>(null)

  useEffect(() => { installAudioUnlock() }, [])

  useEffect(() => {
    if (!summary) return
    const verdict = decidePing({ attention: summary.attention, seen: seen.current, sound: summary.sound })
    seen.current = verdict.seen
    if (verdict.play && summary.sound) playPing(summary.sound.volume)
  }, [summary])
}
