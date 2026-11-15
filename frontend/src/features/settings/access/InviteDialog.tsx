import { useMemo, useState } from 'react'
import { Plus, Send } from 'lucide-react'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Alert } from '@/components/ui/Alert'
import { toApiError } from '@/lib/api/client'
import { clients as countedClients } from '@/lib/counted'
import { canAssign, diffLevels, levelsOf, useAccessActions, type AccessData, type Level, type SectionStates } from './accessApi'
import { AreaChips, AreaToggles, ChipPicker } from './AreaControls'
import { PORTAL_ROLE_LABEL } from './labels'
import { SectionToggles } from './SectionToggles'

type Audience = 'team' | 'client'

/**
 * ACCESS-CENTER-001 — ONE «دعوة» for every audience.
 *
 * لمن؟ → البريد → الفئة (chips) → «+ تخصيص» (areas for THIS invitation only) → العملاء → «ماذا سيرى» → إرسال.
 * The team audience goes to `/settings/access/invitations`; the client audience to the client's own portal-users
 * endpoint, with its own three roles — a client portal's functions are never changed from here.
 */
export function InviteDialog({ open, onClose, data, ar }: { open: boolean; onClose: () => void; data: AccessData; ar: boolean }) {
  const { invite, invitePortal } = useAccessActions()
  const [audience, setAudience] = useState<Audience>('team')
  const [email, setEmail] = useState('')
  const firstAssignable = data.categories.find((c) => c.kind !== 'owner' && canAssign(c, data.me))?.slug ?? ''
  const [category, setCategory] = useState(firstAssignable)
  const [custom, setCustom] = useState(false)
  const [levels, setLevels] = useState<Record<string, Level>>({})
  const [clients, setClients] = useState<string[]>([])
  // ACCESS-SECTIONS-001 — sections switched for THIS invitation only; the category decides the rest.
  const [sectionsOff, setSectionsOff] = useState<SectionStates>({})
  const [portalClient, setPortalClient] = useState('')
  const [portalRole, setPortalRole] = useState('client_viewer')
  const [err, setErr] = useState('')
  const [sent, setSent] = useState<string | null>(null)

  const cat = data.categories.find((c) => c.slug === category)
  const base = useMemo(() => levelsOf(cat?.areas), [cat])
  const chosen = { ...base, ...levels }
  const overrides = diffLevels(base, chosen)

  const pickCategory = (slug: string) => { setCategory(slug); setLevels({}) }
  const reset = () => { setEmail(''); setLevels({}); setCustom(false); setClients([]); setSectionsOff({}); setErr(''); setSent(null) }

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setErr('')
    try {
      if (audience === 'team') {
        await invite.mutateAsync({ email, category, areas: overrides, sections: sectionsOff, ...(clients.length ? { client_ids: clients } : {}) })
      } else {
        await invitePortal.mutateAsync({ client: portalClient, email, role: portalRole })
      }
      setSent(email)
      setEmail('')
    } catch (e2) { setErr(toApiError(e2).message) }
  }

  const pending = invite.isPending || invitePortal.isPending
  const canSend = email.includes('@') && (audience === 'team' ? category !== '' : portalClient !== '')

  return (
    <Modal open={open} onClose={() => { reset(); onClose() }} title={ar ? 'دعوة' : 'Invite'} size="lg">
      <form onSubmit={submit} className="space-y-4" data-testid="invite-dialog">
        {err && <Alert severity="danger" title={ar ? 'لم تُرسل الدعوة' : 'Not sent'}>{err}</Alert>}
        {sent && <Alert severity="positive" title={ar ? 'أُرسلت الدعوة' : 'Invitation sent'}><span dir="ltr">{sent}</span></Alert>}

        <Step n={1} title={ar ? 'لمن؟' : 'Who for?'}>
          <ChipPicker<Audience> testId="invite-audience" label={ar ? 'لمن؟' : 'Who for?'} value={audience} onChange={setAudience}
            options={[
              { value: 'team', label: ar ? 'فريق العمل' : 'Team' },
              { value: 'client', label: ar ? 'عميل (بوابة العميل)' : 'Client (portal)', disabled: data.clients.length === 0 },
            ]} />
        </Step>

        <Step n={2} title={ar ? 'البريد' : 'Email'}>
          <Input id="access-invite-email" type="email" dir="ltr" value={email} onChange={(e) => setEmail(e.target.value)} required data-testid="invite-email" aria-label={ar ? 'البريد' : 'Email'} />
        </Step>

        {audience === 'team' ? (
          <>
            <Step n={3} title={ar ? 'الفئة' : 'Category'}>
              <ChipPicker testId="invite-category" label={ar ? 'الفئة' : 'Category'} value={category} onChange={pickCategory}
                options={data.categories.map((c) => ({ value: c.slug, label: ar ? c.name_ar : c.name_en, disabled: !canAssign(c, data.me) }))} />
              {!custom ? (
                <button type="button" onClick={() => setCustom(true)} data-testid="invite-customise"
                  className="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-brand-700 hover:underline">
                  <Plus size={13} aria-hidden /> {ar ? 'تخصيص لهذه الدعوة' : 'Customise for this invitation'}
                </button>
              ) : (
                <div className="mt-2 space-y-2">
                  {(data.sections ?? []).length > 0 && (
                    <SectionToggles sections={data.sections ?? []} ar={ar} testId="invite-sections"
                      states={Object.fromEntries((data.sections ?? []).map((x) => [x.key, sectionsOff[x.key] ?? true]))}
                      onChange={(k, on) => setSectionsOff((x) => { const next = { ...x }; if (on) delete next[k]; else next[k] = false; return next })} />
                  )}
                  <AreaToggles areas={data.areas} levels={chosen} base={base} grantable={data.me.grantable} ar={ar}
                    onChange={(area, lv) => setLevels((l) => ({ ...l, [area]: lv }))} />
                </div>
              )}
            </Step>

            {data.agency && data.clients.length > 0 && (
              <Step n={4} title={ar ? 'العملاء' : 'Clients'}>
                <div className="flex flex-wrap gap-1.5" data-testid="invite-clients">
                  {data.clients.map((c) => {
                    const on = clients.includes(c.id)
                    return (
                      <button key={c.id} type="button" aria-pressed={on} data-testid={`invite-client-${c.id}`}
                        onClick={() => setClients((xs) => on ? xs.filter((x) => x !== c.id) : [...xs, c.id])}
                        className={`rounded-full border px-3 py-1 text-xs font-semibold ${on ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-border text-text-secondary'}`}>
                        {c.name}
                      </button>
                    )
                  })}
                </div>
                <p className="mt-1 text-xs text-text-muted">
                  {clients.length > 0
                    ? (ar ? `يرى ${countedClients(clients.length, 'ar')} فقط` : `Sees ${countedClients(clients.length, 'en')} only`)
                    : cat?.all_clients ? (ar ? 'يرى كل العملاء' : 'Sees every client') : (ar ? 'لا يرى أي عميل حتى تختار' : 'Sees no client until you pick')}
                </p>
              </Step>
            )}

            <Step n={data.agency && data.clients.length > 0 ? 5 : 4} title={ar ? 'ماذا سيرى' : 'What they will see'}>
              <AreaChips areas={data.areas} levels={chosen} ar={ar} changed={Object.keys(overrides)} testId="invite-preview" />
            </Step>
          </>
        ) : (
          <>
            <Step n={3} title={ar ? 'العميل' : 'Client'}>
              <ChipPicker testId="invite-portal-client" label={ar ? 'العميل' : 'Client'} value={portalClient} onChange={setPortalClient}
                options={data.clients.map((c) => ({ value: c.id, label: c.name }))} />
            </Step>
            <Step n={4} title={ar ? 'الدور في البوابة' : 'Portal role'}>
              <ChipPicker testId="invite-portal-role" label={ar ? 'الدور' : 'Role'} value={portalRole} onChange={setPortalRole}
                options={data.portal_roles.map((r) => ({ value: r, label: ar ? (PORTAL_ROLE_LABEL[r]?.ar ?? r) : (PORTAL_ROLE_LABEL[r]?.en ?? r) }))} />
              <p className="mt-1 text-xs text-text-muted">{ar ? 'بوابة العميل تبقى كما هي؛ الدور يحدد العرض والاعتماد فقط.' : 'The portal stays as it is; the role only sets viewing and approving.'}</p>
            </Step>
          </>
        )}

        <div className="flex justify-end">
          <Button type="submit" disabled={!canSend || pending} data-testid="invite-send">
            <Send size={14} aria-hidden /> {pending ? '…' : (ar ? 'إرسال' : 'Send')}
          </Button>
        </div>
      </form>
    </Modal>
  )
}

function Step({ n, title, children }: { n: number; title: string; children: React.ReactNode }) {
  return (
    <section>
      <h3 className="mb-1.5 flex items-center gap-2 text-sm font-bold text-text-primary">
        <span className="tnum inline-flex size-5 items-center justify-center rounded-full bg-surface-secondary text-xs">{n.toLocaleString('en-US')}</span>
        {title}
      </h3>
      {children}
    </section>
  )
}
