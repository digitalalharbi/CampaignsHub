/**
 * AI-CONNECT-EASY-001 — the words and links of «اربط مساعدك الذكي», kept apart from the components that draw them.
 *
 * Written for someone who has never heard of MCP: one link to copy, the few taps inside Claude or ChatGPT, then «سماح» on
 * our own sign-in page. No tokens, no commands. The developer route (tokens, Claude Code, Cursor…) stays further down the
 * page for the people who want it.
 */

export const MCP_URL = 'https://mcp.campaignshub.io'
export const MCP_HOST = 'mcp.campaignshub.io'

export type Assistant = 'claude' | 'chatgpt' | 'gemini'

export interface AssistantGuide {
  key: Assistant
  label: string
  /** Where the person goes to paste the link. */
  open: string | null
  /** The taps, in the order they make them. Short, the button names as the app shows them. */
  steps: { ar: string; en: string }[]
  note: { ar: string; en: string } | null
  ready: boolean
}

export const ASSISTANTS: AssistantGuide[] = [
  {
    key: 'claude',
    label: 'Claude',
    open: 'https://claude.ai/settings/connectors',
    steps: [
      { ar: 'افتح Claude ← الإعدادات ← «Connectors».', en: 'Open Claude → Settings → «Connectors».' },
      { ar: 'اضغط «Add custom connector».', en: 'Press «Add custom connector».' },
      { ar: 'الاسم: كامبينز هب — والرابط: الصق الرابط اللي نسخته، ثم «Add».', en: 'Name: CampaignsHub — URL: paste the link you copied, then «Add».' },
      { ar: 'اضغط «Connect»، سجّل دخولك واضغط «سماح». خلاص!', en: 'Press «Connect», sign in and press «Allow». Done!' },
    ],
    note: { ar: 'يشتغل في موقع Claude وتطبيق الجوال والكمبيوتر.', en: 'Works on Claude web, mobile and desktop.' },
    ready: true,
  },
  {
    key: 'chatgpt',
    label: 'ChatGPT',
    open: 'https://chatgpt.com/',
    steps: [
      { ar: 'افتح ChatGPT من المتصفح ← الإعدادات ← «Apps» أو «Plugins».', en: 'Open ChatGPT in the browser → Settings → «Apps» or «Plugins».' },
      { ar: 'اضغط «+» ثم «Create MCP app».', en: 'Press «+», then «Create MCP app».' },
      { ar: 'الاسم: كامبينز هب — الرابط: الصق الرابط — الدخول: OAuth، ثم «Create».', en: 'Name: CampaignsHub — URL: paste the link — Authentication: OAuth, then «Create».' },
      { ar: 'سجّل دخولك في كامبينز هب واضغط «سماح». خلاص!', en: 'Sign in to CampaignsHub and press «Allow». Done!' },
    ],
    note: { ar: 'إضافة تطبيق مخصص في ChatGPT تختلف حسب نوع اشتراكك وإعدادات مساحة العمل.', en: 'Adding a custom app in ChatGPT depends on your plan and workspace settings.' },
    ready: true,
  },
  {
    key: 'gemini',
    label: 'Gemini',
    open: null,
    steps: [],
    note: { ar: 'تطبيق Gemini ما يتيح إضافة رابط مخصص للحين. أول ما يتيحه بيشتغل بنفس الرابط.', en: 'The Gemini app does not take a custom link yet. The same link will work as soon as it does.' },
    ready: false,
  },
]

/** What to type first — the merchant's own words, store and ads in one breath. */
export const FIRST_ASKS: { ar: string; en: string }[] = [
  { ar: 'كم صرفت على الإعلانات هالشهر وكم بعت في متجري؟ وش العائد الحقيقي؟', en: 'How much did I spend on ads this month and how much did my store sell? What is the real return?' },
  { ar: 'وش أفضل حملة عندي آخر 7 أيام ووش أضعفها؟', en: 'Which campaign did best over the last 7 days, and which did worst?' },
  { ar: 'أي منصة تجيب لي مبيعات أكثر: سناب ولا تيك توك ولا ميتا؟', en: 'Which platform brings me the most sales: Snapchat, TikTok or Meta?' },
  { ar: 'وش أكثر المنتجات مبيعًا من الإعلانات؟', en: 'Which products sell most from the ads?' },
  { ar: 'فيه حملات تصرف بدون نتيجة؟ اقترح علي وش أوقف.', en: 'Are any campaigns spending without results? Suggest what to pause.' },
  { ar: 'سوّ لي ملخص أسبوعي قصير أرسله لعميلي.', en: 'Write me a short weekly summary to send my client.' },
]
