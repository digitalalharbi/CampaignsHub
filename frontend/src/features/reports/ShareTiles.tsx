import {
  Banknote, CheckCircle2, CircleHelp, CreditCard, Landmark, Wallet, Handshake, Keyboard, Leaf, Mail, Megaphone, MessageSquare, Monitor, MoreHorizontal, Network, Play, RotateCcw,
  Search, Share2, ShoppingCart, Smartphone, Tablet, Tv, Users, XCircle, type LucideIcon,
} from 'lucide-react'
import { Num } from '@/components/ui/Num'
import { PLATFORM_ORDER, canonicalPlatform } from '@/lib/platforms'
import { num, percent } from '@/features/analytics/format'

/**
 * WEBSITE-VISUAL-002 (Owner 2026-11-22) — «النصوص يجب أن تكون إبداعية ومتوازية»: a whole split into its parts as a row of equal
 * tiles — an icon, the part's name, its figure and its share as a bar — instead of a coloured strip followed by a line of words.
 * Every tile has the same height and the same places for the same things, so the parts read across like a table without being one.
 */
export interface SharePart { key: string; label: string; value: number; icon?: LucideIcon; note?: string }

export const SHARE_COLORS = ['var(--brand-600)', 'var(--teal)', 'var(--info)', 'var(--purple)', 'var(--warning)', 'var(--success)', 'var(--text-muted)']

/** The icon of a traffic channel or a device, from the server's own key (GA4 channel groups, the store's classes, device kinds). */
export function partIcon(key: string): LucideIcon {
  const k = key.toLowerCase()
  if (k === 'mobile') return Smartphone
  if (k === 'desktop') return Monitor
  if (k === 'tablet') return Tablet
  if (k.includes('tv')) return Tv
  if (k === 'confirmed') return CheckCircle2
  if (k === 'cancelled') return XCircle
  if (k === 'refunded') return RotateCcw
  if (k.includes('video')) return Play
  if (k.includes('shopping')) return ShoppingCart
  if (k.includes('paid') || k === 'display') return Megaphone
  if (k.includes('search')) return Search
  if (k.includes('social')) return Users
  if (k === 'organic') return Leaf
  if (k === 'direct') return Keyboard
  if (k === 'referral') return Share2
  if (k === 'email') return Mail
  if (k === 'sms') return MessageSquare
  if (k === 'affiliates') return Handshake
  if (k.includes('cross')) return Network
  if (k === 'other') return MoreHorizontal
  // STORE-MIRROR-001 — the store's own splits: how people paid, and the ad platform an order came from.
  if (['mada', 'credit_card', 'creditcard', 'apple_pay', 'stc_pay', 'card'].includes(k)) return CreditCard
  if (k === 'cod' || k === 'cash') return Banknote
  if (k === 'bank' || k === 'bank_transfer') return Landmark
  if (['tabby', 'tamara', 'wallet', 'paypal'].includes(k)) return Wallet
  // The ad platforms come from the one canonical list (CanonicalPlatformOrderTest), never a second copy written here.
  if ((PLATFORM_ORDER as readonly string[]).includes(canonicalPlatform(k))) return Megaphone

  return CircleHelp
}

export function ShareTiles({ testId, parts, cols = 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5', tileTestId, colorOf }: {
  testId?: string
  parts: SharePart[]
  cols?: string
  /** A test id per tile, from its key. */
  tileTestId?: (key: string) => string
  colorOf?: (key: string, index: number) => string
}) {
  const shown = parts.filter((p) => p.value > 0).sort((a, b) => b.value - a.value)
  const total = shown.reduce((a, p) => a + p.value, 0)
  if (total === 0) return null

  return (
    <ul data-testid={testId} data-parts={shown.length} className={`grid gap-2 ${cols}`}>
      {shown.map((p, i) => {
        const color = colorOf ? colorOf(p.key, i) : SHARE_COLORS[i % SHARE_COLORS.length]
        const Icon = p.icon ?? partIcon(p.key)
        const share = p.value / total

        return (
          <li key={p.key} data-testid={tileTestId?.(p.key)} title={`${p.label} ${num(p.value)}`} className="flex min-w-0 flex-col gap-1.5 rounded-xl border border-border bg-surface px-3 py-2.5">
            <span className="flex min-w-0 items-center gap-1.5">
              <span className="grid h-6 w-6 shrink-0 place-items-center rounded-lg" style={{ background: `color-mix(in srgb, ${color} 16%, transparent)`, color }}><Icon size={13} aria-hidden /></span>
              <span className="truncate text-xs font-semibold text-text-secondary">{p.label}</span>
            </span>
            <span className="tnum truncate text-lg font-extrabold leading-none text-text-primary" dir="ltr"><Num>{p.note ?? num(p.value)}</Num></span>
            <span className="flex items-center gap-1.5">
              <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-secondary"><span className="block h-full rounded-full" style={{ width: `${Math.max(3, share * 100)}%`, background: color }} /></span>
              <span className="tnum w-8 shrink-0 text-end text-xs font-bold text-text-muted"><Num>{percent(share, 0)}</Num></span>
            </span>
          </li>
        )
      })}
    </ul>
  )
}
