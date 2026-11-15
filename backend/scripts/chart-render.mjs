// NOTIF-V2-EXTERNAL-001 — SVG → PNG for the external emails' charts, through resvg (free, no browser, no PDF).
//
// Batched: ONE process renders every chart of a run. Input on stdin: {"requireBase": "...", "charts": [{"key": "...",
// "svg": "<svg …>", "width": 1120}]} (width already 2x). Output on stdout: {"key": {"png": base64, "ms": 12.3}, …};
// a chart that fails is {"key": {"error": "..."}} and the caller falls back to its table. Nothing is written to disk.
import { createRequire } from 'module'

const chunks = []
for await (const chunk of process.stdin) chunks.push(chunk)
const input = JSON.parse(Buffer.concat(chunks).toString('utf8'))
const require = createRequire(input.requireBase || import.meta.url)
const { Resvg } = require('@resvg/resvg-js')

const out = {}
for (const chart of input.charts || []) {
  const started = process.hrtime.bigint()
  try {
    const resvg = new Resvg(chart.svg, {
      fitTo: { mode: 'width', value: chart.width },
      font: { loadSystemFonts: true, defaultFontFamily: 'Noto Sans Arabic' },
      background: 'rgba(0,0,0,0)',
    })
    const png = resvg.render().asPng()
    out[chart.key] = { png: Buffer.from(png).toString('base64'), ms: Number(process.hrtime.bigint() - started) / 1e6 }
  } catch (e) {
    out[chart.key] = { error: String(e && e.message ? e.message : e).slice(0, 200) }
  }
}
process.stdout.write(JSON.stringify(out))
