import { KpiMini, KpiStrip } from '@/components/fit/KpiMini'
import { Skeleton } from '@/components/ui/States'
import { MessageSquare, Smartphone, Users, BarChart3 } from 'lucide-react'
import { useEntitlements, useUsage, type WorkspaceSettingsData } from './api'
import type { WordKey } from './strings'
import { useTypeWords } from './typeWords'
import { LockedNote, StatusPill, TabBody } from './ui'

const MODE_WORD: Record<string, WordKey> = { full: 'mFull', grace: 'mGrace', grandfathered: 'mGrandfathered', view_only: 'mViewOnly', off: 'mOff' }
const KEY_AR: Record<string, string> = {
  'notifications.internal': 'الإشعارات الداخلية', 'notifications.push': 'إشعارات الجوال', 'notifications.sound': 'صوت التنبيه', 'notifications.external': 'الإشعارات الخارجية',
  'notifications.external.charts': 'الرسوم في الرسائل الخارجية', 'notifications.external.cc_bcc': 'النسخ في الرسائل الخارجية', 'notifications.external.custom_subject': 'موضوع مخصص',
  'notifications.external.review_mode': 'المراجعة قبل الإرسال', 'followup.morning_brief': 'الملخص الصباحي', 'followup.tasks': 'مهام المتابعة', 'branding.remove_platform_footprint': 'إزالة بصمة المنصة',
  'projects.trash_retention_days': 'مدة بقاء المشروع المحذوف',
}
const KEY_EN: Record<string, string> = {
  'notifications.internal': 'Internal notifications', 'notifications.push': 'Mobile push', 'notifications.sound': 'Alert sound', 'notifications.external': 'External notifications',
  'notifications.external.charts': 'Charts in external messages', 'notifications.external.cc_bcc': 'CC and BCC', 'notifications.external.custom_subject': 'Custom subject',
  'notifications.external.review_mode': 'Review before send', 'followup.morning_brief': 'Morning brief', 'followup.tasks': 'Follow-up tasks', 'branding.remove_platform_footprint': 'Remove platform footprint',
  'projects.trash_retention_days': 'Trash retention',
}

/** The workspace usage page: this month's counters against the plan's limits, and the state of every plan key. */
export function UsagePanel({ data }: { data: WorkspaceSettingsData }) {
  const { t, lang } = useTypeWords(data.workspace.type)
  const usage = useUsage()
  const ent = useEntitlements()
  const names = lang === 'ar' ? KEY_AR : KEY_EN
  if (usage.isLoading || ent.isLoading) return <Skeleton className="h-40" />
  const u = usage.data
  const limit = (k: string) => { const v = u?.limits[k]; return v === null || v === undefined ? t('unlimited') : v }
  const fmt = (n: number | undefined, k: string) => <>{n ?? 0} <span className="text-xs font-semibold text-text-muted">{t('usageOf')} {limit(k)}</span></>

  return (
    <TabBody testid="usage-panel">
      <div className="space-y-3">
        <p className="text-xs text-text-muted">{t('uPeriod')}: <span dir="ltr">{u?.period}</span></p>
        <KpiStrip testid="usage-kpis">
          <KpiMini testid="u-messages" icon={MessageSquare} label={t('uMessages')} value={fmt(u?.counts['notif.external_messages'], 'monthly_messages')} />
          <KpiMini testid="u-recipients" icon={Users} label={t('uRecipients')} value={fmt(u?.counts['notif.external_recipients'], 'external_recipients')} />
          <KpiMini testid="u-push" icon={Smartphone} label={t('uPush')} value={fmt(u?.counts['notif.push_notifications'], 'monthly_push')} />
          <KpiMini testid="u-charts" icon={BarChart3} label={t('uCharts')} value={fmt(u?.counts['notif.charts_generated'], 'monthly_charts')} />
        </KpiStrip>
        <h3 className="text-sm font-bold">{t('uPlanKeys')}</h3>
        <ul className="grid gap-2 md:grid-cols-2">
          {(ent.data?.entitlements ?? []).map((e) => (
            <li key={e.key} data-testid={`plan-key-${e.key}`} className="space-y-1 rounded-xl border border-border bg-surface px-3 py-2">
              <div className="flex items-center justify-between gap-2"><span className="min-w-0 truncate text-sm font-semibold">{names[e.key] ?? e.key}</span>
                <StatusPill tone={e.allowed ? (e.mode === 'full' ? 'success' : 'warning') : 'neutral'}>{t(MODE_WORD[e.mode] ?? 'mOff')}</StatusPill></div>
              {!e.can_edit && <LockedNote entitlement={e.key as never} compact />}
            </li>
          ))}
        </ul>
      </div>
    </TabBody>
  )
}
