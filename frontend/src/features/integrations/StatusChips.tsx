import { Badge } from '@/components/ui/Badge'
import { STATUS_DIMENSIONS, type IntegrationStatuses, type StatusDimension } from './api'

type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral'

/**
 * INTEG-STATUS-MODEL-001 / INTEG-UNIFIED-002 (contract §1.13) — the five statuses, as five chips.
 *
 * ## Why five and never one
 *
 * Authorisation, binding, sync, freshness and error are separate facts. A merged chip has to rank
 * them, and on Production the ranking lost: a running sync buried a refused grant. Five chips cannot
 * do that to each other, and there is deliberately no sixth that says «fine».
 *
 * ## Whose words
 *
 * The state's words come from the SERVER (`label_ar` / `label_en`). This file owns only the name of
 * each dimension and a colour — the two things a status object cannot carry about itself.
 */
export const DIMENSION_COPY: Record<StatusDimension, { ar: string; en: string }> = {
  authorisation: { ar: 'التفويض', en: 'Authorisation' },
  binding: { ar: 'الربط', en: 'Link' },
  sync: { ar: 'المزامنة', en: 'Sync' },
  freshness: { ar: 'حداثة البيانات', en: 'Freshness' },
  error: { ar: 'الخطأ', en: 'Error' },
}

const TONES: Record<StatusDimension, Record<string, Tone>> = {
  authorisation: {
    connected: 'success',
    expiring: 'warning',
    reauth_required: 'danger',
    scope_not_granted: 'danger',
    access_lost: 'danger',
    api_key_invalid: 'danger',
    revoked: 'danger',
  },
  binding: { bound: 'success', discovered_unbound: 'warning' },
  sync: { succeeded: 'success', running: 'info', partial: 'warning', failed: 'danger' },
  freshness: { fresh: 'success', stale: 'warning', partial: 'warning', withheld: 'warning' },
  // «No error» is the absence of something, so it is quiet rather than green.
  error: { attention: 'warning', error: 'danger' },
}

/** A state this file has not met is drawn neutral: an unknown word is never dressed as good or bad. */
export function statusTone(dimension: StatusDimension, state: string): Tone {
  return TONES[dimension][state] ?? 'neutral'
}

export function StatusChips({ statuses, ar, id, legacyIds = {} }: {
  statuses: IntegrationStatuses
  ar: boolean
  /** Suffix of every chip's test id: `status-{dimension}-{id}`. */
  id: string
  /** A second test id for a dimension that replaced an older chip, so what read that chip still finds it. */
  legacyIds?: Partial<Record<StatusDimension, string>>
}) {
  return (
    <ul className="flex min-w-0 flex-wrap items-center gap-1.5" data-testid={`status-chips-${id}`} aria-label={ar ? 'الحالات' : 'Statuses'}>
      {STATUS_DIMENSIONS.map((dimension) => {
        const status = statuses[dimension]
        const action = status.action === null ? null : (ar ? status.action.label_ar : status.action.label_en)

        return (
          <li key={dimension} className="min-w-0" data-testid={legacyIds[dimension]} title={action ?? undefined}>
            <Badge tone={statusTone(dimension, status.state)} data-testid={`status-${dimension}-${id}`} className="max-w-full">
              <span className="font-semibold opacity-80">{ar ? DIMENSION_COPY[dimension].ar : DIMENSION_COPY[dimension].en}:</span>
              {' '}
              <span className="truncate" data-state={status.state}>{ar ? status.label_ar : status.label_en}</span>
            </Badge>
          </li>
        )
      })}
    </ul>
  )
}
