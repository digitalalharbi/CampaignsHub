import { describe, expect, it } from 'vitest'

import { anyDisplayablePreview, hasDisplayablePreview } from './previewPresence'

describe('whether the grid reserves a preview column', () => {
  it('reserves it when at least one creative carries an asset', () => {
    expect(anyDisplayablePreview([
      { preview: { thumbnail_url: null, image_url: null, video_url: null } },
      { preview: { thumbnail_url: null, image_url: null, video_url: 'https://cdn/x.mp4' } },
    ])).toBe(true)
  })

  it('drops it when the platform returned no file for anything in the result', () => {
    expect(anyDisplayablePreview([
      { preview: { thumbnail_url: null, image_url: null, video_url: null } },
      { preview: { thumbnail_url: null, image_url: null, video_url: null } },
    ])).toBe(false)
  })

  /** CONTENT-PREVIEW-VIDEO-001 — a video with no separate thumbnail is not «no preview». */
  it('counts a video with no thumbnail as an asset', () => {
    expect(hasDisplayablePreview({ thumbnail_url: null, image_url: null, video_url: 'https://cdn/v.mp4' })).toBe(true)
  })

  it('treats an empty string as no asset, because a card cannot display one', () => {
    expect(hasDisplayablePreview({ thumbnail_url: '', image_url: '', video_url: '' })).toBe(false)
  })

  it('reserves nothing for an empty result', () => {
    expect(anyDisplayablePreview([])).toBe(false)
  })

  it('survives a creative whose preview block is missing entirely', () => {
    expect(anyDisplayablePreview([{ preview: null }, {}])).toBe(false)
  })
})
