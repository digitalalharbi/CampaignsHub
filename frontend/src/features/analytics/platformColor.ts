/*
 * Platform identities, read from the token layer so each theme can state them.
 *
 * They were literals, and two of them — TikTok `#000000` and X `#111111` — were the brand's true
 * black. On the dark ground that is a donut slice you cannot see, a legend dot that reads as a hole
 * and a bar of zero apparent length, in every screen that draws a platform. The tokens keep the
 * exact brand value in light and swap in each brand's own light-on-dark mark for dark.
 *
 * PERF-RECOVERY-001 — in a file of its own, away from the charts. It lived in `components.tsx`,
 * which imports recharts, so a filter chip that only wanted a colour pulled 400 KB of charting into
 * the entry chunk for every visitor, the sign-in page included. `components.tsx` re-exports it, so
 * every existing import keeps working.
 */
// COLOUR-SYSTEM-001 — the palette now lives in the one shared module; this path keeps every existing import working.
export { PLATFORM_COLORS, platformColor } from '@/lib/colors'
