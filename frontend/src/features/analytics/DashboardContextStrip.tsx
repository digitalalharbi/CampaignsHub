import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { AlertTriangle, Plug } from 'lucide-react'
import { fetchConnectionHub } from '@/features/integrations/api'
import { PLATFORM_LABELS, canonicalPlatform, sortPlatforms, type AdPlatform } from '@/lib/platforms'
import { platformColor } from '@/features/analytics/components'
import { Num } from '@/components/ui/Num'
import { useUi } from '@/stores/ui'

/**
 * DASHBOARD-CONTEXT-001 — two questions the first screen could not answer.
 *
 * The head said where you are, whose project it is, which period and how fresh the figures are. It
 * could not say **which platforms are feeding it** or **whether anything needs attention** — so a
 * dashboard reading zero for Snapchat looked identical whether Snapchat was quiet or simply never
 * connected, and the authorisation that had expired an hour ago was three clicks away on another
 * page.
 *
 * ## It states what is connected, not what exists
 *
 * The chips are the tenant's actual connections, not the canonical platform list. A platform the
 * product supports and this workspace has not connected is absent, because the question here is
 * «what is feeding this screen» and answering it with a catalogue would be decoration.
 *
 * ## And it draws nothing when there is nothing to say
 *
 * No connections, no strip — a workspace that has not connected anything is told so by the empty
 * states beneath, which can offer the action. A row of grey placeholders above them would be a
 * second, quieter way of saying the same thing.
 */
export function DashboardContextStrip() {
  const ar = useUi((s) => s.locale) === 'ar'

  /*
   * The same query key the Connection Hub uses, so opening the dashboard after the hub costs
   * nothing and a reconnect made there is reflected here without a reload.
   */
  const hub = useQuery({ queryKey: ['connection-hub'], queryFn: fetchConnectionHub, retry: false })

  const connections = hub.data?.connections ?? []

  if (connections.length === 0) return null

  const providers = sortPlatforms(
    [...new Set(connections.map((c) => canonicalPlatform(c.provider)))],
  )

  const attention = connections.filter((c) => c.needs_attention).length

  return (
    /*
      Inline, in the header's own meta line, rather than a band of its own.
      
      It was a bordered strip under the head, and a reader counting bands before the first figure
      found one more. This is context about the figures, so it belongs on the line that already
      carries the period and the freshness — not above them with a frame around it.
    */
    <span data-testid="dashboard-context-strip" className="flex flex-wrap items-center gap-x-2 gap-y-1">
      <span className="flex items-center gap-1.5 text-[11.5px] font-bold text-text-muted">
        <Plug size={13} aria-hidden /> {ar ? 'يغذّي هذه الصفحة' : 'Feeding this page'}
      </span>

      <ul className="flex flex-wrap items-center gap-1.5">
        {providers.map((key) => (
          <li
            key={key}
            data-testid={`dashboard-context-platform-${key}`}
            className="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface px-2 py-0.5 text-[12px] font-semibold text-text-secondary"
          >
            <span className="h-1.5 w-1.5 rounded-full" style={{ background: platformColor(key) }} aria-hidden />
            {PLATFORM_LABELS[key as AdPlatform]?.[ar ? 'ar' : 'en'] ?? key}
          </li>
        ))}
      </ul>

      {/*
        The count, and the door. A number somebody cannot act on is a worry rather than a fact, and
        the thing to do about an expired authorisation lives on another page.
      */}
      {attention > 0 && (
        <Link
          to="/app/integrations"
          data-testid="dashboard-context-attention"
          className="inline-flex items-center gap-1.5 rounded-full border border-warning/40 bg-[var(--warning-background)] px-2.5 py-0.5 text-[12px] font-bold text-text-primary hover:border-warning"
        >
          <AlertTriangle size={12} aria-hidden />
          {ar ? 'يحتاج انتباه' : 'Needs attention'} <Num>{attention}</Num>
        </Link>
      )}
    </span>
  )
}
