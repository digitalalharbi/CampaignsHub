/**
 * Central brand identity for the SPA. Values come from Vite env (build-time) with CampaignsHub
 * defaults; the marketing/app can also hydrate from `GET /api/v1/brand` at runtime. Never hard-code
 * the brand name in components — import from here.
 */
export const brand = {
  name: import.meta.env.VITE_BRAND_NAME ?? 'CampaignsHub',
  /**
   * BRAND-LOCKUP-001 — the words that belong to the LOGO, which are not the product's tagline.
   *
   * The identity file sets the lockup as «CampaignsHub · PAID MEDIA IN ONE PLACE» and
   * «كامبينز هب · منصة إدارة الحملات المدفوعة». `tagline` below is a different thing: the sentence
   * the product is sold and described by, pinned by BRAND-001 to match `config/brand.php`.
   *
   * Kept as two fields on purpose. Collapsing them would either put the logo's line into the title
   * tag and the sign-in panel, or put the product sentence under the mark — and one of the two
   * would then be wrong everywhere it appears.
   */
  lockup: {
    nameEn: 'CampaignsHub',
    nameAr: 'كامبينز هَب',
    lineEn: 'PAID MEDIA IN ONE PLACE',
    lineAr: 'منصة إدارة الحملات المدفوعة',
  },
  domain: import.meta.env.VITE_BRAND_DOMAIN ?? 'campaignshub.io',
  /*
   * The OFFICIAL tagline — BRAND-001, and it must match `config/brand.php` exactly.
   *
   * «كل حملاتك الإعلانية المدفوعة في مكان واحد» is the sentence this product is sold on. It lived in
   * eight code comments explaining decisions and in one marketing heading, while the value here was
   * a different sentence in each language — so the title tag, the Open Graph card and the sign-in
   * panel each said something the product does not call itself.
   */
  tagline: 'All your paid campaigns in one place',
  taglineAr: 'كل حملاتك الإعلانية المدفوعة في مكان واحد',
  /** What the product IS, for a description field rather than a headline. Plain and checkable. */
  description: 'One platform to run, monitor and analyse paid advertising across every ad platform.',
  descriptionAr: 'منصة موحدة لإدارة ومتابعة وتحليل الحملات الإعلانية المدفوعة عبر جميع المنصات من مكان واحد.',
  urls: {
    marketing: 'https://campaignshub.io',
    app: 'https://app.campaignshub.io',
    docs: 'https://docs.campaignshub.io',
    status: 'https://status.campaignshub.io',
  },
  supportEmail: 'info@campaignshub.io',
} as const

/**
 * BRAND-CANONICAL-001 — the ONE place that decides what the product is called, given a language.
 *
 * The name was resolved independently in a dozen places: `brand.lockup.nameAr` in one shell, the
 * literal `'CampaignsHub'` as a fallback in four more, and an `app_name` key duplicated into BOTH
 * halves of the i18n dictionary with the English spelling on the Arabic side — so an Arabic account
 * read «CampaignsHub» wherever a surface happened to use that key instead of the lockup.
 *
 * Every caller asks this instead. A fallback is still the product identity, and a fallback written
 * as a string literal is the identity spelled by hand in a place nobody looks.
 */
export function productName(locale: string | undefined): string {
  return locale === 'ar' ? brand.lockup.nameAr : brand.lockup.nameEn
}

/** The lockup's own line, which is NOT the marketing tagline — see the note on `lockup` above. */
export function productLockupLine(locale: string | undefined): string {
  return locale === 'ar' ? brand.lockup.lineAr : brand.lockup.lineEn
}
