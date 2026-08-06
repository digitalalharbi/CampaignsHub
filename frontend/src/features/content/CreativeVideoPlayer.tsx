import { useCallback, useEffect, useRef, useState } from 'react'
import { Maximize2, Pause, Play, Volume2, VolumeX } from 'lucide-react'
import { formatClock } from './format'
import { useUi } from '@/stores/ui'

/**
 * §15.4 — a video player, rather than a thumbnail with a play icon on it.
 *
 * ## Nothing plays until somebody asks
 *
 * `preload="metadata"` and no autoplay, ever. A library page is a grid of many videos; letting them
 * preload their streams turns opening a page into tens of megabytes on a phone, and letting them
 * autoplay turns it into tens of megabytes and a wall of noise. Metadata is enough to know the
 * duration and show the poster, which is all the card needs before a click.
 *
 * ## It stops when you look away
 *
 * `src` is attached only once the viewer presses play (`armed`), and the effect below pauses and
 * detaches on unmount and whenever the source changes. Without that, arrowing from one creative to
 * the next in the viewer leaves the previous video playing audio over the new one — §15.4 asks for
 * «إيقاف الفيديو عند الانتقال» and this is where it is enforced, not by a caller remembering to.
 *
 * ## Keyboard, and the reason for the guard
 *
 * Space/K toggle, arrows seek, M mutes, F is fullscreen — the shortcuts anybody who has used a video
 * player already knows. They are bound to the PLAYER, not the document: a global handler would eat
 * the space bar in the search box beside it, and swallow the arrow keys the viewer uses to move
 * between creatives.
 */

const SPEEDS = [0.5, 1, 1.5, 2]

const COPY = {
  ar: {
    play: 'تشغيل',
    pause: 'إيقاف مؤقت',
    mute: 'كتم الصوت',
    unmute: 'إلغاء الكتم',
    fullscreen: 'ملء الشاشة',
    speed: 'سرعة التشغيل',
    seek: 'موضع التشغيل',
    volume: 'مستوى الصوت',
    player: 'مشغل الفيديو',
  },
  en: {
    play: 'Play',
    pause: 'Pause',
    mute: 'Mute',
    unmute: 'Unmute',
    fullscreen: 'Fullscreen',
    speed: 'Playback speed',
    seek: 'Seek',
    volume: 'Volume',
    player: 'Video player',
  },
}

export function CreativeVideoPlayer({
  src,
  poster,
  durationHint,
  className = '',
}: {
  src: string
  poster?: string | null
  /** The platform's own reported duration, shown before the file has loaded its metadata. */
  durationHint?: number | null
  className?: string
}) {
  const { locale } = useUi()
  const t = COPY[locale === 'ar' ? 'ar' : 'en']

  const videoRef = useRef<HTMLVideoElement>(null)
  const shellRef = useRef<HTMLDivElement>(null)

  const [armed, setArmed] = useState(false)
  const [playing, setPlaying] = useState(false)
  const [muted, setMuted] = useState(false)
  const [volume, setVolume] = useState(1)
  const [speed, setSpeed] = useState(1)
  const [current, setCurrent] = useState(0)
  const [duration, setDuration] = useState(durationHint ?? 0)

  /*
   * Pause and detach whenever the source changes or the player unmounts.
   *
   * Returning to `armed = false` is deliberate: the next creative starts from its poster, in the
   * same state this one did, rather than inheriting a half-played timeline from the last video.
   */
  useEffect(() => {
    const video = videoRef.current

    return () => {
      if (video) {
        video.pause()
        video.removeAttribute('src')
        video.load()
      }
      setArmed(false)
      setPlaying(false)
      setCurrent(0)
    }
  }, [src])

  const toggle = useCallback(() => {
    const video = videoRef.current
    if (!video) return

    if (!armed) {
      setArmed(true)
      return
    }

    if (video.paused) void Promise.resolve(video.play()).catch(() => setPlaying(false))
    else video.pause()
  }, [armed])

  const seekBy = useCallback((delta: number) => {
    const video = videoRef.current
    if (!video || !Number.isFinite(video.duration)) return
    video.currentTime = Math.min(Math.max(video.currentTime + delta, 0), video.duration)
  }, [])

  /*
   * Once armed, start playing — the click that armed it WAS the request to play, and requiring a
   * second click to actually start reads as a broken button.
   *
   * `play()` is wrapped rather than awaited directly: it returns a Promise in modern browsers and
   * `undefined` in older ones (and in jsdom), so calling `.catch` on the result throws a TypeError
   * and takes the whole player down with it. `Promise.resolve` normalises both. The rejection it
   * catches is the ordinary one — a browser refusing to play un-muted media without a gesture it
   * recognises — and the right response is to show the play control again, not to raise an error.
   */
  useEffect(() => {
    if (!armed) return
    const video = videoRef.current
    if (video) void Promise.resolve(video.play()).catch(() => setPlaying(false))
  }, [armed])

  const onKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
    const keys = [' ', 'k', 'K', 'ArrowRight', 'ArrowLeft', 'm', 'M', 'f', 'F']
    if (!keys.includes(event.key)) return

    // Only once we know we handle it: a blanket `preventDefault` here would stop the viewer's own
    // arrow-key navigation between creatives from ever running.
    event.preventDefault()
    event.stopPropagation()

    if (event.key === ' ' || event.key.toLowerCase() === 'k') toggle()
    if (event.key === 'ArrowRight') seekBy(5)
    if (event.key === 'ArrowLeft') seekBy(-5)
    if (event.key.toLowerCase() === 'm') {
      const video = videoRef.current
      if (video) {
        video.muted = !video.muted
        setMuted(video.muted)
      }
    }
    if (event.key.toLowerCase() === 'f') void shellRef.current?.requestFullscreen?.()
  }

  const progress = duration > 0 ? (current / duration) * 100 : 0

  return (
    <div
      ref={shellRef}
      role="group"
      aria-label={t.player}
      tabIndex={0}
      onKeyDown={onKeyDown}
      className={`relative overflow-hidden rounded-lg bg-black focus:outline-none focus-visible:ring-2 focus-visible:ring-primary ${className}`}
    >
      <video
        ref={videoRef}
        // Attached only after the viewer asks: an unarmed player holds no `src`, so it opens no
        // connection and downloads nothing at all.
        src={armed ? src : undefined}
        poster={poster ?? undefined}
        preload="metadata"
        playsInline
        // The platform's own controls on touch: a hand-built scrubber is worse than the native one
        // at the size a thumb actually is, and fullscreen on iOS is only reliable through them.
        controls={armed}
        controlsList="nodownload"
        className="aspect-video w-full bg-black"
        onPlay={() => setPlaying(true)}
        onPause={() => setPlaying(false)}
        onTimeUpdate={(e) => setCurrent(e.currentTarget.currentTime)}
        onLoadedMetadata={(e) => {
          setDuration(e.currentTarget.duration)
          e.currentTarget.playbackRate = speed
          e.currentTarget.volume = volume
        }}
        onVolumeChange={(e) => {
          setMuted(e.currentTarget.muted)
          setVolume(e.currentTarget.volume)
        }}
      />

      {!armed && (
        <button
          type="button"
          onClick={toggle}
          aria-label={t.play}
          className="absolute inset-0 flex items-center justify-center bg-black/30 transition hover:bg-black/40"
        >
          <span className="flex h-16 w-16 items-center justify-center rounded-full bg-white/90 text-slate-900 shadow-lg">
            <Play className="h-7 w-7 translate-x-0.5" aria-hidden />
          </span>
        </button>
      )}

      {armed && (
        <div className="flex flex-wrap items-center gap-2 bg-slate-900 px-3 py-2 text-xs text-white">
          <button type="button" onClick={toggle} aria-label={playing ? t.pause : t.play} className="p-1">
            {playing ? <Pause className="h-4 w-4" aria-hidden /> : <Play className="h-4 w-4" aria-hidden />}
          </button>

          {/* Latin digits, in both languages — the contract's rule, and the only way a duration
              beside an English metric name reads as one sentence. */}
          <span className="tabular-nums" dir="ltr">
            {formatClock(current)} / {formatClock(duration)}
          </span>

          <label className="flex min-w-24 flex-1 items-center gap-2">
            <span className="sr-only">{t.seek}</span>
            <input
              type="range"
              min={0}
              max={100}
              value={Number.isFinite(progress) ? progress : 0}
              onChange={(e) => {
                const video = videoRef.current
                if (video && Number.isFinite(video.duration)) {
                  video.currentTime = (Number(e.target.value) / 100) * video.duration
                }
              }}
              className="w-full accent-primary"
            />
          </label>

          <button
            type="button"
            aria-label={muted ? t.unmute : t.mute}
            onClick={() => {
              const video = videoRef.current
              if (video) {
                video.muted = !video.muted
                setMuted(video.muted)
              }
            }}
            className="p-1"
          >
            {muted ? <VolumeX className="h-4 w-4" aria-hidden /> : <Volume2 className="h-4 w-4" aria-hidden />}
          </button>

          <label className="flex items-center gap-1">
            <span className="sr-only">{t.volume}</span>
            <input
              type="range"
              min={0}
              max={1}
              step={0.05}
              value={muted ? 0 : volume}
              onChange={(e) => {
                const video = videoRef.current
                if (video) {
                  video.volume = Number(e.target.value)
                  video.muted = Number(e.target.value) === 0
                }
              }}
              className="w-16 accent-primary"
            />
          </label>

          <label className="flex items-center gap-1">
            <span className="sr-only">{t.speed}</span>
            <select
              value={speed}
              onChange={(e) => {
                const rate = Number(e.target.value)
                setSpeed(rate)
                const video = videoRef.current
                if (video) video.playbackRate = rate
              }}
              className="rounded bg-slate-800 px-1 py-0.5 text-white"
              dir="ltr"
            >
              {SPEEDS.map((rate) => (
                <option key={rate} value={rate}>
                  {rate}×
                </option>
              ))}
            </select>
          </label>

          <button
            type="button"
            aria-label={t.fullscreen}
            onClick={() => void shellRef.current?.requestFullscreen?.()}
            className="p-1"
          >
            <Maximize2 className="h-4 w-4" aria-hidden />
          </button>
        </div>
      )}
    </div>
  )
}
