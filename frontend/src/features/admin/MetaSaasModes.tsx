import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Circle } from 'lucide-react'
import { setMetaSaasRouting, type MetaCandidateState, type MetaSaasModesState } from './api'
import { Button } from '@/components/ui/Button'
import { Num } from '@/components/ui/Num'
import { toApiError } from '@/lib/api/client'

/**
 * META-SAAS-APP-001 — Live / SaaS Test / SaaS Primary, managed in one place.
 *
 * «Set SaaS as primary» changes which app NEW connections use, nothing else: the preview says how many existing connections stay
 * where they are (all of them). It stays disabled until every precondition the server read from evidence is met, and then still
 * needs the Owner's two named statements. Going back to Live is always available and, again, only for new connections.
 */
const COPY = {
  ar: {
    title: 'وضع تكامل ميتا',
    modes: { live: 'Live الحالي', saas_test: 'SaaS — اختبار', saas_primary: 'SaaS — أساسي' } as Record<string, string>,
    modeNotes: {
      live: 'كل ربط جديد عبر تطبيق Live الحالي. تطبيق المنصة غير مستخدم في أي مكان.',
      saas_test: 'كل العملاء على Live. تطبيق المنصة يُستخدم داخل مساحة الاختبار المعزولة فقط.',
      saas_primary: 'الربط الجديد في كل المساحات عبر تطبيق المنصة. الاتصالات القائمة باقية مع تطبيقها وتستمر مزامنتها.',
    } as Record<string, string>,
    primary: 'تعيين SaaS كتطبيق أساسي للاتصالات الجديدة',
    preconditions: 'شروط التفعيل (من أدلة فعلية)',
    pre: {
      credentials_configured: 'بيانات اعتماد التطبيق الجديد مُدخلة',
      round_trip_passed: 'نجاح اختبار الربط الحقيقي بالبيانات الحالية',
      test_journey_complete: 'رحلة فعلية كاملة في مساحة الاختبار (ربط، اكتشاف، مزامنة، مقاييس، تقرير)',
      meta_approval_recorded: 'تسجيل موافقة ميتا (Business Verification وAdvanced Access)',
      live_still_configured: 'تطبيق Live ما زال مُعدًّا لتستمر مزامنة اتصالاته',
    } as Record<string, string>,
    liveHealth: 'صحة Live الحالية', liveAccounts: 'حسابات إعلانية على Live', synced24h: 'زُامنت خلال 24 ساعة', oldestSync: 'أقدم مزامنة ناجحة',
    preview: 'معاينة الأثر', liveConnections: 'اتصالات Live قائمة', liveTenants: 'مساحات عمل', saasConnections: 'اتصالات SaaS قائمة', moved: 'اتصالات ستُنقل',
    approve: 'أوافق، بصفتي المالك، على أن يستخدم كل ربط جديد تطبيق المنصة (SaaS).',
    acknowledge: 'أفهم أن الاتصالات القائمة لا تُنقل وتبقى مع التطبيق الذي أصدر رموزها.',
    enable: 'تفعيل SaaS كتطبيق أساسي', revert: 'العودة إلى Live للاتصالات الجديدة',
    revertNote: 'العودة لا تمس الاتصالات التي أُنشئت عبر SaaS؛ تبقى مع تطبيقها.',
    locked: 'مقفل حتى تتحقق كل الشروط.',
  },
  en: {
    title: 'Meta integration mode',
    modes: { live: 'Live (current)', saas_test: 'SaaS — test', saas_primary: 'SaaS — primary' } as Record<string, string>,
    modeNotes: {
      live: 'Every new connection uses the current Live app. The platform app is not used anywhere.',
      saas_test: 'Every customer is on Live. The platform app is used only inside the isolated test workspace.',
      saas_primary: 'New connections everywhere use the platform app. Existing connections stay with their app and keep syncing.',
    } as Record<string, string>,
    primary: 'Set SaaS as the primary app for new connections',
    preconditions: 'Conditions (from real evidence)',
    pre: {
      credentials_configured: 'The new app’s credentials are entered',
      round_trip_passed: 'The real connection test passed with the current credentials',
      test_journey_complete: 'A real, complete journey in the test workspace (connect, discover, sync, metrics, report)',
      meta_approval_recorded: 'Meta’s approval recorded (Business Verification and Advanced Access)',
      live_still_configured: 'The Live app is still configured, so its connections keep syncing',
    } as Record<string, string>,
    liveHealth: 'Current Live health', liveAccounts: 'ad accounts on Live', synced24h: 'synced in the last 24 h', oldestSync: 'oldest successful sync',
    preview: 'Impact preview', liveConnections: 'existing Live connections', liveTenants: 'workspaces', saasConnections: 'existing SaaS connections', moved: 'connections moved',
    approve: 'As the Owner, I approve that every new connection uses the platform (SaaS) app.',
    acknowledge: 'I understand existing connections are not moved and stay with the app that issued their tokens.',
    enable: 'Make SaaS primary', revert: 'Back to Live for new connections',
    revertNote: 'Going back does not touch connections made through SaaS; they stay with their app.',
    locked: 'Locked until every condition is met.',
  },
}

export function MetaSaasModes({ modes, ar }: { modes: MetaSaasModesState; ar: boolean }) {
  const c = COPY[ar ? 'ar' : 'en']
  const qc = useQueryClient()
  const switchTo = useMutation({ mutationFn: setMetaSaasRouting, onSuccess: (data: MetaCandidateState) => qc.setQueryData(['admin', 'meta-saas'], data) })
  const [approved, setApproved] = useState(false)
  const [acknowledged, setAcknowledged] = useState(false)
  const { primary } = modes
  const p = primary.preview

  return (
    <section data-testid="meta-saas-modes" className="rounded-2xl border border-border bg-surface p-5">
      <h2 className="font-heading text-[15px] font-bold text-text-primary">{c.title}</h2>
      <div className="mt-3 flex flex-wrap gap-2" role="list">
        {(['live', 'saas_test', 'saas_primary'] as const).map((m) => (
          <span
            key={m}
            role="listitem"
            data-testid={`meta-saas-mode-${m}`}
            aria-current={modes.current === m ? 'true' : undefined}
            className={`rounded-full px-3 py-1 text-xs font-semibold ${modes.current === m ? 'bg-brand-primary-soft text-brand-700' : 'bg-surface-secondary text-text-muted'}`}
          >
            {c.modes[m]}
          </span>
        ))}
      </div>
      <p className="mt-2 max-w-3xl text-xs text-text-secondary" data-testid="meta-saas-mode-note">{c.modeNotes[modes.current]}</p>

      <h3 className="mt-5 text-sm font-bold text-text-primary">{c.primary}</h3>
      <p className="mt-2 text-xs font-semibold text-text-secondary">{c.preconditions}</p>
      <ul className="mt-1 grid gap-1.5" data-testid="meta-saas-primary-preconditions">
        {primary.preconditions.map((pc) => (
          <li key={pc.key} data-testid={`meta-saas-precondition-${pc.key}`} data-met={pc.met} className="flex items-center gap-2 text-sm">
            {pc.met ? <CheckCircle2 size={16} className="text-success" aria-hidden /> : <Circle size={16} className="text-text-muted" aria-hidden />}
            <span className={pc.met ? 'text-text-primary' : 'text-text-secondary'}>{c.pre[pc.key] ?? pc.key}</span>
          </li>
        ))}
      </ul>

      <p className="mt-4 text-xs font-semibold text-text-secondary">{c.preview}</p>
      <dl className="mt-1 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4" data-testid="meta-saas-primary-preview">
        <div><dt className="text-text-muted">{c.liveConnections}</dt><dd className="tnum font-semibold text-text-primary"><Num>{p.existing_live_connections}</Num></dd></div>
        <div><dt className="text-text-muted">{c.liveTenants}</dt><dd className="tnum font-semibold text-text-primary"><Num>{p.existing_live_tenants}</Num></dd></div>
        <div><dt className="text-text-muted">{c.saasConnections}</dt><dd className="tnum font-semibold text-text-primary"><Num>{p.existing_saas_connections}</Num></dd></div>
        <div><dt className="text-text-muted">{c.moved}</dt><dd className="tnum font-semibold text-text-primary" data-testid="meta-saas-preview-moved"><Num>{p.existing_connections_moved}</Num></dd></div>
      </dl>

      <p className="mt-4 text-xs font-semibold text-text-secondary">{c.liveHealth}</p>
      <dl className="mt-1 grid grid-cols-2 gap-2 text-xs sm:grid-cols-3" data-testid="meta-saas-live-health">
        <div><dt className="text-text-muted">{c.liveAccounts}</dt><dd className="tnum font-semibold text-text-primary"><Num>{p.existing_live_ad_accounts ?? 0}</Num></dd></div>
        <div><dt className="text-text-muted">{c.synced24h}</dt><dd className="tnum font-semibold text-text-primary"><Num>{p.live_ad_accounts_synced_24h ?? 0}</Num></dd></div>
        <div><dt className="text-text-muted">{c.oldestSync}</dt><dd className="font-semibold text-text-primary"><bdi dir="ltr">{p.live_oldest_last_successful_sync_at ? p.live_oldest_last_successful_sync_at.slice(0, 16).replace('T', ' ') : '—'}</bdi></dd></div>
      </dl>

      {primary.enabled ? (
        <div className="mt-4 flex flex-col gap-2">
          <p className="text-xs text-text-muted">{c.revertNote}</p>
          <div><Button data-testid="meta-saas-primary-revert" variant="secondary" loading={switchTo.isPending} onClick={() => switchTo.mutate('live')}>{c.revert}</Button></div>
        </div>
      ) : (
        <div className="mt-4 flex flex-col gap-2">
          {!primary.eligible && <p className="text-xs text-text-muted" data-testid="meta-saas-primary-locked">{c.locked}</p>}
          <label className="flex items-start gap-2 text-xs text-text-primary">
            <input type="checkbox" data-testid="meta-saas-primary-approve" disabled={!primary.eligible} checked={approved} onChange={(e) => setApproved(e.target.checked)} />
            <span>{c.approve}</span>
          </label>
          <label className="flex items-start gap-2 text-xs text-text-primary">
            <input type="checkbox" data-testid="meta-saas-primary-acknowledge" disabled={!primary.eligible} checked={acknowledged} onChange={(e) => setAcknowledged(e.target.checked)} />
            <span>{c.acknowledge}</span>
          </label>
          <div>
            <Button data-testid="meta-saas-primary-enable" disabled={!primary.eligible || !approved || !acknowledged} loading={switchTo.isPending} onClick={() => switchTo.mutate('saas')}>
              {c.enable}
            </Button>
          </div>
        </div>
      )}
      {switchTo.isError && <p className="mt-2 text-xs text-danger">{toApiError(switchTo.error).message}</p>}
    </section>
  )
}
