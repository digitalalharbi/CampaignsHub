import { readProviderError } from './providerError'
import type { Locale } from '@/stores/ui'

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §15 — the failure, said once, to the person who can act on it.
 *
 * The instruction is the visible part and the provider's own sentence is one press away. Both are
 * needed and they are needed by different people: the customer acts on the first, and whoever
 * diagnoses it — support, the operator, the team — needs the second exactly as the provider wrote it.
 *
 * `<details>` rather than a modal or a tooltip: it is keyboard-reachable, it prints, it survives
 * copy-and-paste into a support thread, and it costs no state.
 */
export function ProviderErrorNote({
  error,
  locale,
  testId,
}: {
  error: string | null | undefined
  locale: Locale
  testId: string
}) {
  const reading = readProviderError(error, locale)
  if (reading === null) return null

  const ar = locale === 'ar'

  return (
    <div data-testid={testId} data-category={reading.category} data-actor={reading.actor} className="space-y-1">
      <p className={reading.actor === 'nobody' ? 'text-text-secondary' : 'text-danger'}>{reading.message}</p>

      <details className="text-[11px] text-text-muted">
        <summary className="cursor-pointer select-none">{ar ? 'التفاصيل التقنية' : 'Technical details'}</summary>
        {/*
          `dir="ltr"` because the provider's message is machine text — a URN, a field list, a schema
          name — and rendering it right-to-left in an Arabic page reorders the punctuation until it is
          no longer the string anybody can search for.
        */}
        <pre
          dir="ltr"
          data-testid={`${testId}-raw`}
          className="mt-1 overflow-x-auto whitespace-pre-wrap break-words rounded-lg bg-surface-secondary p-2 text-left font-mono"
        >
          {reading.raw}
        </pre>
      </details>
    </div>
  )
}
