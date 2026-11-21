import { Copy, Image, Pause, SlidersHorizontal, Target, Wallet, Zap } from 'lucide-react'
import { ProviderMark } from '@/components/ProviderMark'

/**
 * CAMPAIGN-MANAGER-PRO-001 — Owner 2026-11-21: managing campaigns on the platforms from here is a headline capability, not a
 * button found by accident. One strip above the table says what can be done, on which of this project's platforms, and
 * where («إدارة» on any row). What a given row may change is still decided per campaign by the server's write capabilities.
 */
export function DirectControlStrip({ ar, platforms, level, testid }: { ar: boolean; platforms: string[]; level: 'campaign' | 'ad_set' | 'ad'; testid?: string }) {
  const caps = [
    { icon: Pause, ar: 'تشغيل وإيقاف', en: 'On / off' },
    ...(level !== 'ad' ? [{ icon: Wallet, ar: 'الميزانية', en: 'Budget' }] : []),
    ...(level === 'ad_set' ? [{ icon: Target, ar: 'الاستهداف', en: 'Targeting' }] : []),
    ...(level === 'ad' ? [{ icon: Image, ar: 'المحتوى والرابط', en: 'Creative & link' }] : []),
    { icon: Copy, ar: 'نسخ وأرشفة', en: 'Duplicate & archive' },
  ]

  return (
    <div data-testid={testid ?? 'direct-control-strip'} className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-brand-500/30 bg-brand-500/5 px-3.5 py-2.5">
      <span className="inline-flex items-center gap-2">
        <span className="grid size-7 place-items-center rounded-lg bg-brand-600 text-white" aria-hidden><Zap size={15} /></span>
        <span className="text-sm font-extrabold text-text-primary">{ar ? 'إدارة مباشرة على المنصات' : 'Manage directly on the platforms'}</span>
      </span>
      <span className="flex flex-wrap items-center gap-1.5">
        {caps.map((c) => (
          <span key={c.en} className="inline-flex items-center gap-1 rounded-full bg-surface px-2 py-0.5 text-xs font-semibold text-text-secondary ring-1 ring-border">
            <c.icon size={12} aria-hidden />{ar ? c.ar : c.en}
          </span>
        ))}
      </span>
      {platforms.length > 0 && (
        <span className="flex items-center gap-1" aria-label={ar ? 'منصات المشروع' : 'Project platforms'}>
          {platforms.slice(0, 7).map((p) => <ProviderMark key={p} provider={p} size={16} />)}
        </span>
      )}
      <span className="ms-auto inline-flex items-center gap-1 text-xs text-text-muted">
        <SlidersHorizontal size={12} aria-hidden />
        {ar ? 'اضغط «إدارة» على أي صف — يُرسل التغيير للمنصة ويُسجَّل باسمك' : 'Press «Manage» on any row — the change goes to the platform and is logged under your name'}
      </span>
    </div>
  )
}
