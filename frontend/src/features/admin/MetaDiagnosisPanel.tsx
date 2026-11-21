import { useQuery } from '@tanstack/react-query'
import { Badge } from '@/components/ui/Badge'
import { Num } from '@/components/ui/Num'
import { ErrorState, Skeleton } from '@/components/ui/States'
import { fmtDateTime } from '@/lib/datetime'
import { useUi } from '@/stores/ui'
import { fetchMetaDiagnosis, type MetaDiagnosisAccount, type MetaDiagnosisConnection, type MetaRefusal } from './metaDiagnosisApi'

/**
 * META-DIAGNOSIS-001 — each Meta connection, its state and the one action it needs.
 *
 * Only what the sync recorded: opening this calls nothing at Meta and changes nothing, and it offers no reconnect
 * or rotate button, because those belong to the connection's owner. «Syncing» appears only after a real sync.
 */
type Tone = 'success' | 'warning' | 'danger' | 'neutral'

const STATES: Record<MetaDiagnosisConnection['state'], { ar: string; en: string; tone: Tone }> = {
  needs_reconnect: { ar: 'يحتاج إعادة ربط', en: 'Needs reconnecting', tone: 'danger' },
  token_expired: { ar: 'انتهى الرمز', en: 'Token expired', tone: 'danger' },
  permission_missing: { ar: 'صلاحية ناقصة', en: 'Permission missing', tone: 'danger' },
  disconnected: { ar: 'غير مربوط', en: 'Disconnected', tone: 'neutral' },
  never_synced: { ar: 'مربوط ولم تصل بيانات بعد', en: 'Connected, nothing synced yet', tone: 'warning' },
  stale: { ar: 'مزامنة متأخرة', en: 'Sync is late', tone: 'warning' },
  syncing: { ar: 'يزامن', en: 'Syncing', tone: 'success' },
}

const TOKENS: Record<MetaDiagnosisConnection['token']['state'], { ar: string; en: string }> = {
  valid: { ar: 'سارٍ', en: 'valid' },
  expiring: { ar: 'ينتهي قريبًا', en: 'expiring soon' },
  expired: { ar: 'منتهٍ', en: 'expired' },
  not_stated: { ar: 'بلا تاريخ انتهاء معلن', en: 'no stated expiry' },
}

const KINDS: Record<string, { ar: string; en: string }> = {
  session_invalid: { ar: 'جلسة منتهية', en: 'dead session' },
  permission_missing: { ar: 'صلاحية ناقصة', en: 'permission missing' },
  account_unreachable: { ar: 'حساب لا يمكن الوصول إليه', en: 'account unreachable' },
  rate_limited: { ar: 'حد الطلبات', en: 'rate limit' },
  transient: { ar: 'عطل مؤقت', en: 'passing fault' },
  data_volume: { ar: 'صفحة كبيرة', en: 'page too large' },
  request_rejected: { ar: 'معامل مرفوض', en: 'rejected parameter' },
  policy_block: { ar: 'حظر سياسة', en: 'policy block' },
  unclassified: { ar: 'غير مصنّف', en: 'unclassified' },
}

const kindLabel = (kind: string, ar: boolean): string => KINDS[kind]?.[ar ? 'ar' : 'en'] ?? kind

function codeOf(refusal: MetaRefusal): string {
  return [
    refusal.code !== null ? `code ${refusal.code}` : null,
    refusal.subcode !== null ? `subcode ${refusal.subcode}` : null,
    refusal.trace !== null ? `trace ${refusal.trace}` : null,
  ].filter(Boolean).join(' · ')
}

function AccountRow({ account, ar }: { account: MetaDiagnosisAccount; ar: boolean }) {
  const refusal = account.structure.refusal ?? account.metrics.refusal
  const media = account.structure.media
  const renewal = account.structure.media_renewal

  return (
    <li data-testid={`meta-diagnosis-account-${account.external_id}`} className="space-y-1 border-t border-border pt-2 text-xs">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
        <span className="font-semibold text-text-primary">{account.name}</span>
        <span className="text-text-muted" dir="ltr">{account.external_id}</span>
        <span className="text-text-secondary">{account.selected ? (ar ? 'مختار' : 'selected') : (ar ? 'غير مختار' : 'not selected')}</span>
        <span className="text-text-secondary"><Num>{account.creatives}</Num> {ar ? 'محتوى' : 'creatives'}</span>
        <span className={account.expired_media > 0 ? 'text-warning' : 'text-text-secondary'}>
          <Num>{account.expired_media}</Num> {ar ? 'رابط منتهٍ' : 'expired links'}
        </span>
        <span className="text-text-secondary">{ar ? 'آخر مؤشرات:' : 'Last metrics:'} <Num>{fmtDateTime(account.last_metrics_at)}</Num></span>
      </div>
      {refusal !== null && (
        <>
          <p className="text-text-muted" dir="ltr">{codeOf(refusal)}</p>
          <p data-testid="meta-diagnosis-account-action" className="text-text-secondary">{ar ? refusal.action_ar : refusal.action_en}</p>
        </>
      )}
      {media !== null && (
        <p className="text-text-muted">
          {ar ? 'الوسائط:' : 'Media:'} <Num>{media.resolved ?? 0}</Num>/<Num>{media.asked ?? 0}</Num>
          {media.stopped_by ? ` · ${ar ? 'توقفت' : 'stopped'}: ${kindLabel(media.stopped_by, ar)}` : ''}
        </p>
      )}
      {renewal !== null && (
        <p className="text-text-muted">
          {ar ? 'تجديد الروابط المنتهية:' : 'Expired link renewal:'} <Num>{renewal.renewed}</Num>/<Num>{renewal.expired}</Num>
        </p>
      )}
    </li>
  )
}

function ConnectionCard({ connection, ar }: { connection: MetaDiagnosisConnection; ar: boolean }) {
  const state = STATES[connection.state]
  const token = TOKENS[connection.token.state]
  const refusals = Object.entries(connection.refusals_24h)

  return (
    <li data-testid={`meta-diagnosis-${connection.id}`} data-state={connection.state} className="space-y-3 rounded-2xl border border-border bg-surface p-5">
      <header className="flex flex-wrap items-center gap-2">
        <p className="font-heading text-sm font-bold text-text-primary">{connection.tenant}</p>
        <span className="text-xs text-text-muted" dir="ltr">{connection.name} · {connection.app}</span>
        <Badge tone={state.tone}>{ar ? state.ar : state.en}</Badge>
      </header>

      {connection.refusal !== null && (
        <div className="space-y-1 text-sm">
          <p data-testid="meta-diagnosis-action" className="text-text-primary">{ar ? connection.refusal.action_ar : connection.refusal.action_en}</p>
          <p data-testid="meta-diagnosis-code" className="text-xs text-text-muted" dir="ltr">{codeOf(connection.refusal)}</p>
        </div>
      )}

      <dl className="grid gap-x-4 gap-y-1 text-xs sm:grid-cols-2">
        <div><dt className="inline text-text-muted">{ar ? 'الرمز: ' : 'Token: '}</dt><dd className="inline text-text-secondary">{ar ? token.ar : token.en} · <Num>{fmtDateTime(connection.token.expires_at)}</Num></dd></div>
        <div><dt className="inline text-text-muted">{ar ? 'آخر مزامنة فعلية: ' : 'Last real sync: '}</dt><dd className="inline text-text-secondary"><Num>{fmtDateTime(connection.last_sync_at)}</Num></dd></div>
        <div>
          <dt className="inline text-text-muted">{ar ? 'الصلاحيات الناقصة: ' : 'Missing scopes: '}</dt>
          <dd className="inline text-text-secondary">
            {connection.scopes_missing === null
              ? (ar ? 'لا سجل للمنح' : 'no grant recorded')
              : connection.scopes_missing.length === 0
                ? (ar ? 'لا شيء' : 'none')
                : <span dir="ltr">{connection.scopes_missing.join(', ')}</span>}
          </dd>
        </div>
        <div>
          <dt className="inline text-text-muted">{ar ? 'الحسابات: ' : 'Accounts: '}</dt>
          <dd className="inline text-text-secondary">
            <Num>{connection.accounts_total}</Num> · {ar ? 'مختار' : 'selected'} <Num>{connection.accounts_selected}</Num> · {ar ? 'زامن' : 'synced'} <Num>{connection.accounts_synced}</Num>
          </dd>
        </div>
        <div><dt className="inline text-text-muted">{ar ? 'روابط منتهية: ' : 'Expired links: '}</dt><dd className="inline text-text-secondary"><Num>{connection.expired_media}</Num></dd></div>
        <div>
          <dt className="inline text-text-muted">{ar ? 'الرفض في 24 ساعة: ' : 'Refusals in 24h: '}</dt>
          <dd className="inline text-text-secondary">
            {refusals.length === 0 ? (ar ? 'لا شيء' : 'none') : refusals.map(([kind, count]) => (
              <span key={kind} className="me-2">{kindLabel(kind, ar)} <Num>{count}</Num></span>
            ))}
          </dd>
        </div>
      </dl>

      {connection.accounts.length > 0 && <ul className="space-y-2">{connection.accounts.map((a) => <AccountRow key={a.external_id} account={a} ar={ar} />)}</ul>}

      {connection.last_error !== null && (
        <details className="text-[11px] text-text-muted">
          <summary className="cursor-pointer select-none">{ar ? 'التفاصيل التقنية' : 'Technical details'}</summary>
          <pre data-testid="meta-diagnosis-raw" dir="ltr" className="mt-1 overflow-x-auto whitespace-pre-wrap break-words rounded-lg bg-surface-secondary p-2 text-left font-mono">{connection.last_error}</pre>
        </details>
      )}
    </li>
  )
}

export function MetaDiagnosisPanel() {
  const ar = useUi((s) => s.locale) === 'ar'
  const query = useQuery({ queryKey: ['admin', 'integrations', 'meta-diagnosis'], queryFn: fetchMetaDiagnosis })

  return (
    <section data-testid="meta-diagnosis" className="mt-6 space-y-3">
      <div>
        <h3 className="font-heading text-sm font-bold text-text-primary">{ar ? 'تشخيص اتصالات Meta' : 'Meta connection diagnosis'}</h3>
        <p className="text-xs text-text-muted">
          {ar ? 'من سجل المزامنة فقط: لا يتصل هذا العرض بـ Meta ولا يغيّر أي اتصال.' : 'From the sync record only: this view does not call Meta and changes no connection.'}
        </p>
      </div>

      {query.isPending && <Skeleton className="h-32" />}
      {query.isError && <ErrorState error={query.error} ar={ar} title={ar ? 'تعذّر تحميل التشخيص.' : 'The diagnosis could not be loaded.'} />}
      {query.data?.connections.length === 0 && (
        <p className="rounded-2xl border border-dashed border-border px-4 py-8 text-center text-sm text-text-muted">
          {ar ? 'لا يوجد اتصال Meta في هذا التثبيت.' : 'No Meta connection on this install.'}
        </p>
      )}
      {query.data !== undefined && query.data.connections.length > 0 && (
        <ul className="space-y-3">{query.data.connections.map((c) => <ConnectionCard key={c.id} connection={c} ar={ar} />)}</ul>
      )}
    </section>
  )
}
