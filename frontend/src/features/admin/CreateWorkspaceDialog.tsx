import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Copy } from 'lucide-react'
import { createWorkspace, fetchPlans, type OwnerInvitation } from './api'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { toApiError } from '@/lib/api/client'

/**
 * SUPERADMIN-WORKSPACE-001 — «+ إضافة مساحة عمل».
 *
 * Company, workspace name, owner email, plan and modules. The owner is invited, never given a password; the one-time link comes back
 * once, here, so it can be handed over when mail does not arrive. Creating the same company again duplicates nothing.
 */

const MODULES = [
  { key: 'paid_media', ar: 'الإعلانات المدفوعة', en: 'Paid media' },
  { key: 'influencer_marketing', ar: 'تسويق المؤثرين', en: 'Influencer marketing' },
] as const

export function CreateWorkspaceDialog({ open, onClose, ar }: { open: boolean; onClose: () => void; ar: boolean }) {
  const qc = useQueryClient()
  const [company, setCompany] = useState('')
  const [workspace, setWorkspace] = useState('')
  const [email, setEmail] = useState('')
  const [plan, setPlan] = useState('')
  const [modules, setModules] = useState<string[]>(['paid_media', 'influencer_marketing'])

  const plans = useQuery({ queryKey: ['admin', 'plans'], queryFn: fetchPlans, enabled: open })
  const create = useMutation({
    mutationFn: () => createWorkspace({
      company_name: company.trim(),
      workspace_name: workspace.trim() || undefined,
      owner_email: email.trim(),
      plan: plan || undefined,
      modules,
    }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['admin', 'tenants'] }),
  })

  const close = () => {
    create.reset()
    setCompany(''); setWorkspace(''); setEmail(''); setPlan(''); setModules(['paid_media', 'influencer_marketing'])
    onClose()
  }

  const toggle = (key: string) => setModules((m) => (m.includes(key) ? m.filter((k) => k !== key) : [...m, key]))
  const field = 'h-10 w-full min-w-0 rounded-lg border border-border bg-surface px-3 text-sm text-text-primary outline-none focus-visible:ring-2 focus-visible:ring-brand-600'

  return (
    <Modal open={open} onClose={close} title={ar ? 'إضافة مساحة عمل' : 'Add a workspace'}>
      {create.data ? (
        <div data-testid="create-workspace-result" className="flex flex-col gap-3 text-sm">
          <p className="font-semibold text-text-primary">
            {create.data.created
              ? (ar ? `أُنشئت «${create.data.tenant.name}».` : `«${create.data.tenant.name}» was created.`)
              : (ar ? 'هذه المساحة موجودة مسبقًا؛ لم يُكرَّر شيء.' : 'This workspace already existed; nothing was duplicated.')}
          </p>
          {create.data.owner_has_access && <p className="text-text-secondary">{ar ? 'المالك لديه وصول بالفعل.' : 'The owner already has access.'}</p>}
          {create.data.invitation && <InvitationLink invitation={create.data.invitation} ar={ar} />}
          {!create.data.invitation && !create.data.owner_has_access && (
            <p className="text-text-secondary">{ar ? 'دعوة سابقة للمالك ما زالت سارية. أعد إصدارها من صفحة المستأجر إن لزم.' : 'An earlier owner invitation is still live. Reissue it from the tenant if needed.'}</p>
          )}
          <div className="flex justify-end"><Button size="sm" onClick={close}>{ar ? 'تم' : 'Done'}</Button></div>
        </div>
      ) : (
        <form data-testid="create-workspace-form" className="flex flex-col gap-3" onSubmit={(e) => { e.preventDefault(); create.mutate() }}>
          <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
            {ar ? 'اسم الشركة' : 'Company name'}
            <input data-testid="cw-company" required minLength={2} value={company} onChange={(e) => setCompany(e.target.value)} className={field} />
          </label>
          <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
            {ar ? 'اسم المساحة (اختياري)' : 'Workspace name (optional)'}
            <input data-testid="cw-workspace" value={workspace} onChange={(e) => setWorkspace(e.target.value)} className={field} />
          </label>
          <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
            {ar ? 'بريد المالك' : 'Owner email'}
            <input data-testid="cw-email" required type="email" dir="ltr" value={email} onChange={(e) => setEmail(e.target.value)} className={field} />
          </label>
          <label className="flex flex-col gap-1 text-xs font-semibold text-text-secondary">
            {ar ? 'الخطة' : 'Plan'}
            <select data-testid="cw-plan" value={plan} onChange={(e) => setPlan(e.target.value)} className={field}>
              <option value="">{ar ? 'تجربة (افتراضي)' : 'Trial (default)'}</option>
              {(plans.data?.plans ?? []).filter((p) => p.is_active).map((p) => (
                <option key={p.id} value={p.code}>{ar ? (p.name_ar ?? p.name) : p.name}</option>
              ))}
            </select>
          </label>
          <fieldset className="flex flex-col gap-1.5 text-xs">
            <legend className="mb-1 font-semibold text-text-secondary">{ar ? 'الوحدات' : 'Modules'}</legend>
            {MODULES.map((m) => (
              <label key={m.key} className="flex items-center gap-2 text-sm text-text-primary">
                <input data-testid={`cw-module-${m.key}`} type="checkbox" checked={modules.includes(m.key)} onChange={() => toggle(m.key)} />
                {ar ? m.ar : m.en}
              </label>
            ))}
          </fieldset>
          {create.isError && <p data-testid="create-workspace-error" className="text-xs font-semibold text-danger">{toApiError(create.error).message}</p>}
          <div className="flex justify-end gap-2">
            <Button type="button" size="sm" variant="secondary" onClick={close}>{ar ? 'إلغاء' : 'Cancel'}</Button>
            <Button data-testid="cw-submit" type="submit" size="sm" disabled={create.isPending || modules.length === 0}>
              {create.isPending ? (ar ? 'جارٍ الإنشاء…' : 'Creating…') : (ar ? 'إنشاء ودعوة المالك' : 'Create and invite the owner')}
            </Button>
          </div>
        </form>
      )}
    </Modal>
  )
}

/** The one-time owner link, with its delivery state. Shown once; copied by hand when mail did not go. */
export function InvitationLink({ invitation, ar }: { invitation: OwnerInvitation; ar: boolean }) {
  const [copied, setCopied] = useState(false)
  const sent = invitation.delivery_status === 'sent'

  const copy = async () => {
    if (!invitation.accept_link) return
    try {
      await navigator.clipboard.writeText(invitation.accept_link)
      setCopied(true)
      window.setTimeout(() => setCopied(false), 1500)
    } catch {
      setCopied(false)
    }
  }

  return (
    <div data-testid="owner-invitation" data-delivery={invitation.delivery_status ?? ''} className="flex min-w-0 flex-col gap-2 rounded-xl bg-surface-secondary p-3 text-xs">
      <p className={sent ? 'text-[var(--positive-foreground)]' : 'text-text-secondary'}>
        {sent
          ? (ar ? 'أُرسلت دعوة المالك بالبريد.' : 'The owner invitation was emailed.')
          : (ar ? 'لم يُؤكَّد إرسال البريد. انسخ الرابط وسلّمه للمالك بنفسك.' : 'Delivery was not confirmed. Copy the link and hand it to the owner yourself.')}
      </p>
      {invitation.accept_link && (
        <div className="flex items-start gap-2">
          <p data-testid="owner-invitation-link" className="min-w-0 flex-1 break-all font-mono text-text-primary" dir="ltr">{invitation.accept_link}</p>
          <button type="button" data-testid="owner-invitation-copy" onClick={() => void copy()}
            className="flex shrink-0 items-center gap-1 rounded-lg border border-border px-2 py-1 font-semibold text-text-secondary hover:text-text-primary">
            {copied ? <Check size={12} /> : <Copy size={12} />} {copied ? (ar ? 'نُسخ' : 'Copied') : (ar ? 'نسخ' : 'Copy')}
          </button>
        </div>
      )}
      <p className="text-text-muted">
        {ar
          ? `رابط لاستخدام واحد، صالح ${invitation.expires_in_hours ?? 72} ساعة. لن يُعرض مرة أخرى.`
          : `Single use, valid ${invitation.expires_in_hours ?? 72} hours. It will not be shown again.`}
      </p>
    </div>
  )
}
