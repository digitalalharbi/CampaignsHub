import { Suspense, type ComponentProps } from 'react'
import { lazyWithReload } from '@/lib/chunkReload'
import type { StoreAnalyticsBlock as Block } from './StoreAnalyticsBlock'

/*
 * «تحليلات المتجر والزيارات» loads in a chunk of its own, only where it is drawn: inside the shared report's first download it put
 * `/r/` over its bundle budget (src/build/bundleBudget.ts). The print route awaits `loadStoreAnalytics` before it signals readiness.
 */
export const loadStoreAnalytics = () => import('./StoreAnalyticsBlock')
const Lazy = lazyWithReload(() => loadStoreAnalytics().then((m) => ({ default: m.StoreAnalyticsBlock })))

export function StoreAnalyticsBlock(props: ComponentProps<typeof Block>) {
  return <Suspense fallback={<div aria-hidden style={{ minHeight: 320 }} />}><Lazy {...props} /></Suspense>
}
