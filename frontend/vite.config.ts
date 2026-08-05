import { fileURLToPath, URL } from 'node:url'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

const API_TARGET = process.env.VITE_API_TARGET ?? 'http://127.0.0.1:8000'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    proxy: {
      /*
       * Proxy API calls to the Laravel backend during development.
       *
       * The target is overridable because the E2E gate runs its own backend on :8100 against its own
       * database (E2E-ISO-001). Hard-coding :8000 here would send the gate's requests to whatever
       * dev server happened to be listening — i.e. to the development database, which is the exact
       * leak that isolation removes. `playwright.config.ts` sets `VITE_API_TARGET`; a developer's
       * `npm run dev` sets nothing and keeps the default.
       */
      '/api': { target: API_TARGET, changeOrigin: true },
      '/sanctum': { target: API_TARGET, changeOrigin: true },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: './src/test/setup.ts',
    css: false,
    // Playwright e2e specs live in ./e2e and run via `npx playwright test`, not vitest.
    exclude: ['e2e/**', 'node_modules/**', 'dist/**'],
  },
})
