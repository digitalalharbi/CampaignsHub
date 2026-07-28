import type { ReactElement } from 'react'
import { JourneyDemoPage } from './JourneyDemoPage'

/**
 * Route fragment for the internal Request Journey tool. The reusable control lives in JourneyControl (mount it
 * on a request detail); this standalone demo page exercises it end-to-end. The app orchestrator imports this
 * array and mounts it under the authenticated AppShell (it also owns the nav). Paths are absolute under /app.
 */
export const requestJourneyRoutes: { path: string; element: ReactElement }[] = [
  { path: 'app/request-journey', element: <JourneyDemoPage /> },
]
