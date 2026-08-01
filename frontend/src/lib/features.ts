/**
 * What the product is OFFERING in this build (INFL-OFF-001).
 *
 * Read this anywhere a surface presents a choice — a marketing card, a sign-in door, a registration
 * option, a rail entry, the portal switcher. One import, so a surface cannot be the one that forgot.
 *
 * **This is presentation, never permission.** The browser deciding not to draw a link has never
 * stopped anybody from typing the URL, and it never will. Every flag here has an enforcing twin in
 * the backend — for the influencers portal that is `Portal::isEnabled()`, read by `EnsurePortal` and
 * by the request intake — and the backend's answer is the one that decides. What this file governs
 * is whether the product ADVERTISES something, not whether it grants it.
 */
export const features = {
  /**
   * Influencer & UGC — off.
   *
   * Not built, not deleted: the portal, its pages, its API and its data are all still here and all
   * still tested. The service is simply not being sold in this release and returns later as its own
   * sub-system, so the flag hides the offer and leaves everything behind it intact.
   */
  influencersUgc: import.meta.env.VITE_INFLUENCERS_UGC === 'true',
} as const

/** Where somebody who reaches the retired influencer routes is sent instead. */
export const INFLUENCERS_FALLBACK = '/services'
