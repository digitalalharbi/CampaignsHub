import { canonicalPlatform } from '@/lib/platforms'

/**
 * REPORT-PLATFORM-ICONS-001 — one restrained mark per ad platform, the same everywhere a platform is named.
 *
 * A small rounded square carrying the platform's initials in its own muted tint: enough to find a row
 * at a glance in a table of seven, quiet enough that it never competes with the figures beside it. It is
 * decorative — the platform's NAME always sits next to it — so it is hidden from assistive technology
 * rather than announced twice.
 *
 * These are not the platforms' trademarked logos: shipping those needs each vendor's brand-asset terms,
 * and a half-correct logo in a client's report is worse than a clean neutral mark.
 */
const MARKS: Record<string, { letters: string; tone: string }> = {
  snapchat: { letters: 'Sn', tone: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' },
  tiktok: { letters: 'Tt', tone: 'bg-zinc-200 text-zinc-800 dark:bg-zinc-500/20 dark:text-zinc-200' },
  meta: { letters: 'Me', tone: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300' },
  google: { letters: 'Go', tone: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' },
  x: { letters: 'X', tone: 'bg-neutral-200 text-neutral-900 dark:bg-neutral-500/20 dark:text-neutral-100' },
  linkedin: { letters: 'In', tone: 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300' },
  openai_ads: { letters: 'Ai', tone: 'bg-teal-100 text-teal-800 dark:bg-teal-500/15 dark:text-teal-300' },
}

const FALLBACK = { letters: '··', tone: 'bg-surface-secondary text-text-muted' }

export function ProviderMark({ provider, size = 20, className = '' }: { provider: string; size?: number; className?: string }) {
  const mark = MARKS[canonicalPlatform(provider)] ?? FALLBACK

  return (
    <span
      aria-hidden
      data-testid="provider-mark"
      data-provider={canonicalPlatform(provider)}
      style={{ width: size, height: size, fontSize: Math.round(size * 0.46) }}
      className={`inline-flex shrink-0 items-center justify-center rounded-md font-bold leading-none ${mark.tone} ${className}`}
    >
      {mark.letters}
    </span>
  )
}
