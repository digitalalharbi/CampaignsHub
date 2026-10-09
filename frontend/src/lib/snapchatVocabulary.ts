/**
 * SNAP-OCT26 — the words Snapchat uses in 2026, mirrored from the server's SnapchatVocabulary.
 *
 * A placement token the product has not named is shown as the token itself — never hidden, never
 * guessed at. The AI-media declaration is only ever what the platform stated.
 */
export const SNAPCHAT_PLACEMENTS: Record<string, { ar: string; en: string }> = {
  FEED: { ar: 'الخلاصة', en: 'Feed' },
  CHAT_FEED: { ar: 'خلاصة الدردشة', en: 'Chat Feed' },
  INTERSTITIAL_USER: { ar: 'بين قصص المستخدمين', en: 'Between user stories' },
  INTERSTITIAL_CONTENT: { ar: 'بين محتوى الناشرين', en: 'Between publisher content' },
  SPOTLIGHT: { ar: 'سبوتلايت', en: 'Spotlight' },
  CAMERA: { ar: 'الكاميرا', en: 'Camera' },
}

export function snapchatPlacementLabel(token: string, ar: boolean): string {
  const words = SNAPCHAT_PLACEMENTS[token.toUpperCase()]
  return words ? (ar ? words.ar : words.en) : token
}

export const PLACEMENT_CONFIG: Record<string, { ar: string; en: string }> = {
  automatic: { ar: 'مواضع تلقائية', en: 'Automatic placements' },
  custom: { ar: 'مواضع مخصّصة', en: 'Custom placements' },
}

/** The targeting chips an ad set shows: the placement keys are translated, everything else is shown as stored. */
export function targetingChip(key: string, value: unknown, ar: boolean): { label: string; value: string } | null {
  if (key === 'placements' && Array.isArray(value)) {
    // An empty list under automatic placements is not a fact to print; the mode chip says «automatic».
    if (value.length === 0) return null
    return { label: ar ? 'المواضع' : 'Placements', value: value.map((v) => snapchatPlacementLabel(String(v), ar)).join(' · ') }
  }
  if (key === 'placement_config' && typeof value === 'string') {
    const words = PLACEMENT_CONFIG[value.toLowerCase()]
    return { label: ar ? 'نمط المواضع' : 'Placement mode', value: words ? (ar ? words.ar : words.en) : value }
  }
  return { label: key, value: Array.isArray(value) ? value.join(' · ') : String(value) }
}
