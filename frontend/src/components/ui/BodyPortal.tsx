import type { ReactNode } from 'react'
import { createPortal } from 'react-dom'

/**
 * MOBILE-DIALOG-001 — a dialog rendered where the page put it inherits that place's stacking context: inside a report
 * section it sat UNDER the sticky report header on a phone, and its close button was covered. Rendered on <body>, the
 * dialog's own z-index is the only one that decides.
 */
export function BodyPortal({ children }: { children: ReactNode }) {
  if (typeof document === 'undefined') return <>{children}</>
  return createPortal(children, document.body)
}
