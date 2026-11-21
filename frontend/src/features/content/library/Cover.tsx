import { useState } from 'react'
import { Film, ImageOff } from 'lucide-react'
import { firstFrameSrc, mediaFitClass, posterSource, readPreview } from '../adPreview'
import type { CreativePreview } from '../api'

const COVER = mediaFitClass(null, null, 'cover')

/**
 * CONTENT-LIBRARY-SNAP-001 — one cover for every library surface: the still where there is one, the film's own first frame where
 * the platform sent only the film, and a plain mark where neither loads. Covers fill their box (CONTENT-THUMB-FILL-001).
 */
export function Cover({ preview, ar, className = '' }: { preview: CreativePreview | null | undefined; ar: boolean; className?: string }) {
  const [broken, setBroken] = useState(false)
  const reading = readPreview(preview, ar)
  const still = posterSource(reading)

  if (still && !broken) {
    return <img src={still} alt="" loading="lazy" onError={() => setBroken(true)} className={`${className} bg-surface-secondary ${COVER}`} />
  }
  if (reading.kind === 'video' && !broken) {
    return <video src={firstFrameSrc(reading.src)} preload="metadata" muted playsInline onError={() => setBroken(true)} className={`${className} bg-surface-secondary ${COVER}`} />
  }
  const Icon = reading.kind === 'video' ? Film : ImageOff

  return (
    <div className={`${className} flex items-center justify-center bg-surface-secondary text-text-muted`}>
      <Icon size={18} aria-hidden />
    </div>
  )
}
