import type { ReactElement } from 'react'
import { BrandingCenterPage } from './BrandingCenterPage'

/**
 * Route fragment for the Branding Center. The orchestrator spreads this into the authenticated AppShell
 * children (which use relative paths), so the resulting URL is /app/branding. We DO NOT edit router.tsx.
 */
export const brandingRoutes: { path: string; element: ReactElement }[] = [
  { path: 'app/branding', element: <BrandingCenterPage /> },
]
