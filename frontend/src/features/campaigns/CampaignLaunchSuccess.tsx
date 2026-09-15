import { useEffect, useState } from 'react'
import { useNavigate, useLocation } from 'react-router-dom'
import { Check } from 'lucide-react'
import { SuccessMoment } from '@/components/success/SuccessMoment'
import { portalBaseOf } from './CampaignLink'
import { campaignStatusLabel, objectiveLabel, providerLabel } from './labels'
import type { LaunchOutcome, LaunchPlatform } from './launch'
import { fmtDateTime } from '@/lib/datetime'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'

/**
 * LAUNCH-SUCCESS-001 — the launch moment, wired to the launch that happened.
 *
 * Everything on screen is read off `outcome`, which only {@link readLaunchOutcome} can produce and
 * only from an activate response the server answered `active` to. This component has no fallback
 * headline and no clock: given nothing, it renders nothing.
 *
 * ## Two outcomes, two screens
 *
 * A partial launch is not a success with a footnote. It gets its own headline, its own tone, and it
 * names the platforms that are not live with the status each one reported — because "live" and
 * "live except Google, which is still in review" lead to different next moves, and a product that
 * blurs them has told the operator their work is running when part of it is not.
 *
 * The campaign is already open behind this dialog, so the primary action goes where the operator
 * actually wants to be next: the performance tab, on the canonical campaign route for whichever
 * portal they are in.
 *
 * ## After the dialog
 *
 * Closing it leaves a small chip for a few seconds. Dismissing a confirmation should not feel like
 * undoing it, and the chip is what carries the fact across the gap between «I saw it» and the page
 * catching up with the new status.
 */
export function CampaignLaunchSuccess({ outcome, onDismiss }: {
  outcome: LaunchOutcome | null
  /** Clears the launch upstream once the chip has had its few seconds. */
  onDismiss: () => void
}) {
  const t = useT()
  const locale = useUi((s) => s.locale)
  const navigate = useNavigate()
  const location = useLocation()

  /*
   * The dialog re-opens only when a DIFFERENT launch arrives. Keyed on the campaign plus the
   * server's activation timestamp, so re-rendering — or a query settling underneath — cannot make
   * the same launch celebrate itself twice.
   */
  const key = outcome === null ? null : `${outcome.campaign_id}:${outcome.activated_at ?? ''}`
  const [seen, setSeen] = useState<string | null>(null)
  const [chip, setChip] = useState(false)

  useEffect(() => {
    if (!chip) return undefined
    const timer = window.setTimeout(() => { setChip(false); onDismiss() }, 6000)

    return () => window.clearTimeout(timer)
  }, [chip, onDismiss])

  if (outcome === null || key === null) return null

  const close = () => { setSeen(key); setChip(true) }

  if (seen === key) {
    if (!chip) return null

    return (
      <div
        data-testid="launch-success-chip"
        role="status"
        className="fixed bottom-4 end-4 z-[110] flex items-center gap-2 rounded-full border border-border bg-surface px-3.5 py-2 text-xs font-semibold text-text-primary shadow-lg motion-safe:animate-[riseIn_220ms_ease-out]"
      >
        <Check size={14} className={outcome.outcome === 'partial' ? 'text-warning' : 'text-success'} />
        <span className="max-w-[16rem] truncate">{t('launch_toast')} · {outcome.name}</span>
      </div>
    )
  }

  const partial = outcome.outcome === 'partial'
  const base = portalBaseOf(location.pathname) ?? '/app'
  const detail = `${base}/campaigns/${outcome.project_id}/${outcome.campaign_id}`

  /*
   * A clean launch lists what is carrying it. A partial one lists only what is NOT, since that is
   * the part with something left to do — and it keeps the platform's own word for why.
   */
  const shown: LaunchPlatform[] = partial ? outcome.platforms.filter((p) => !p.live) : outcome.platforms
  // The platform's status in the reader's language — the raw provider word is not a sentence.
  const badges = shown.map((p) => (partial
    ? `${providerLabel(p.provider, locale)} · ${campaignStatusLabel(p.status, locale)}`
    : providerLabel(p.provider, locale)))

  const facts: Array<{ label: string; value: string; ltr?: boolean }> = [
    { label: t('objective_label'), value: objectiveLabel(outcome.objective, locale) },
  ]
  if (outcome.total_budget !== null) {
    facts.push({
      label: t('budget_label'),
      value: `${outcome.total_budget.toLocaleString('en-US')} ${outcome.budget_currency}`,
      ltr: true,
    })
  }
  if (outcome.platforms_total > 0) {
    // Latin digits on both sides of the slash, in either language (NP-002).
    facts.push({ label: t('launch_platforms_live'), value: `${outcome.platforms_live}/${outcome.platforms_total}`, ltr: true })
  }

  return (
    <SuccessMoment
      open
      onClose={close}
      testid="launch-success"
      tone={{ kind: partial ? 'mixed' : 'success' }}
      headline={partial ? t('launch_partial_title') : t('launch_ok_title')}
      subject={outcome.name}
      badges={badges}
      facts={facts}
      // The server's timestamp, formatted — not a time this component decided.
      at={outcome.activated_at !== null ? fmtDateTime(outcome.activated_at) : null}
      atLabel={t('launch_at')}
      note={partial ? t('launch_partial_note') : undefined}
      primary={{ label: t('launch_view_analysis'), onClick: () => { close(); navigate(`${detail}?tab=performance`) } }}
      secondary={{ label: t('launch_back_to_campaigns'), onClick: () => { close(); navigate(`${base}/campaigns`) } }}
    />
  )
}
