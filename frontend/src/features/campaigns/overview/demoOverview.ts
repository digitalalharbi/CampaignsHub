import type { OverviewVM } from './UnifiedCampaignOverview'

/**
 * Labeled DEMO view-model for the marketing homepage preview — the SAME shape the real dashboard builds from
 * live data, so the public preview is an honest illustration of the post-login command center (not a divergent
 * mock). Numbers are internally consistent (CPA = spend / results; totals add up). Tagged on screen as
 * «معاينة توضيحية ببيانات تجريبية». `lastSyncAt` is a fixed timestamp so visual snapshots stay deterministic.
 */
export const DEMO_OVERVIEW_VM: OverviewVM = {
  currency: 'SAR',
  dataStatus: 'demo',
  lastSyncAt: '2026-07-28T09:00:00Z',
  kpis: [
    { key: 'spend', label: 'إجمالي الإنفاق', value: '48,900 SAR' },
    { key: 'results', label: 'النتائج المحققة', value: '1,556' },
    { key: 'active', label: 'الحملات النشطة', value: '16' },
    { key: 'cpr', label: 'متوسط تكلفة النتيجة', value: '31.4 SAR' },
    { key: 'remaining', label: 'الميزانية المتبقية', value: '21,100 SAR' },
    { key: 'roas', label: 'ROAS', value: '3.1x', tone: 'good' },
  ],
  // spend / results give the CPA shown per platform; roas is blended.
  platforms: [
    { key: 'meta', name: 'Meta', spend: 18400, results: 512, roas: 3.6 },
    { key: 'google_ads', name: 'Google Ads', spend: 12100, results: 318, roas: 3.1 },
    { key: 'tiktok', name: 'TikTok', spend: 9600, results: 402, roas: 2.8 },
    { key: 'snapchat', name: 'Snapchat', spend: 5200, results: 228, roas: 2.4 },
    { key: 'x', name: 'X', spend: 2400, results: 74, roas: 1.9 },
    { key: 'linkedin', name: 'LinkedIn', spend: 1200, results: 22, roas: 1.6 },
  ],
  spend: [
    { name: 'meta', value: 18400 },
    { name: 'google_ads', value: 12100 },
    { name: 'tiktok', value: 9600 },
    { name: 'snapchat', value: 5200 },
    { name: 'x', value: 2400 },
    { name: 'linkedin', value: 1200 },
  ],
  topCampaigns: [
    { id: 'c1', name: 'إطلاق المجموعة الصيفية', provider: 'meta', spend: 9200, results: 263, cpa: 35.0, roas: 3.9 },
    { id: 'c2', name: 'عروض نهاية الأسبوع', provider: 'google_ads', spend: 6400, results: 176, cpa: 36.4, roas: 3.3 },
    { id: 'c3', name: 'حملة المحتوى القصير', provider: 'tiktok', spend: 5100, results: 214, cpa: 23.8, roas: 2.9 },
    { id: 'c4', name: 'تجربة الجمهور الجديد', provider: 'snapchat', spend: 3000, results: 132, cpa: 22.7, roas: 2.5 },
    { id: 'c5', name: 'حملة الوعي بالعلامة', provider: 'x', spend: 2400, results: 74, cpa: 32.4, roas: 1.9 },
  ],
  needsAttention: [
    { id: 'c5', name: 'حملة الوعي بالعلامة (X)', reason: 'إنفاق مرتفع دون نتائج كافية' },
    { id: 'c6', name: 'اكتساب العملاء (LinkedIn)', reason: 'تكلفة النتيجة أعلى من الهدف' },
  ],
  alerts: [
    { severity: 'high', text: 'ارتفاع تكلفة النتيجة 22% في حملة Google Ads' },
    { severity: 'medium', text: 'استهلاك الميزانية أسرع من المخطط في Meta' },
    { severity: 'info', text: 'تمت آخر مزامنة قبل دقائق — البيانات محدثة' },
  ],
  topCreatives: [
    { id: 'cr1', name: 'فيديو المنتج 15ث', provider: 'tiktok', kind: 'فيديو', results: 402, cpa: 23.9 },
    { id: 'cr2', name: 'كاروسيل العروض', provider: 'meta', kind: 'Carousel', results: 261, cpa: 35.2 },
    { id: 'cr3', name: 'قصة الخصومات', provider: 'snapchat', kind: 'Story', results: 143, cpa: 24.1 },
  ],
}
