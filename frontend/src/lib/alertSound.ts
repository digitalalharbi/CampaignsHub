/**
 * NOTIF-SOUND-001 — the bell's «ping».
 *
 * One short, soft sine tone made with the Web Audio API — no audio file, no library, nothing to download or license. It is
 * under 400 ms, plays once, and sits at a low level (the loudest setting is a quarter of full scale).
 *
 * ## Autoplay policy, and why this never logs an error
 *
 * Browsers refuse to start audio before the person has touched the page. `unlockAudio()` is installed once, on the first
 * pointer / key / touch event, and creates and resumes the context inside that gesture. Until then `playPing()` simply
 * returns `false`: a notification that arrives before the first click is silent, which is correct, and nothing is thrown or
 * logged. A suspended context is never «resumed» from a timer — that is exactly the call that prints the autoplay warning.
 *
 * ## When it may play — `decidePing`
 *
 * The decision is a pure function so it is tested without a browser: only for a NEW critical or warning notification that
 * was not seen before, once for the whole batch however many arrived together, never for plain info, never in quiet hours,
 * never when the person switched it off.
 */
export const PING_MAX_GAIN = 0.25
export const PING_SECONDS = 0.32

type Ctx = AudioContext

let context: Ctx | null = null
let unlocked = false
let installed = false

function create(): Ctx | null {
  try {
    const Ctor = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
    if (!Ctor) return null

    return new Ctor()
  } catch {
    return null
  }
}

/** Create and resume the context. Only ever called from inside a user gesture. */
/** A person has interacted with the page at least once — the browser's own condition for sound. */
let gestured = false

function unlock(): void {
  gestured = true
  if (unlocked) return
  context ??= create()
  if (!context) return
  // `resume()` inside a gesture is allowed; the promise result is not needed and a rejection must stay silent.
  void context.resume().then(() => { unlocked = context?.state === 'running' }).catch(() => undefined)
  unlocked = context.state === 'running'
}

/** Listen once for the first interaction. Safe to call more than once and on the server. */
export function installAudioUnlock(): void {
  if (installed || typeof window === 'undefined') return
  installed = true
  /*
   * `mousedown` and `click` as well as `pointerdown` (CI PR #1071, Firefox): a gesture that reaches the page as mouse events
   * only — Firefox driven by automation, an older engine — is still the person's first interaction, and without it the
   * context was never resumed and the bell never rang.
   */
  const events = ['pointerdown', 'mousedown', 'click', 'keydown', 'touchstart'] as const
  const handler = () => {
    unlock()
    if (unlocked) events.forEach((e) => window.removeEventListener(e, handler, true))
  }
  events.forEach((e) => window.addEventListener(e, handler, { capture: true, passive: true }))
}

/** True once a gesture has unlocked audio — read by the settings page to word its hint. */
export function audioReady(): boolean {
  return context !== null && context.state === 'running'
}

/**
 * Play the ping. Returns false (silently) when audio is locked, unsupported, or the volume is zero.
 *
 * `fromGesture` is for the «جرّب الصوت» button: the click is the gesture, so the context may be created and resumed right here.
 */
export function playPing(volume: number, opts: { fromGesture?: boolean } = {}): boolean {
  const level = Math.max(0, Math.min(100, volume)) / 100
  if (level === 0 || typeof window === 'undefined') return false

  if (opts.fromGesture) unlock()
  const ctx = context
  if (!ctx) return false
  if (ctx.state !== 'running') {
    /*
     * Firefox reports a context resumed inside a gesture as `suspended` until its audio thread starts, and may suspend it
     * again when the tab goes to the background. After a real gesture that is a delay, not a refusal: resume, and ring
     * once it is actually running. Before any gesture nothing is attempted, so no autoplay warning is ever logged.
     */
    if (!gestured || ctx.state === 'closed') return false
    /*
     * Scheduled now, not on resume's promise (CI PR #1071, round 3): Firefox without an audio device (a headless CI box,
     * a desktop with the output unplugged) never settles that promise, and the ping was never started at all. A tone
     * scheduled on a suspended context sounds as soon as the browser lets the context run; on a machine that cannot
     * sound, nothing is heard, which is the honest outcome there.
     */
    void ctx.resume().catch(() => undefined)

    return tone(ctx, level)
  }

  return tone(ctx, level)
}

/** One short, soft sine — started only on a running context. */
function tone(ctx: Ctx, level: number): boolean {
  try {
    const now = ctx.currentTime
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()
    osc.type = 'sine'
    osc.frequency.setValueAtTime(880, now)
    // A soft attack and a smooth fade: no click at either end.
    gain.gain.setValueAtTime(0.0001, now)
    gain.gain.linearRampToValueAtTime(PING_MAX_GAIN * level, now + 0.012)
    gain.gain.exponentialRampToValueAtTime(0.0001, now + PING_SECONDS)
    osc.connect(gain)
    gain.connect(ctx.destination)
    osc.start(now)
    osc.stop(now + PING_SECONDS + 0.02)
    osc.onended = () => { osc.disconnect(); gain.disconnect() }

    return true
  } catch {
    return false
  }
}

export interface PingInput {
  /** Unread critical / warning notifications the server listed. */
  attention: ReadonlyArray<{ id: string; severity: string }>
  /** Ids already seen by this tab; the first load seeds it and never plays. */
  seen: ReadonlySet<string> | null
  sound: { enabled: boolean; volume: number; quiet: boolean } | undefined
}

/** One key per notification AND its severity, so a warning that escalated to critical counts as new. */
export const pingKey = (n: { id: string; severity: string }) => `${n.id}:${n.severity}`

/**
 * Should this refresh play the ping — and what has now been seen.
 *
 * Nothing plays on the first refresh (what was already waiting when the page opened is not «new»), nor for ids already seen,
 * nor when the reader switched it off or it is their quiet hours. Whatever the verdict, every id listed becomes seen: a
 * notification that arrived during quiet hours must not ring the moment quiet hours end.
 */
export function decidePing({ attention, seen, sound }: PingInput): { play: boolean; seen: Set<string> } {
  const next = new Set(seen ?? [])
  const fresh = attention.filter((n) => (n.severity === 'critical' || n.severity === 'warning') && !next.has(pingKey(n)))
  attention.forEach((n) => next.add(pingKey(n)))

  const play = seen !== null && fresh.length > 0 && sound !== undefined && sound.enabled && !sound.quiet && sound.volume > 0

  return { play, seen: next }
}
