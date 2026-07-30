/**
 * The single source of truth for where every public journey goes.
 *
 * The hero cards and the closing cards used to decide their own destinations, which is how the closing
 * cards ended up pointing at `#usage` — scrolling back to the top instead of opening the path. Both
 * surfaces now read this table, so a card at the bottom of the page can never disagree with the same
 * card at the top, and a destination is changed in exactly one place.
 *
 * Every entry is a real route. No anchors, no `#`, nothing that lands the visitor back where they were.
 */

export type JourneyKey = 'self-service' | 'multi-client' | 'services' | 'influencer'

export interface Journey {
  key: JourneyKey
  /** Where the card goes. A real path, always. */
  to: string
  /** Copy keys are held in homeCopy/start.paths; this table owns routing only. */
  icon: string
}

export const JOURNEYS: Record<JourneyKey, Journey> = {
  // Self-serve advertiser → register with the paid-media module, carrying the journey through.
  'self-service': { key: 'self-service', to: '/register?journey=self-service&module=paid-media', icon: 'layout-dashboard' },
  // Agency → register with the clients/requests modules.
  'multi-client': { key: 'multi-client', to: '/register?journey=multi-client&module=paid-media', icon: 'users' },
  // Service buyer → the real catalogue page, where choosing a service pre-fills the intake.
  services: { key: 'services', to: '/services', icon: 'megaphone' },
  // Influencer / UGC → the dedicated intake for that module.
  influencer: { key: 'influencer', to: '/requests/new?module=influencer-marketing', icon: 'sparkles' },
}

/** Returning visitors. Kept here so the header, hero and footer cannot drift apart either. */
export const ACCOUNT_ROUTES = {
  login: '/login',
  register: '/register',
  trackRequests: '/portal/login',
  requestService: '/requests/new',
  servicesCatalogue: '/services',
} as const

export function journeyTo(key: string): string {
  return JOURNEYS[key as JourneyKey]?.to ?? ACCOUNT_ROUTES.requestService
}

/** Every destination this page can send a visitor to — used by the routing test to prove none is an anchor. */
export function allJourneyDestinations(): string[] {
  return [...Object.values(JOURNEYS).map((j) => j.to), ...Object.values(ACCOUNT_ROUTES)]
}
