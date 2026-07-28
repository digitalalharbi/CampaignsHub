import type { ReactElement } from 'react'
import { DrivePage } from './DrivePage'

/**
 * Route fragment for the Google Drive links page. The orchestrator spreads this into the authenticated
 * AppShell children (relative paths), so the resulting URL is /app/drive. We DO NOT edit router.tsx.
 */
export const driveRoutes: { path: string; element: ReactElement }[] = [
  { path: 'app/drive', element: <DrivePage /> },
]
