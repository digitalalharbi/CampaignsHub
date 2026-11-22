/**
 * CONTENT-LIBRARY-SNAP-001 — «تحميل الكل»: the saved designs in one .zip, built in the browser.
 *
 * Stored, not deflated: PNG and MP4 are already compressed, so deflating them again costs time and saves nothing. The format is the
 * plain PKZIP layout (local headers, central directory, end record) with UTF-8 names, which every OS opens.
 */
const CRC_TABLE = (() => {
  const t = new Uint32Array(256)
  for (let n = 0; n < 256; n++) {
    let c = n
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
    t[n] = c >>> 0
  }

  return t
})()

export function crc32(bytes: Uint8Array): number {
  let c = 0xffffffff
  for (let i = 0; i < bytes.length; i++) c = CRC_TABLE[(c ^ bytes[i]!) & 0xff]! ^ (c >>> 8)

  return (c ^ 0xffffffff) >>> 0
}

export async function zipFiles(files: ReadonlyArray<{ name: string; blob: Blob }>): Promise<Blob> {
  const enc = new TextEncoder()
  const parts: Uint8Array[] = []
  const central: Uint8Array[] = []
  let offset = 0

  for (const f of files) {
    const data = new Uint8Array(await f.blob.arrayBuffer())
    const name = enc.encode(f.name)
    const crc = crc32(data)

    const local = new DataView(new ArrayBuffer(30))
    local.setUint32(0, 0x04034b50, true)
    local.setUint16(4, 20, true)
    local.setUint16(6, 0x0800, true) // UTF-8 names
    local.setUint32(14, crc, true)
    local.setUint32(18, data.length, true)
    local.setUint32(22, data.length, true)
    local.setUint16(26, name.length, true)
    parts.push(new Uint8Array(local.buffer), name, data)

    const head = new DataView(new ArrayBuffer(46))
    head.setUint32(0, 0x02014b50, true)
    head.setUint16(4, 20, true)
    head.setUint16(6, 20, true)
    head.setUint16(8, 0x0800, true)
    head.setUint32(16, crc, true)
    head.setUint32(20, data.length, true)
    head.setUint32(24, data.length, true)
    head.setUint16(28, name.length, true)
    head.setUint32(42, offset, true)
    central.push(new Uint8Array(head.buffer), name)

    offset += 30 + name.length + data.length
  }

  const size = central.reduce((n, c) => n + c.length, 0)
  const end = new DataView(new ArrayBuffer(22))
  end.setUint32(0, 0x06054b50, true)
  end.setUint16(8, files.length, true)
  end.setUint16(10, files.length, true)
  end.setUint32(12, size, true)
  end.setUint32(16, offset, true)

  return new Blob([...parts, ...central, new Uint8Array(end.buffer)].map((p) => new Uint8Array(p)), { type: 'application/zip' })
}

export function downloadBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
