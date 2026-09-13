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
    nameAr: 'كامبينز هب',
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
