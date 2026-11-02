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
export const PLATFORM_COLORS: Record<string, string> = {
  meta: 'var(--platform-meta)',
  google: 'var(--platform-google)',
  google_ads: 'var(--platform-google)',
  tiktok: 'var(--platform-tiktok)',
  snapchat: 'var(--platform-snapchat)',
  x: 'var(--platform-x)',
  linkedin: 'var(--platform-linkedin)',
  /*
   * ChatGPT Ads, from the same token pair as the rest — never a colour picked here.
   *
   * OpenAI's own mark is near-black on white, which is the exact problem the paragraph above
   * describes for TikTok and X: on a dark ground it is a donut slice nobody can see. The token swaps
   * to the light-on-dark mark, so the platform is legible in both themes without inventing a brand
   * colour that is not theirs.
   */
  openai_ads: 'var(--platform-openai)',
}
export const platformColor = (p: string) => PLATFORM_COLORS[p] ?? 'var(--brand-500)'
