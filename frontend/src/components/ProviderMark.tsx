import { canonicalPlatform } from '@/lib/platforms'
import { PlatformMark, hasPlatformMark } from '@/components/brand/PlatformMark'
import { providerLabel } from '@/features/campaigns/labels'

/**
 * REPORT-PLATFORM-ICONS-001 — the platform's own logo, the same everywhere a platform is named.
 *
 * Owner, 2026-10-19: «استبدل أسماء المنصات بأيقونات أو شعارات كل منصة، أو أن تكون الأيقونة واسم المنصة معًا».
 * The mark is the official logo the dashboard's platform filter already ships (`PlatformMark`, simple-icons,
 * stored not fetched), set in a small rounded square in the platform's own muted tint so it reads on a
 * light and a dark page. A platform with no logo falls back to its initials, never a blank.
 *
 * Decorative on its own (hidden from assistive technology): a caller that shows the logo WITHOUT the
 * name must go through `ProviderName`, which keeps the name for screen readers and a hover title.
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
  const key = canonicalPlatform(provider)
  const mark = MARKS[key] ?? FALLBACK

  return (
    <span
      aria-hidden
      data-testid="provider-mark"
      data-provider={key}
      data-logo={hasPlatformMark(key) ? 'true' : 'false'}
      style={{ width: size, height: size, fontSize: Math.round(size * 0.46) }}
      className={`inline-flex shrink-0 items-center justify-center rounded-md font-bold leading-none ${mark.tone} ${className}`}
    >
      {hasPlatformMark(key) ? <PlatformMark platform={key} size={Math.round(size * 0.62)} /> : mark.letters}
    </span>
  )
}

/**
 * The platform as a reader meets it in a report: its logo and its name — or the logo alone where the room
 * is tight (`logoOnly`), in which case the name is kept as the accessible name and the hover title, so a
 * reader who cannot see the logo, or does not know the brand, loses nothing.
 */
export function ProviderName({
  provider,
  locale,
  size = 18,
  logoOnly = false,
  className = '',
}: {
  provider: string
  locale: 'ar' | 'en'
  size?: number
  logoOnly?: boolean
  className?: string
}) {
  const name = providerLabel(canonicalPlatform(provider), locale)

  return (
    <span data-testid="provider-name" title={logoOnly ? name : undefined} className={`inline-flex min-w-0 items-center gap-1.5 ${className}`}>
      <ProviderMark provider={provider} size={size} />
      <span className={logoOnly ? 'sr-only' : 'truncate'}>{name}</span>
    </span>
  )
}
