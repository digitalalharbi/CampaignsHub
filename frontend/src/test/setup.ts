import '@testing-library/jest-dom/vitest'
import { afterEach } from 'vitest'
import { cleanup } from '@testing-library/react'

// jsdom lacks ResizeObserver, which recharts' ResponsiveContainer relies on. Polyfill a no-op so
// chart-bearing components (KPI cards, performance panels) render in tests instead of throwing.
if (!('ResizeObserver' in globalThis)) {
  ;(globalThis as { ResizeObserver?: unknown }).ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  }
}

// Unmount React trees between tests so queries don't leak across cases.
afterEach(() => cleanup())
