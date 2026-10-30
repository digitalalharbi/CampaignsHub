import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { confirmMetaSaasReview, withdrawMetaSaasReview, type MetaCandidateState, type MetaSaasReadinessItem, type MetaSaasReadinessKey, type MetaSaasReadinessStatus } from './api'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'

/**
 * META-SAAS-APP-001 — how close the SaaS app is to taking customer connections.
 *
 * Each status is the server's, read from evidence it holds (a stored value, a passed round trip, a call Meta really made, a sync
 * that really ran). This list never upgrades one on its own. Business Verification and App Review are Meta's decisions that Meta does
 * not expose to the app, so they become «ready» only on the Owner's recorded confirmation, which is shown with its date.
 */
const COPY = {
  ar: {
    title: 'جاهزية تطبيق المنصة',
    note: 'كل حالة مقروءة من دليل لدى المنصة. لا يُفعَّل الربط لعملاء الإنتاج من هنا تلقائيًا.',
    status: { ready: 'جاهز', not_verified: 'غير متحقق', needs_credentials: 'يحتاج بيانات اعتماد', owner_action: 'يحتاج إجراء من المالك' } as Record<MetaSaasReadinessStatus, string>,
    items: {
      legal_pages: 'صفحات الخصوصية والشروط وحذف البيانات منشورة',
      redirect_uri: 'رابط العودة (Redirect URI)',
      deletion_callback: 'رابط حذف البيانات الخاص بتطبيق المنصة، يُتحقق من توقيعه بسرّه وحده',
      credentials: 'App ID وApp Secret وConfiguration ID',
      test_workspace: 'مساحة اختبار معزولة تربط عبر التطبيق الجديد فقط',
      meta_dashboard: 'إعدادات لوحة ميتا: Facebook Login for Business ورابط العودة والنطاقات وأدوار المختبِرين',
      oauth_round_trip: 'اختبار الربط الكامل: تفويض ← عودة ← رمز',
      account_discovery: 'اكتشاف حساب إعلاني تجريبي',
      sync_and_reports: 'مزامنة حساب مربوط بتطبيق المنصة وظهوره في التقارير',
      business_verification: 'توثيق النشاط التجاري (Business Verification)',
      app_review: 'مراجعة التطبيق وصلاحية ads_read بمستوى Advanced Access',
      new_connections: 'استخدام تطبيق المنصة للربط الجديد',
    } as Record<MetaSaasReadinessKey, string>,
    hints: {
      deletion_callback: 'يصبح جاهزًا حين تستدعيه ميتا فعلًا بتوقيع صحيح.',
      meta_dashboard: 'تثبته أول جولة اختبار ناجحة بالبيانات الحالية.',
      sync_and_reports: 'يصبح جاهزًا بعد مزامنة حقيقية لحساب مربوط بهذا التطبيق.',
      new_connections: 'قرار المالك بعد اعتماد ميتا؛ الربط القائم لا ينتقل.',
    } as Partial<Record<MetaSaasReadinessKey, string>>,
    confirmedAt: 'أكّده المالك في',
    confirm: 'تسجيل أن ميتا وثّقت النشاط ومنحت Advanced Access',
    confirmCheck: 'أؤكد أن ميتا أتمّت Business Verification ومنحت صلاحية ads_read بمستوى Advanced Access لهذا التطبيق.',
    withdraw: 'سحب التأكيد',
    links: 'روابط الإعداد',
  },
  en: {
    title: 'SaaS app readiness',
    note: 'Every status is read from evidence the platform holds. Nothing here turns on connections for production customers by itself.',
    status: { ready: 'Ready', not_verified: 'Not verified', needs_credentials: 'Needs credentials', owner_action: 'Needs Owner action' } as Record<MetaSaasReadinessStatus, string>,
    items: {
      legal_pages: 'Privacy, Terms and Data deletion pages are published',
      redirect_uri: 'Redirect URI',
      deletion_callback: 'The SaaS app’s own data deletion callback, verified with its secret only',
      credentials: 'App ID, App Secret and Configuration ID',
      test_workspace: 'An isolated test workspace that connects through the new app only',
      meta_dashboard: 'Meta dashboard settings: Facebook Login for Business, redirect URI, domains, tester roles',
      oauth_round_trip: 'Full connection test: authorise → callback → token',
      account_discovery: 'A test ad account is discovered',
      sync_and_reports: 'An account on a SaaS connection syncs and reaches reports',
      business_verification: 'Business Verification',
      app_review: 'App Review and ads_read Advanced Access',
      new_connections: 'New connections use the SaaS app',
    } as Record<MetaSaasReadinessKey, string>,
    hints: {
      deletion_callback: 'Ready once Meta really calls it with a valid signature.',
      meta_dashboard: 'Proven by the first passing test with the current credentials.',
      sync_and_reports: 'Ready after a real sync of an account on this app’s connection.',
      new_connections: 'The Owner’s decision after Meta’s approval; existing connections never move.',
    } as Partial<Record<MetaSaasReadinessKey, string>>,
    confirmedAt: 'Confirmed by the Owner on',
    confirm: 'Record that Meta verified the business and granted Advanced Access',
    confirmCheck: 'I confirm Meta completed Business Verification and granted ads_read Advanced Access to this app.',
    withdraw: 'Withdraw confirmation',
    links: 'Setup links',
  },
}

const TONE: Record<MetaSaasReadinessStatus, string> = {
  ready: 'bg-brand-primary-soft text-brand-700',
  not_verified: 'bg-[var(--info-background)] text-text-primary',
  needs_credentials: 'bg-[var(--warning-background)] text-warning',
  owner_action: 'bg-[var(--warning-background)] text-warning',
}

/** Where the Owner does each item, in Meta's own consoles. */
const LINKS: Partial<Record<MetaSaasReadinessKey, string[]>> = {
  credentials: ['https://developers.facebook.com/apps/'],
  meta_dashboard: ['https://developers.facebook.com/apps/', 'https://business.facebook.com/settings'],
  business_verification: ['https://business.facebook.com/settings/security'],
  app_review: ['https://developers.facebook.com/docs/marketing-api/get-started/authorization', 'https://developers.facebook.com/docs/app-review'],
}

/** The URLs the server reports for an item, to copy into Meta's dashboard. */
function urlsOf(item: MetaSaasReadinessItem): string[] {
  return Object.entries(item.detail)
    .filter(([k, v]) => k.endsWith('_url') || k === 'redirect_uri' ? typeof v === 'string' : false)
    .map(([, v]) => v as string)
}

export function MetaSaasReadiness({ items, ar }: { items: MetaSaasReadinessItem[]; ar: boolean }) {
  const c = COPY[ar ? 'ar' : 'en']
  const qc = useQueryClient()
  const onSuccess = (data: MetaCandidateState) => qc.setQueryData(['admin', 'meta-saas'], data)
  const confirm = useMutation({ mutationFn: confirmMetaSaasReview, onSuccess })
  const withdraw = useMutation({ mutationFn: withdrawMetaSaasReview, onSuccess })
  const [checked, setChecked] = useState(false)
  const review = items.find((i) => i.key === 'app_review')
  const confirmedAt = typeof review?.detail.confirmed_at === 'string' ? review.detail.confirmed_at : null
  const error = confirm.error ?? withdraw.error

  return (
    <section data-testid="meta-saas-readiness" className="rounded-2xl border border-border bg-surface p-5">
      <h2 className="font-heading text-[15px] font-bold text-text-primary">{c.title}</h2>
      <p className="mt-1 max-w-3xl text-xs text-text-secondary">{c.note}</p>

      <ul className="mt-4 flex flex-col divide-y divide-border">
        {items.map((item) => {
          const urls = urlsOf(item)
          const links = LINKS[item.key] ?? []
          return (
            <li key={item.key} data-testid={`meta-saas-readiness-${item.key}`} data-status={item.status} className="flex flex-col gap-1 py-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-text-primary">{c.items[item.key]}</p>
                {c.hints[item.key] && item.status !== 'ready' && <p className="mt-0.5 text-xs text-text-muted">{c.hints[item.key]}</p>}
                {urls.map((u) => (
                  <p key={u} className="mt-0.5 break-all font-mono text-xs text-text-secondary" dir="ltr">{u}</p>
                ))}
                {item.key === 'credentials' && Array.isArray(item.detail.missing) && item.detail.missing.length > 0 && (
                  <p className="mt-0.5 text-xs text-text-muted" dir="ltr">{(item.detail.missing as string[]).join(', ')}</p>
                )}
                {(item.key === 'business_verification' || item.key === 'app_review') && confirmedAt && (
                  <p className="mt-0.5 text-xs text-text-muted">{c.confirmedAt} <bdi dir="ltr">{confirmedAt.slice(0, 10)}</bdi></p>
                )}
                {item.status !== 'ready' && links.length > 0 && (
                  <p className="mt-0.5 flex flex-wrap gap-x-3 text-xs">
                    {links.map((l) => (
                      <a key={l} href={l} target="_blank" rel="noreferrer" className="break-all text-brand-700 underline" dir="ltr">{l.replace('https://', '')}</a>
                    ))}
                  </p>
                )}
              </div>
              <span data-testid="meta-saas-readiness-status" className={`shrink-0 self-start rounded-full px-2.5 py-0.5 text-xs font-semibold ${TONE[item.status]}`}>
                {c.status[item.status]}
              </span>
            </li>
          )
        })}
      </ul>

      <div className="mt-4 border-t border-border pt-4">
        {confirmedAt ? (
          <Button data-testid="meta-saas-review-withdraw" variant="secondary" loading={withdraw.isPending} onClick={() => withdraw.mutate()}>{c.withdraw}</Button>
        ) : (
          <div className="flex flex-col gap-2">
            <label className="flex items-start gap-2 text-xs text-text-secondary">
              <input data-testid="meta-saas-review-check" type="checkbox" className="mt-0.5" checked={checked} onChange={(e) => setChecked(e.target.checked)} />
              <span>{c.confirmCheck}</span>
            </label>
            <div>
              <Button data-testid="meta-saas-review-confirm" variant="secondary" disabled={!checked} loading={confirm.isPending} onClick={() => confirm.mutate()}>{c.confirm}</Button>
            </div>
          </div>
        )}
        {error && <p className="mt-2 text-xs text-danger">{toApiError(error).message}</p>}
      </div>
    </section>
  )
}
