import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router-dom'
import { Providers } from '@/app/providers'
import { router } from '@/app/router'
import { registerServiceWorker } from '@/app/pwa'
import { installDateInputNormalizer } from '@/lib/dateInputs'
import { defaultSink, sampleRate, startWebVitals } from '@/lib/perf/webVitals'
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

// PERF-BASELINE-WEBVITALS-001 — LCP/INP/CLS, OFF unless VITE_WEB_VITALS_SAMPLE (0–1) is set at build time.
startWebVitals({
  rate: sampleRate(import.meta.env.VITE_WEB_VITALS_SAMPLE as string | undefined),
  report: defaultSink(import.meta.env.VITE_WEB_VITALS_ENDPOINT as string | undefined),
})
