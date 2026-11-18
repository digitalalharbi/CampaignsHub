import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { Check, Eye, ShieldCheck, X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { api } from '@/lib/api/client'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

/**
 * MCP-OAUTH-001 — «سماح»: the one screen between «Add custom connector» in Claude or ChatGPT and a working connection.
 *
 * The app sent the person here (through mcp.campaignshub.io/authorize) already signed in, or through the sign-in page
 * and back. It says who is asking, what it will be able to do — read, as this person, and propose changes that wait
 * for approval — and what it will never do. «سماح» returns a single-use code to the app; «إلغاء» tells the app no.
 */
export function McpConsentPage() {
  const ar = useUi((s) => s.locale) === 'ar'
  const tx = (a: string, e: string) => (ar ? a : e)
  const user = useAuth((s) => s.user)
  const [params] = useSearchParams()
  const clientId = params.get('client_id') ?? ''
  const redirectUri = params.get('redirect_uri') ?? ''
  const state = params.get('state')
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState(false)

  const client = useQuery({
    queryKey: ['mcp-oauth-client', clientId],
    queryFn: async () => (await api.get<{ name: string }>(`/mcp/oauth/clients/${encodeURIComponent(clientId)}`)).data,
    enabled: clientId !== '',
    retry: false,
  })

  const valid = clientId !== '' && redirectUri !== '' && params.get('code_challenge_method') === 'S256' && Boolean(params.get('code_challenge'))

  const allow = async () => {
    setBusy(true)
    setFailed(false)
    try {
      const { data } = await api.post<{ redirect: string }>('/mcp/oauth/approve', {
        client_id: clientId,
        redirect_uri: redirectUri,
        code_challenge: params.get('code_challenge'),
        code_challenge_method: 'S256',
        state,
      })
      window.location.assign(data.redirect)
    } catch {
      setFailed(true)
      setBusy(false)
    }
  }

  const deny = () => {
    const sep = redirectUri.includes('?') ? '&' : '?'
    const q = new URLSearchParams({ error: 'access_denied', ...(state ? { state } : {}) })
    window.location.assign(`${redirectUri}${sep}${q.toString()}`)
  }

  const name = client.data?.name ?? tx('تطبيق', 'An app')

  return (
    <main className="flex min-h-screen items-center justify-center bg-surface-secondary px-4 py-10" data-testid="mcp-consent">
      <section className="w-full max-w-md space-y-5 rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <div className="flex items-center gap-3">
          <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-500/10 text-brand-600"><ShieldCheck size={22} aria-hidden /></span>
          <div className="min-w-0">
            <h1 className="font-heading text-lg font-bold text-text-primary">{tx('ربط كامبينز هب', 'Connect CampaignsHub')}</h1>
            <p className="truncate text-sm text-text-secondary" data-testid="mcp-consent-client">
              {client.isLoading ? tx('جارٍ التحقق…', 'Checking…') : tx(`«${name}» يطلب الوصول إلى حسابك`, `${name} is asking for access to your account`)}
            </p>
          </div>
        </div>

        {!valid || client.isError ? (
          <p className="rounded-xl border border-danger/40 bg-danger/5 p-3 text-sm text-text-primary" data-testid="mcp-consent-invalid">
            {tx('رابط الربط غير صالح أو انتهت صلاحيته. ارجع للتطبيق وأضف الرابط mcp.campaignshub.io من جديد.', 'This connection link is not valid or has expired. Go back to the app and add mcp.campaignshub.io again.')}
          </p>
        ) : (
          <>
            {user && (
              <p className="text-sm text-text-secondary">
                {tx('سيعمل باسم', 'It will act as')} <span className="font-semibold text-text-primary">{user.name}</span>
                {user.email ? <span dir="ltr" className="text-text-muted"> ({user.email})</span> : null}
              </p>
            )}
            <ul className="space-y-2 text-sm">
              <li className="flex gap-2"><Eye size={16} aria-hidden className="mt-0.5 shrink-0 text-brand-600" />{tx('يقرأ مشاريعك وحملاتك ومحتواك وقواعدك، بصلاحياتك أنت فقط.', 'Read your projects, campaigns, content and rules — only what you can see.')}</li>
              <li className="flex gap-2"><Check size={16} aria-hidden className="mt-0.5 shrink-0 text-brand-600" />{tx('يقترح تعديلات تصلك في «الموافقات» لتعتمدها أو ترفضها.', 'Propose changes that wait for you in «Approvals».')}</li>
              <li className="flex gap-2"><X size={16} aria-hidden className="mt-0.5 shrink-0 text-danger" />{tx('لا يغيّر أي حملة أو ميزانية بنفسه.', 'Never changes a campaign or a budget by itself.')}</li>
            </ul>
            <p className="text-xs text-text-muted">
              {tx('تقدر تلغي الربط متى ما بغيت من «ربط MCP» في إعدادات حسابك.', 'You can disconnect any time from «MCP» in your account settings.')}{' '}
              <Link to="/account/ai" className="font-semibold text-brand-700 hover:underline">{tx('ربط MCP', 'MCP')}</Link>
            </p>
            {failed && <p className="text-sm text-danger" data-testid="mcp-consent-failed">{tx('تعذّر إكمال الربط. حاول مرة ثانية.', 'Could not complete the connection. Try again.')}</p>}
            <div className="flex gap-2">
              <Button className="flex-1" onClick={allow} disabled={busy} data-testid="mcp-consent-allow">{busy ? tx('جارٍ الربط…', 'Connecting…') : tx('سماح', 'Allow')}</Button>
              <Button variant="secondary" onClick={deny} disabled={busy} data-testid="mcp-consent-deny">{tx('إلغاء', 'Cancel')}</Button>
            </div>
          </>
        )}
      </section>
    </main>
  )
}
