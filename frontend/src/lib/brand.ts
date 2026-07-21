/**
 * Central brand identity for the SPA. Values come from Vite env (build-time) with CampaignsHub
 * defaults; the marketing/app can also hydrate from `GET /api/v1/brand` at runtime. Never hard-code
 * the brand name in components — import from here.
 */
export const brand = {
  name: import.meta.env.VITE_BRAND_NAME ?? 'CampaignsHub',
  domain: import.meta.env.VITE_BRAND_DOMAIN ?? 'campaignshub.io',
  tagline: 'Run every client, project, and campaign from one place.',
  taglineAr: 'أدر كل عميل ومشروع وحملة من مكان واحد.',
  urls: {
    marketing: 'https://campaignshub.io',
    app: 'https://app.campaignshub.io',
    docs: 'https://docs.campaignshub.io',
    status: 'https://status.campaignshub.io',
  },
  supportEmail: 'support@campaignshub.io',
} as const
