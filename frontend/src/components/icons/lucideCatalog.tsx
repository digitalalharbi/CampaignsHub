import type { ComponentType, ReactNode } from 'react'
import * as LucideIcons from 'lucide-react'

/**
 * The whole lucide catalogue, resolved by NAME — loaded on demand through `LucideByName`.
 *
 * `import * as LucideIcons` defeats tree-shaking by construction: the bundler cannot know which of
 * the ~1,600 icons a taxonomy option will name at runtime, so it keeps every one. That is correct
 * and unavoidable for a name-addressed icon; what was wrong was WHERE it happened. Four components
 * each held the namespace import, and the forms primitives were one of them — so every page with a
 * select on it (the dashboard, campaigns, reports, settings…) shipped a 600 KB chunk to draw a
 * 14-pixel glyph that most options do not carry.
 *
 * This module is the only namespace import now, and nothing imports it statically: `LucideByName`
 * reaches it through `import()`, so the catalogue is one chunk that arrives the first time a named
 * icon is actually rendered, and never on a page that renders none.
 */
export type LucideNamed = ComponentType<{ size?: number; className?: string }>

export function lucideByName(name: string): LucideNamed | null {
  const pascal = name.split(/[-_ ]/).filter(Boolean).map((part) => part[0].toUpperCase() + part.slice(1)).join('')
  const Resolved = (LucideIcons as unknown as Record<string, unknown>)[pascal]
  // Lucide icons are forwardRef objects, not plain functions — checking only for `function` silently
  // dropped every icon to the fallback glyph.
  const isComponent =
    typeof Resolved === 'function' ||
    (typeof Resolved === 'object' && Resolved !== null && '$$typeof' in (Resolved as object))

  return isComponent ? (Resolved as LucideNamed) : null
}

/** The loaded half of `LucideByName`: resolves the name and draws the icon, or what the caller wants instead. */
export function LucideByNameLoaded({
  name,
  render,
  fallback,
}: {
  name: string
  render: (Icon: LucideNamed) => ReactNode
  fallback: ReactNode
}) {
  const Icon = lucideByName(name)

  return <>{Icon === null ? fallback : render(Icon)}</>
}
