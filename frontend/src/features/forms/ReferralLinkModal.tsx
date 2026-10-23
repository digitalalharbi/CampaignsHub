import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Check, Copy } from 'lucide-react'
import { Modal } from '@/components/ui/Modal'
import { getData } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { useForms } from './api'

/**
 * FORMS-REFERRALS-001 — the link a lead can pass on: one of THIS project's published forms, with a signed code that names the lead.
 * The code carries an opaque id and no personal data; a lead that arrives through it knows who brought it.
 */
export function ReferralLinkModal({ projectId, leadId, onClose }: { projectId: string; leadId: string; onClose: () => void }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const forms = useForms(projectId)
  const published = (forms.data ?? []).filter((f) => f.status === 'published')
  const [formId, setFormId] = useState('')
  const [copied, setCopied] = useState(false)
  const link = useQuery({
    queryKey: ['referral-link', projectId, leadId, formId],
    queryFn: () => getData<{ url: string }>(`/projects/${projectId}/leads/${leadId}/referral-link?form=${formId}`),
    enabled: formId !== '',
  })

  const copy = async () => {
    if (!link.data) return
    try { await navigator.clipboard.writeText(link.data.url); setCopied(true); setTimeout(() => setCopied(false), 1500) } catch { /* the address is shown for copying by hand */ }
  }

  return (
    <Modal open onClose={onClose} title={ar ? 'رابط الإحالة' : 'Referral link'}>
      <div className="space-y-3" data-testid="referral-modal">
        <p className="text-xs text-text-secondary">{ar ? 'يمرّر العميل هذا الرابط لمن يعرفه. كل من يرسل النموذج عبره يُسجَّل كمُحال منه.' : 'The lead passes this on. Anyone who sends the form through it is recorded as referred by them.'}</p>
        {forms.isSuccess && published.length === 0 && <p className="text-sm text-text-secondary" data-testid="referral-no-forms">{ar ? 'لا نماذج منشورة في مشروع هذا العميل.' : 'There is no published form in this lead’s project.'}</p>}
        {published.length > 0 && (
          <select className="w-full rounded-lg border border-border bg-surface px-2 py-1.5 text-sm" value={formId} onChange={(e) => setFormId(e.target.value)} aria-label={ar ? 'النموذج' : 'Form'} data-testid="referral-form">
            <option value="">{ar ? 'اختر النموذج…' : 'Choose a form…'}</option>
            {published.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
          </select>
        )}
        {link.data && (
          <div className="flex items-center gap-2">
            <code className="min-w-0 flex-1 truncate rounded-lg bg-surface-secondary px-2 py-1 text-xs" dir="ltr" data-testid="referral-url">{link.data.url}</code>
            <button type="button" onClick={copy} className="inline-flex items-center gap-1 rounded-lg border border-border px-2 py-1 text-xs font-semibold" data-testid="referral-copy">
              {copied ? <Check size={14} aria-hidden /> : <Copy size={14} aria-hidden />} {copied ? (ar ? 'نُسخ' : 'Copied') : (ar ? 'نسخ' : 'Copy')}
            </button>
          </div>
        )}
        {link.isError && <p role="alert" className="text-xs text-danger">{ar ? 'تعذّر إنشاء الرابط.' : 'The link could not be made.'}</p>}
      </div>
    </Modal>
  )
}
