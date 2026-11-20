import { canonicalPlatform } from '@/lib/platforms'
import type { LivePayload } from '@/features/reports/api'
import type { ReportAd } from '@/features/reports/ReportAdsSection'

/**
 * «مرتّبة على أداء المبيعات» — which objective the headline order rests on, when the scope mixed several. The line names the FAMILY
 * (sales, traffic, awareness…) and not the metric the server ranked on. A single-objective scope needs no line.
 */
export function basisLine(basis: LivePayload['ads_basis'], ar: boolean): string | null {
  // The objective's result was not reported: say that, plainly, instead of naming a family the order does not rest on.
  if (basis?.fallback && basis.metric) {
    const note = ar ? basis.note_ar : basis.note_en
    if (note) return note
  }
  if (!basis || !basis.metric || !basis.family) return null
  const family = (ar ? basis.family_label_ar : basis.family_label_en) ?? basis.family

  return ar ? `مرتّبة على أداء ${family}` : `Ranked by ${family.toLowerCase()} performance`
}

export type ContentKind = 'best' | 'improve'

/**
 * Best and opportunity content, ranked on the objective's own indicator by the server (`ads`, `ads_weakest`). A creative among the best is never
 * also listed as one to improve, and a platform filter keeps only that platform's content.
 */
export function contentLists(payload: LivePayload, kind: ContentKind, platform: string | null, limit = 4): ReportAd[] {
  const all = (kind === 'best' ? payload.ads : payload.ads_weakest) ?? []
  const best = new Set((payload.ads ?? []).slice(0, 4).map((a) => a.content_key).filter(Boolean))

  return all
    .filter((a) => kind === 'best' || !best.has(a.content_key))
    .filter((a) => platform === null || (a.provider && canonicalPlatform(a.provider) === platform))
    .slice(0, limit)
}
