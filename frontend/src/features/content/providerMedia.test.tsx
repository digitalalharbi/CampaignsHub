import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PosterImage } from './PosterImage'
import { cardPoster, posterSource, readPreview } from './adPreview'
import type { CreativePreview } from './api'
import { isProviderSignedStill, localStill } from './providerMedia'

/**
 * CREATIVE-PREVIEWS-001 — Owner, 2026-11-07: «حل مشكلة عدم ظهور المعاينة أو صورة الغلاف لمنصة تيك توك وميتا
 * وباقي المنصات! باستثناء سناب تظهر الصور والمعاينات.»
 *
 * A Meta (`fbcdn`) or TikTok (`tiktokcdn`, `ibyteimg`) signed still is never put in an `<img>`: the server
 * sends our own copy (`/media/covers/…`), and should a raw platform link ever slip through again the
 * browser is not asked to load it. Snapchat's media links, which draw today, are untouched.
 */
const META = 'https://scontent-fra3-1.xx.fbcdn.net/v/t45/full.jpg?oe=6A1B2C3D'
const TIKTOK = 'https://p16-common-sign.tiktokcdn.com/obj/cover.jpg?x-expires=1762000000&x-signature=s'
const BYTE = 'https://p19-ad-site-sign-sg.ibyteimg.com/x.image?x-expires=1762000000'
const SNAP = 'https://adsapi-media.snapchat.com/media/abc/download?e=4000000000'
const OURS = '/media/covers/ab/abcdef.jpg'

const preview = (over: Partial<CreativePreview>): CreativePreview => ({
  state: 'available', kind: 'image', aspect: null,
  image_url: null, video_url: null, thumbnail_url: null,
  expires_at: null, note_ar: null, note_en: null,
  ...over,
})

describe('which stills may be loaded', () => {
  it('names Meta and TikTok signed hosts, and nothing of ours or Snapchat’s', () => {
    expect([META, TIKTOK, BYTE, 'https://www.facebook.com/ads/image/?d=x'].map(isProviderSignedStill)).toEqual([true, true, true, true])
    expect([OURS, SNAP, 'data:image/png;base64,AA', '/demo/creative-sample.jpg', null, undefined, 'not a url'].map(isProviderSignedStill))
      .toEqual([false, false, false, false, false, false, false])
    expect(localStill(META)).toBeNull()
    expect(localStill(OURS)).toBe(OURS)
  })

  it('reads our copy, and never a signed platform still, from a preview', () => {
    expect(posterSource(readPreview(preview({ image_url: META, thumbnail_url: OURS }), true))).toBe(OURS)
    expect(readPreview(preview({ image_url: TIKTOK }), true)).toMatchObject({ kind: 'none', reason: 'no_media' })
    expect(readPreview(preview({ kind: 'video', video_url: 'https://v16m.tiktokcdn.com/v.mp4', thumbnail_url: TIKTOK }), true))
      .toMatchObject({ kind: 'video', poster: null })
    expect(cardPoster({ image_url: META, thumbnail_url: OURS })).toBe(OURS)
    // Snapchat: unchanged.
    expect(posterSource(readPreview(preview({ image_url: SNAP }), true))).toBe(SNAP)
  })

  it('says «pending» or «failed» in a short chip when our copy is not there yet', () => {
    expect(readPreview(preview({ state: 'cover_pending', note_ar: 'الغلاف قيد التجهيز.' }), true))
      .toEqual({ kind: 'none', reason: 'cover_pending', note: 'الغلاف قيد التجهيز.' })
    expect(readPreview(preview({ state: 'cover_failed' }), false)).toMatchObject({ kind: 'none', reason: 'cover_failed' })
  })
})

describe('PosterImage refuses a signed platform still', () => {
  it('draws the caller’s stated absence instead of loading it, and reports the failure', () => {
    const failed = vi.fn()
    render(<PosterImage src={META} alt="" testid="poster" fallback={<span data-testid="absence">absent</span>} onFailed={failed} />)

    expect(screen.queryByTestId('poster')).toBeNull()
    expect(screen.getByTestId('absence')).toBeTruthy()
    expect(document.querySelector('img')).toBeNull()
    expect(failed).toHaveBeenCalledTimes(1)
  })

  it('loads our own copy as before', () => {
    render(<PosterImage src={OURS} alt="" testid="poster" fallback={<span data-testid="absence">absent</span>} />)

    expect(screen.getByTestId('poster').getAttribute('src')).toBe(OURS)
  })
})

/**
 * The source guard: a surface that reads a creative's raw still field straight into an `<img src>` must pass
 * it through `localStill()`. The chokepoints above cover the components that go through them; this covers
 * the ones that spell `<img src={x.thumbnail_url}>` by hand.
 */
const TREE: Record<string, string> = import.meta.glob('/src/features/**/*.tsx', { query: '?raw', import: 'default', eager: true })

describe('no surface puts a raw creative still in an <img>', () => {
  it('wraps every thumbnail_url / image_url src in localStill()', () => {
    const offenders: string[] = []
    for (const [path, source] of Object.entries(TREE)) {
      if (path.includes('.test.')) continue
      for (const match of source.matchAll(/<img[^>]*?\ssrc=\{([^}]*)\}/gs)) {
        const expr = match[1] ?? ''
        if (/(thumbnail_url|image_url)/.test(expr) && !expr.includes('localStill(')) offenders.push(`${path}: src={${expr.trim()}}`)
      }
    }

    expect(offenders).toEqual([])
  })
})
