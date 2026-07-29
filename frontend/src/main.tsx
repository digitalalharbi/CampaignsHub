import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router-dom'
import { Providers } from '@/app/providers'
import { router } from '@/app/router'
import { registerServiceWorker } from '@/app/pwa'
import { installDateInputNormalizer } from '@/lib/dateInputs'
// Self-hosted fonts (no external CDN): Inter for Latin, IBM Plex Sans Arabic for Arabic.
import '@fontsource-variable/inter'
import '@fontsource/ibm-plex-sans-arabic/400.css'
import '@fontsource/ibm-plex-sans-arabic/500.css'
import '@fontsource/ibm-plex-sans-arabic/600.css'
import '@fontsource/ibm-plex-sans-arabic/700.css'
import './index.css'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <Providers>
      <RouterProvider router={router} />
    </Providers>
  </StrictMode>,
)

// Install the PWA service worker (production only; no-op in dev to keep HMR intact).
registerServiceWorker()

// Force all native date/time inputs to Gregorian + English (YYYY-MM-DD), system-wide.
installDateInputNormalizer()
