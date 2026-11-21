import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useUi } from '@/stores/ui'
import { StudioDrawer } from './StudioDrawer'

/** A recording 2D context, so no real canvas is needed: what is drawn, in which style, and with which font. */
const drawn = { fills: [] as string[], fonts: new Set<string>(), texts: 0, draws: 0 }

function stubContext() {
  const state = { fillStyle: '', font: '' }

  return {
    set fillStyle(v: string) { state.fillStyle = v },
    get fillStyle() { return state.fillStyle },
    set font(v: string) { state.font = v; drawn.fonts.add(v) },
    get font() { return state.font },
    globalAlpha: 1, textAlign: 'left', textBaseline: 'alphabetic', direction: 'ltr',
    save() {}, restore() {}, translate() {}, scale() {}, beginPath() {}, rect() {}, clip() {},
    clearRect() {},
    fillRect() { drawn.fills.push(state.fillStyle) },
    fillText() { drawn.texts++ },
    drawImage() { drawn.draws++ },
    measureText: (t: string) => ({ width: t.length * 20 }),
    getImageData: (_x: number, _y: number, w: number, h: number) => {
      const data = new Uint8ClampedArray(w * h * 4)

      for (let i = 0; i < w * h; i++) {
        const x = i % w
        const y = Math.floor(i / w)
        const hot = x > w * 0.2 && x < w * 0.5 && y > h * 0.25 && y < h * 0.45
        data[i * 4] = hot ? 210 : 110; data[i * 4 + 1] = hot ? 40 : 112; data[i * 4 + 2] = hot ? 50 : 118; data[i * 4 + 3] = 255
      }

      return { data, width: w, height: h }
    },
  }
}

beforeEach(() => {
  drawn.fills = []; drawn.fonts = new Set(); drawn.texts = 0; drawn.draws = 0
  vi.stubGlobal('createImageBitmap', async () => ({ width: 720, height: 1280, close() {} }))
  vi.stubGlobal('IntersectionObserver', class { observe() {} disconnect() {} unobserve() {} takeRecords() { return [] } })
  vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockImplementation(() => stubContext() as unknown as CanvasRenderingContext2D)
  useUi.setState({ locale: 'ar' })
})

afterEach(() => { vi.unstubAllGlobals(); vi.restoreAllMocks() })

const open = async () => {
  render(<StudioDrawer file={new Blob(['x'], { type: 'image/png' })} onClose={() => {}} />)
  await screen.findByTestId('studio-grid')
}

describe('the studio drawer', () => {
  it('makes the batch by itself: 3 videos and 4 images, each from a different family, every one ready', async () => {
    await open()

    const videos = screen.getAllByTestId('studio-output-video')
    const images = screen.getAllByTestId('studio-output-image')

    expect(videos).toHaveLength(3)
    expect(images).toHaveLength(4)
    expect(new Set([...videos, ...images].map((li) => li.getAttribute('data-family'))).size).toBe(7)
    expect(screen.getAllByTestId('studio-ready')).toHaveLength(7)
    expect(screen.queryByTestId('studio-hard')).toBeNull()
    expect(screen.getAllByTestId('studio-preview')).toHaveLength(7)
  })

  it('draws the picture and the copy into the previews', async () => {
    await open()

    await waitFor(() => expect(drawn.draws).toBeGreaterThan(0))
    expect(drawn.texts).toBeGreaterThan(0)
  })

  it('shows the protected margins over every preview only when asked', async () => {
    await open()
    await waitFor(() => expect(drawn.draws).toBeGreaterThan(0))

    expect(drawn.fills.includes('#ff2d55')).toBe(false)

    fireEvent.click(screen.getByTestId('studio-margins'))

    await waitFor(() => expect(drawn.fills.includes('#ff2d55')).toBe(true))
  })

  it('«new batch» changes the combination without reading the picture again', async () => {
    await open()

    const before = [...screen.getAllByTestId(/^studio-output-/)].map((li) => li.getAttribute('data-family')).join()
    const reads = vi.mocked(HTMLCanvasElement.prototype.getContext).mock.calls.length

    fireEvent.click(screen.getByTestId('studio-new-batch'))

    await waitFor(() => expect([...screen.getAllByTestId(/^studio-output-/)].map((li) => li.getAttribute('data-family')).join()).not.toBe(before))
    expect(screen.getAllByTestId(/^studio-output-/)).toHaveLength(7)
    expect(reads).toBeGreaterThan(0)
  })

  it('«swap style» changes the font pair of the whole batch', async () => {
    await open()
    await waitFor(() => expect(drawn.fonts.size).toBeGreaterThan(0))

    const before = new Set(drawn.fonts)

    fireEvent.click(screen.getByTestId('studio-swap-style'))

    await waitFor(() => expect([...drawn.fonts].some((f) => !before.has(f))).toBe(true))
    expect(screen.getAllByTestId(/^studio-output-/)).toHaveLength(7)
  })

  it('a hashtag in the copy blocks every output and says why, instead of looking fine', async () => {
    await open()

    fireEvent.change(screen.getByTestId('studio-copy-message'), { target: { value: 'عطر #جديد' } })

    await waitFor(() => expect(screen.getAllByTestId('studio-hard')).toHaveLength(7))
    expect(within(screen.getAllByTestId('studio-hard')[0]!).getByText(/هاشتاق/)).toBeTruthy()
    expect(screen.queryByTestId('studio-ready')).toBeNull()
    expect(screen.getByTestId('studio-copy-warning')).toBeTruthy()
  })

  it('says so when the picture cannot be read, and draws nothing', async () => {
    vi.stubGlobal('createImageBitmap', async () => { throw new Error('bad image') })
    render(<StudioDrawer file={new Blob(['x'])} onClose={() => {}} />)

    expect(await screen.findByTestId('studio-failed')).toBeTruthy()
    expect(screen.queryByTestId('studio-grid')).toBeNull()
  })

  it('speaks English when the interface does', async () => {
    useUi.setState({ locale: 'en' })
    await open()

    expect(screen.getByText('New batch')).toBeTruthy()
    expect(screen.getByText('Show margins')).toBeTruthy()
  })
})
