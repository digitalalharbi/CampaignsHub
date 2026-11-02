import { lazy, Suspense, type ReactNode } from 'react'
import type { LucideNamed } from './lucideCatalog'

/**
 * A lucide icon addressed by its NAME («rocket», «calendar-check»), as the taxonomy stores them.
 *
 * The catalogue behind it is the one namespace import of `lucide-react` left in the product, and it
 * loads through `import()` the first time a named icon is drawn — see `lucideCatalog.tsx` for why.
 * Until it arrives nothing is drawn (a 14-pixel glyph a few milliseconds late is not a state worth a
 * skeleton); an unknown name renders `fallback`, exactly as the four resolvers this replaces did.
 */
const Loaded = lazy(() => import('./lucideCatalog').then((m) => ({ default: m.LucideByNameLoaded })))

export function LucideByName({
  name,
  render,
  fallback = null,
}: {
  name: string
  /** How to draw the resolved icon — the wrapper and the size are the caller's. */
  render: (Icon: LucideNamed) => ReactNode
  /** What to draw when the name is not an icon. */
  fallback?: ReactNode
}) {
  return (
    <Suspense fallback={null}>
      <Loaded name={name} render={render} fallback={fallback} />
    </Suspense>
  )
}
