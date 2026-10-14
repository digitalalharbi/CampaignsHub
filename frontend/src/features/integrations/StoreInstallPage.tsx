import { useMutation, useQuery } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { PageIntro } from '@/components/ui/PageIntro'
import { ensureCsrfCookie, getData, postData, toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'

/**
 * SALLA-INSTALL-001 — claim a store that was installed from Salla's side.
 *
 * A merchant who installs CampaignsHub from the Salla App Store lands here (after signing in or
 * signing up) with a one-time claim code. The page names the store and attaches it to the signed-in
 * workspace; until then the store is connected to nobody.
 */
interface PendingInstall {
  provider: string
  store_name: string
  store_domain: string | null
  currency: string | null
  expires_at: string
}

export function StoreInstallPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const claim = params.get('claim') ?? ''

  const pending = useQuery({
    queryKey: ['store-install', claim],
    queryFn: () => getData<PendingInstall>(`/commerce/store-installs/${encodeURIComponent(claim)}`),
    enabled: claim !== '',
    retry: false,
  })

  const accept = useMutation({
    mutationFn: async () => {
      await ensureCsrfCookie()
      return postData<{ connection_id: string; stores: number }>(`/commerce/store-installs/${encodeURIComponent(claim)}/claim`, {})
    },
    onSuccess: (r) => navigate(`/app/integrations?provider=salla&outcome=connected&stores=${r.stores}`),
  })

  return (
    <div className="mx-auto flex max-w-lg flex-col gap-4 p-6" data-testid="store-install-page">
      <PageIntro
        testid="store-install-intro"
        title={ar ? 'ربط متجر سلة بحسابك' : 'Connect your Salla store'}
        purpose={ar ? 'متجر ثُبّت عليه التطبيق من سلة، بانتظار ربطه بمساحة عمل.' : 'A store that installed the app from Salla, waiting to be attached to a workspace.'}
      />

      {claim === '' || pending.isError ? (
        <p data-testid="store-install-expired" className="rounded-xl bg-[var(--warning-background)] p-3 text-sm text-[var(--warning-foreground)]">
          {ar ? 'رابط التثبيت انتهت صلاحيته أو استُخدم مسبقًا. ثبّت التطبيق من سلة مرة أخرى.' : 'This install link has expired or was already used. Install the app from Salla again.'}
        </p>
      ) : pending.isPending ? (
        <p className="text-sm text-text-muted">{ar ? 'جارٍ التحميل…' : 'Loading…'}</p>
      ) : (
        <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-4">
          <p className="text-sm text-text-secondary">
            {ar ? 'ثُبّت التطبيق على هذا المتجر من سلة. اربطه بمساحة العمل الحالية لتبدأ المزامنة.' : 'The app was installed on this store from Salla. Attach it to your current workspace to start syncing.'}
          </p>
          <p data-testid="store-install-name" className="text-base font-bold text-text-primary">{pending.data.store_name}</p>
          {pending.data.store_domain && <p className="text-xs text-text-muted"><bdi dir="ltr">{pending.data.store_domain}</bdi></p>}
          <Button data-testid="store-install-claim" onClick={() => accept.mutate()} loading={accept.isPending}>
            {ar ? 'ربط المتجر بمساحة العمل' : 'Attach the store to this workspace'}
          </Button>
          {accept.isError && <p className="text-xs text-danger">{toApiError(accept.error).message}</p>}
        </div>
      )}
    </div>
  )
}
