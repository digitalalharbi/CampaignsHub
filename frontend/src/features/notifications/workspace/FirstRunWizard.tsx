import { useState } from 'react'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { useSaveWorkspaceSettings, type WorkspaceSettingsData } from './api'
import { RecipientWizard } from './RecipientWizard'
import { useTypeWords } from './typeWords'
import { Labelled, TextBox } from './ui'

/**
 * First run for a new workspace of any type (spec §0): the workspace already works on defaults, so this only asks the two
 * things a default cannot know — who the responsible recipient is (the type's responsible role: admin / owner / account
 * owner) and which domains belong to the organisation. Skipping leaves the defaults in place and asks again next visit.
 */
export function FirstRunWizard({ data, userEmail }: { data: WorkspaceSettingsData; userEmail?: string }) {
  const type = data.workspace.type
  const { t, lang } = useTypeWords(type)
  const save = useSaveWorkspaceSettings()
  const [open, setOpen] = useState(true)
  const [domains, setDomains] = useState('')
  const [recipient, setRecipient] = useState(false)
  const role = type.role_labels[type.responsible_role]?.[lang] ?? type.responsible_role
  const finish = () => save.mutate(
    { org_domains: domains.split(',').map((d) => d.trim().toLowerCase()).filter(Boolean), settings: { first_run_completed: true } },
    { onSuccess: () => setOpen(false) },
  )

  if (recipient) return <RecipientWizard key="first-run" open data={data} editing={null} userEmail={userEmail} onClose={() => { setRecipient(false); finish() }} />

  return (
    <Modal open={open} onClose={() => setOpen(false)} size="md" title={t('firstRunTitle')}
      footer={<><Button variant="ghost" onClick={() => setOpen(false)} data-testid="first-run-skip">{t('firstRunSkip')}</Button>
        <Button loading={save.isPending} onClick={finish} data-testid="first-run-done">{t('firstRunDone')}</Button></>}>
      <div data-testid="first-run" className="space-y-3">
        <p className="text-sm text-text-secondary">{t('firstRunText')}</p>
        <Button variant="secondary" onClick={() => setRecipient(true)} data-testid="first-run-recipient">{t('firstRunResponsible', { role })}</Button>
        <Labelled label={t('orgDomains')} hint={t('orgDomainsHint')}><TextBox dir="ltr" data-testid="first-run-domains" placeholder="example.com" value={domains} onChange={(e) => setDomains(e.target.value)} /></Labelled>
      </div>
    </Modal>
  )
}
