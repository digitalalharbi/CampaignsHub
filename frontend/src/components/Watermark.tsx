import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useAuth } from '@/stores/auth'

/**
 * Dynamic, tiled watermark for sensitive content (reports, media plans, AI output, budgets).
 *
 * HONEST SCOPE: this is a DETERRENCE + attribution layer, not screenshot prevention. A browser web
 * app cannot stop a phone camera or OS screen capture. See docs/../CONTENT_PROTECTION.md. The value
 * is that any leaked capture carries the viewer's identity + timestamp.
 */
export function Watermark({
  children,
  label,
  enabled = true,
}: {
  children: ReactNode
  label?: string
  enabled?: boolean
}) {
  const user = useAuth((s) => s.user)
  const [now, setNow] = useState(() => new Date().toISOString().slice(0, 16).replace('T', ' '))

  // Refresh the timestamp periodically so a capture is time-stamped close to when it was taken.
  useEffect(() => {
    if (!enabled) return
    const id = setInterval(() => setNow(new Date().toISOString().slice(0, 16).replace('T', ' ')), 30_000)
    return () => clearInterval(id)
  }, [enabled])

  const text = useMemo(() => {
    const who = user ? `${user.name} · ${maskEmail(user.email)}` : 'Confidential'
    return `${label ? label + ' · ' : ''}${who} · ${now}`
  }, [user, label, now])

  if (!enabled) return <>{children}</>

  const tile = encodeURIComponent(text)
  const svg =
    `data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='420' height='160'>` +
    `<text x='0' y='80' fill='rgba(120,120,120,0.14)' font-size='13' font-family='monospace' ` +
    `transform='rotate(-24 0 80)'>${tile}</text></svg>`

  return (
    <div className="relative">
      {children}
      <div
        aria-hidden
        className="pointer-events-none absolute inset-0 z-10 select-none"
        style={{ backgroundImage: `url("${svg}")`, backgroundRepeat: 'repeat' }}
      />
      <span className="pointer-events-none absolute bottom-2 end-2 z-10 rounded bg-black/5 px-1.5 py-0.5 text-[10px] text-text-muted">
        Confidential — {text}
      </span>
    </div>
  )
}

function maskEmail(email: string): string {
  const [name, domain] = email.split('@')
  if (!domain) return email
  const shown = name.slice(0, 2)
  return `${shown}${'*'.repeat(Math.max(1, name.length - 2))}@${domain}`
}
