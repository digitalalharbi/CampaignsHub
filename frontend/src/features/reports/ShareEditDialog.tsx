import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { updateShare, type ShareEdit, type ShareRow } from './api'

/**
 * REPORT-LINK-EDIT-IN-PLACE-001 — change a published client link without changing its URL.
 *
 * A link already sent to a client is a promise that the address keeps working. So changing what it shows is
 * an EDIT, not a new link: the same URL, the same token, the new setting from the next time it is opened.
 * Only what the Owner actually touched is sent, so saving never quietly resets a setting they did not look at.
 */
const COPY = {
  ar: {
    title: 'تعديل الرابط', hint: 'الرابط نفسه يبقى كما هو — يظهر التغيير للعميل عند فتحه المرة القادمة.',
    download: 'السماح بالتنزيل', watermark: 'علامة مائية', spend: 'إخفاء الإنفاق', revenue: 'إخفاء الإيرادات', names: 'إخفاء أسماء الحملات',
    accountNames: 'إظهار أسماء الحسابات الإعلانية (أسماء تقنية — مخفية افتراضيًا)', form: 'مستوى التقرير', formAsIs: 'كما هو التقرير', formExec: 'ملخص تنفيذي', formDetailed: 'تقرير تفصيلي',
    password: 'كلمة مرور جديدة', clearPassword: 'إزالة كلمة المرور', expiry: 'تاريخ انتهاء جديد', clearExpiry: 'بلا انتهاء',
    previous: 'إظهار مقارنة الفترة السابقة للعميل', save: 'حفظ التعديل', cancel: 'إلغاء', protectedNow: 'محمي بكلمة مرور حالياً',
  },
  en: {
    title: 'Edit link', hint: 'The link itself stays exactly as it is — the client sees the change the next time they open it.',
    download: 'Allow download', watermark: 'Watermark', spend: 'Hide spend', revenue: 'Hide revenue', names: 'Hide campaign names',
    accountNames: 'Show ad-account names (technical names — hidden by default)', form: 'Report level', formAsIs: 'As the report is', formExec: 'Executive summary', formDetailed: 'Detailed report',
    password: 'New password', clearPassword: 'Remove the password', expiry: 'New expiry date', clearExpiry: 'Never expires',
    previous: 'Show the previous-period comparison to the client', save: 'Save changes', cancel: 'Cancel', protectedNow: 'Currently password-protected',
  },
}

export function ShareEditDialog({ projectId, reportId, share, onClose, onSaved }: { projectId: string; reportId: string; share: ShareRow; onClose: () => void; onSaved: () => void }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = ar ? COPY.ar : COPY.en
  const [edit, setEdit] = useState<ShareEdit>({})
  const [password, setPassword] = useState('')
  const [expiry, setExpiry] = useState('')

  const value = <K extends 'allow_download' | 'watermark' | 'hide_spend' | 'hide_revenue' | 'hide_campaign_names'>(k: K): boolean => (edit[k] ?? share[k]) as boolean
  const toggle = (k: 'allow_download' | 'watermark' | 'hide_spend' | 'hide_revenue' | 'hide_campaign_names') => setEdit((e) => ({ ...e, [k]: !value(k) }))

  const save = useMutation({
    mutationFn: () => updateShare(projectId, reportId, share.id, {
      ...edit,
      ...(password !== '' ? { password } : {}),
      ...(expiry !== '' ? { expires_at: expiry } : {}),
    }),
    onSuccess: () => { onSaved(); onClose() },
  })

  const Row = ({ k, label }: { k: 'allow_download' | 'watermark' | 'hide_spend' | 'hide_revenue' | 'hide_campaign_names'; label: string }) => (
    <label className="flex cursor-pointer items-center justify-between rounded-xl border border-border px-3 py-2 text-sm">
      <span>{label}</span>
      <input type="checkbox" data-testid={`edit-${k}`} checked={value(k)} onChange={() => toggle(k)} className="h-4 w-4 accent-brand-600" />
    </label>
  )

  return (
    <Modal open onClose={onClose} title={t.title} size="md">
      <div data-testid="share-edit" className="space-y-3">
        <p className="text-xs text-text-muted">{t.hint}</p>
        <div className="grid gap-2 sm:grid-cols-2">
          <Row k="allow_download" label={t.download} />
          <Row k="watermark" label={t.watermark} />
          <Row k="hide_spend" label={t.spend} />
          <Row k="hide_revenue" label={t.revenue} />
          <Row k="hide_campaign_names" label={t.names} />
        </div>
        {/* REPORT-CLIENT-ACCOUNT-NAMES-OFF-001 — technical ad-account names are off for a client unless the Owner says so. */}
        <label className="flex cursor-pointer items-center justify-between rounded-xl border border-border px-3 py-2 text-sm">
          <span>{t.accountNames}</span>
          <input
            type="checkbox"
            data-testid="edit-account-names"
            checked={edit.sections?.account_names ?? share.sections?.account_names ?? false}
            onChange={(e) => setEdit((x) => ({ ...x, sections: { ...(x.sections ?? {}), account_names: e.target.checked } }))}
            className="h-4 w-4 accent-brand-600"
          />
        </label>
        <label className="flex cursor-pointer items-center justify-between rounded-xl border border-border px-3 py-2 text-sm">
          <span>{t.previous}</span>
          <input
            type="checkbox"
            data-testid="edit-previous-comparison"
            checked={edit.sections?.previous_comparison ?? share.sections?.previous_comparison ?? false}
            onChange={(e) => setEdit((x) => ({ ...x, sections: { ...(x.sections ?? {}), previous_comparison: e.target.checked } }))}
            className="h-4 w-4 accent-brand-600"
          />
        </label>
        <label className="block text-xs font-semibold text-text-secondary">{t.form}
          <select data-testid="edit-form" value={(edit.form !== undefined ? edit.form : share.form ?? '') ?? ''} onChange={(e) => setEdit((x) => ({ ...x, form: (e.target.value || null) as ShareEdit['form'] }))} className="mt-1 w-full rounded-xl border border-border bg-surface px-3 py-2 text-sm">
            <option value="">{t.formAsIs}</option>
            <option value="executive_summary">{t.formExec}</option>
            <option value="detailed">{t.formDetailed}</option>
          </select>
        </label>
        <div className="grid gap-2 sm:grid-cols-2">
          <label className="block text-xs font-semibold text-text-secondary">{t.password}
            <input type="text" data-testid="edit-password" value={password} onChange={(e) => setPassword(e.target.value)} className="mt-1 w-full rounded-xl border border-border bg-surface px-3 py-2 text-sm" dir="ltr" />
          </label>
          <label className="block text-xs font-semibold text-text-secondary">{t.expiry}
            <input type="date" data-testid="edit-expiry" value={expiry} onChange={(e) => setExpiry(e.target.value)} className="mt-1 w-full rounded-xl border border-border bg-surface px-3 py-2 text-sm" dir="ltr" />
          </label>
        </div>
        <div className="flex flex-wrap gap-4 text-xs text-text-secondary">
          {share.password_protected && (
            <label className="flex items-center gap-1.5"><input type="checkbox" data-testid="edit-clear-password" checked={edit.clear_password === true} onChange={(e) => setEdit((x) => ({ ...x, clear_password: e.target.checked }))} /> {t.clearPassword} <span className="text-text-muted">({t.protectedNow})</span></label>
          )}
          {share.expires_at && (
            <label className="flex items-center gap-1.5"><input type="checkbox" data-testid="edit-clear-expiry" checked={edit.clear_expiry === true} onChange={(e) => setEdit((x) => ({ ...x, clear_expiry: e.target.checked }))} /> {t.clearExpiry}</label>
          )}
        </div>
        {save.isError && <p role="alert" className="text-xs text-danger">{toApiError(save.error).message}</p>}
        <div className="flex items-center gap-2">
          <Button loading={save.isPending} onClick={() => save.mutate()} data-testid="edit-save">{t.save}</Button>
          <button type="button" onClick={onClose} className="text-xs font-semibold text-text-secondary hover:underline">{t.cancel}</button>
        </div>
      </div>
    </Modal>
  )
}
