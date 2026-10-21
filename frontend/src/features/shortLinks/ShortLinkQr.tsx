import { useMemo, useRef } from 'react'
import qrcode from 'qrcode-generator'
import { Download } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'

/**
 * SHORT-LINK-QR-001 — the short link as a code a phone can read off a print, a screen or a slide.
 *
 * Encoded in the browser from the address the row already shows; nothing is sent anywhere and no image
 * service is involved, so the code is the link and only the link. The modules are drawn as plain
 * rectangles so the SVG is text the reader can inspect, and the PNG is the same drawing on a canvas at a
 * print size. Error correction M (15 %) is the usual choice for a code with nothing printed over it.
 */
const EC = 'M'
const CELL = 8
const QUIET = 4
const PNG_SCALE = 4

export function qrModules(text: string): boolean[][] {
  const code = qrcode(0, EC)
  code.addData(text, 'Byte')
  code.make()
  const n = code.getModuleCount()
  const rows: boolean[][] = []
  for (let r = 0; r < n; r++) {
    const row: boolean[] = []
    for (let c = 0; c < n; c++) row.push(code.isDark(r, c))
    rows.push(row)
  }

  return rows
}

function svgMarkup(modules: boolean[][]): string {
  const n = modules.length
  const size = (n + QUIET * 2) * CELL
  const rects = modules.flatMap((row, r) => row.flatMap((dark, c) => (dark ? [`<rect x="${(c + QUIET) * CELL}" y="${(r + QUIET) * CELL}" width="${CELL}" height="${CELL}"/>`] : [])))

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" width="${size}" height="${size}" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><g fill="#000">${rects.join('')}</g></svg>`
}

function save(filename: string, blob: Blob) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  window.setTimeout(() => URL.revokeObjectURL(url), 1000)
}

export function ShortLinkQr({ url, slug, ar, onClose }: { url: string; slug: string; ar: boolean; onClose: () => void }) {
  const modules = useMemo(() => qrModules(url), [url])
  const n = modules.length
  const size = (n + QUIET * 2) * CELL
  const canvasRef = useRef<HTMLCanvasElement>(null)

  const downloadSvg = () => save(`${slug}.svg`, new Blob([svgMarkup(modules)], { type: 'image/svg+xml' }))
  const downloadPng = () => {
    const canvas = canvasRef.current ?? document.createElement('canvas')
    canvas.width = size * PNG_SCALE
    canvas.height = size * PNG_SCALE
    const ctx = canvas.getContext('2d')
    if (!ctx) return
    ctx.fillStyle = '#fff'
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    ctx.fillStyle = '#000'
    modules.forEach((row, r) => row.forEach((dark, c) => {
      if (dark) ctx.fillRect((c + QUIET) * CELL * PNG_SCALE, (r + QUIET) * CELL * PNG_SCALE, CELL * PNG_SCALE, CELL * PNG_SCALE)
    }))
    canvas.toBlob((blob) => { if (blob) save(`${slug}.png`, blob) }, 'image/png')
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={ar ? 'رمز QR للرابط' : 'QR code for the link'}
      size="sm"
      footer={<Button onClick={onClose}>{ar ? 'إغلاق' : 'Close'}</Button>}
    >
      <div className="flex flex-col items-center gap-3" data-testid="short-link-qr" data-modules={n}>
        <svg viewBox={`0 0 ${size} ${size}`} width={240} height={240} role="img" aria-label={url} shapeRendering="crispEdges" className="rounded-lg bg-white">
          <rect width="100%" height="100%" fill="#fff" />
          {modules.map((row, r) => row.map((dark, c) => (dark ? <rect key={`${r}-${c}`} x={(c + QUIET) * CELL} y={(r + QUIET) * CELL} width={CELL} height={CELL} fill="#000" /> : null)))}
        </svg>
        <code dir="ltr" className="max-w-full break-all text-center text-xs text-text-secondary">{url}</code>
        <div className="flex flex-wrap justify-center gap-2">
          <Button variant="secondary" onClick={downloadSvg} data-testid="short-link-qr-svg"><Download size={14} aria-hidden /> {ar ? 'تنزيل SVG' : 'Download SVG'}</Button>
          <Button variant="secondary" onClick={downloadPng} data-testid="short-link-qr-png"><Download size={14} aria-hidden /> {ar ? 'تنزيل PNG' : 'Download PNG'}</Button>
        </div>
        <p className="text-center text-xs text-text-muted">
          {ar ? 'يُولَّد الرمز هنا من العنوان نفسه؛ لا يُرسل شيء لأي خدمة. مسحه يفتح الرابط القصير ويُحتسب كنقرة.' : 'Generated here from the address itself; nothing is sent to any service. Scanning opens the short link and counts as a click.'}
        </p>
        <canvas ref={canvasRef} className="hidden" aria-hidden />
      </div>
    </Modal>
  )
}
