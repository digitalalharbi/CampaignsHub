import { getData } from '@/lib/api/client'
import {
  libraryQueryString,
  type CreativeCard,
  type CreativeMetrics,
  type FatigueStatus,
  type LibraryFilterOptions,
  type LibraryQuery,
} from './api'

/**
 * §15.11 — the dashboard's creative section.
 *
 * `GET /creatives/pulse` takes the SAME query as the library: the same filters, the same window, the
 * same membership ceiling. That is deliberate and it is the whole point of the endpoint — a
 * dashboard section on its own query is one that can disagree with the page its cards link into.
 *
 * Every ranking below carries the `metric` it used and the `path` it was computed inside. Neither is
 * decoration: «best video» with no metric named is a verdict nobody can check, and one computed
 * across marketing paths would be judging an awareness film by a sales figure.
 */

/** A winner on one axis, and how much evidence stood behind it. */
export interface CreativeWinner {
  metric: string
  higher_wins: boolean
  value: number
  /** How many creatives had the metric at all. */
  candidates: number
  /** How many of those cleared the impressions floor. */
  evidenced: number
  /** True when NONE did — the answer is still given, and marked as thin. */
  low_evidence: boolean
  creative: CreativeCard
}

export interface ObjectiveWinner extends CreativeWinner {
  objective: string | null
  path: string
  creatives: number
  spend: number | null
}

export interface KindWinner extends CreativeWinner {
  kind: string
  path: string
  creatives: number
  spend: number | null
}

export interface CreativeMove {
  metric: string
  higher_wins: boolean
  current: number
  previous: number
  /** The raw movement. Negative is an improvement when `higher_wins` is false. */
  change: number
  /** The movement re-signed so positive always means better. */
  improvement: number
  creative: CreativeCard
}

/** A list section and its honest total — `items` is what fits, `total` is what there was. */
export interface PulseList<T> {
  items: T[]
  total: number
  shown: number
}

export interface FatigueAlert {
  creative: CreativeCard
  spend: number
  signals: Array<{ key: string; direction: string; change: number }>
  note_ar: string | null
  note_en: string | null
}

export interface SpendByKind {
  kind: string
  spend: number | null
  /** Null when nothing reported spend — a share of nothing is undefined, not 0%. */
  share: number | null
  creatives: number
  spend_not_reported: number
}

export interface PathComparison {
  path: string
  headline_metrics: string[]
  image: CreativeMetrics | null
  video: CreativeMetrics | null
}

export interface PlatformComparison {
  group_id: string
  name: string | null
  path: string
  metric: string
  higher_wins: boolean
  /** Null when the best value is shared — a tie is «the platform made no difference», not a winner. */
  winner: string | null
  tied: boolean
  low_evidence: boolean
  platforms: Array<{
    creative_id: string
    provider: string
    value: number | null
    spend: number | null
    evidenced: boolean
  }>
}

export interface CreativePulse {
  period: { from: string; to: string; days: number }
  previous_period: { from: string; to: string }
  totals: { creatives: number; with_metrics: number; without_metrics: number }
  evidence: { min_impressions: number; min_change: number }
  best_by_objective: ObjectiveWinner[]
  /** One entry per marketing path, heaviest spend first — never one winner across all of them. */
  best_image: KindWinner[]
  best_video: KindWinner[]
  fastest_growing: PulseList<CreativeMove>
  declining: PulseList<CreativeMove>
  fatigue: {
    counts: Record<FatigueStatus, number>
    fatigued: PulseList<CreativeCard>
    watch: PulseList<CreativeCard>
    insufficient_data: PulseList<CreativeCard>
    /** Fatigued AND still spending — the version of the fact that names something to do. */
    alerts: PulseList<FatigueAlert>
    spend_at_risk: number
  }
  spend_by_kind: SpendByKind[]
  image_vs_video: PathComparison[]
  best_platform: PulseList<PlatformComparison>
  /** The same options the library's filter bar offers — derived from the rows in reach, not an enum. */
  filters: LibraryFilterOptions
  freshness: {
    last_synced_at: string | null
    providers: Array<{
      provider: string
      creatives: number
      with_metrics: number
      without_metrics: number
      last_synced_at: string | null
    }>
    quality: Record<string, number>
  }
}

export const getCreativePulse = (query: LibraryQuery, projectId?: string | null) =>
  getData<CreativePulse>(
    `${projectId ? `/projects/${projectId}/creatives` : '/creatives'}/pulse${libraryQueryString(query)}`,
  )
