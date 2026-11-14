import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { ensureCsrfCookie, postData } from '@/lib/api/client'
import { toApiError } from '@/lib/api/client'
import { listUsers } from '@/features/projects/api'
import { usePortalPath } from '@/app/portalPath'
import { useUi } from '@/stores/ui'

/**
 * CLIENT-UNIT-001 — «عميل جديد»: ONE form that makes the client, its internal project and its portal.
 *
 * Three questions, because those are the three things the server needs and nothing else: the name, the currency money
 * is read in, and who manages it. The internal project is made in the same request (`create_project`), so a client can
 * never exist without the project its campaigns will live in, and it stays out of sight until a second one is added.
 * Nothing about ids, routes or the isolation between projects changes — it is the ordinary project, flagged `internal`.
 */
const CURRENCIES = ['SAR', 'USD', 'AED', 'KWD', 'QAR', 'BHD', 'OMR', 'EGP', 'EUR', 'GBP']

interface Created { id: string; slug: string; project_id: string | null; portal_path: string }

export function NewClientDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const navigate = useNavigate()
  const portalTo = usePortalPath()
  const [name, setName] = useState('')
  const [currency, setCurrency] = useState('SAR')
  const [manager, setManager] = useState('')
  const [reason, setReason] = useState('')
  const users = useQuery({ queryKey: ['tenant-users'], queryFn: listUsers, enabled: open })

  const create = useMutation({
    mutationFn: async () => {
      await ensureCsrfCookie()
      return postData<Created>('/client-workspaces', {
        name: name.trim(),
        mode: 'managed',
        default_currency: currency,
        account_manager_id: manager === '' ? null : Number(manager),
        create_project: true,
      })
    },
    onSuccess: (client) => {
      setName(''); setManager(''); setReason('')
      onClose()
      navigate(portalTo(`/clients/${client.id}`))
    },
    onError: (e) => setReason(toApiError(e).message),
  })

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={ar ? 'عميل جديد' : 'New client'}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>{ar ? 'إلغاء' : 'Cancel'}</Button>
          <Button data-testid="new-client-submit" loading={create.isPending} disabled={name.trim() === ''} onClick={() => { setReason(''); create.mutate() }}>
            {ar ? 'أضف' : 'Add'}
          </Button>
        </>
      }
    >
      <form className="flex flex-col gap-4" data-testid="new-client-form" onSubmit={(e) => { e.preventDefault(); if (name.trim() !== '' && !create.isPending) { setReason(''); create.mutate() } }}>
        <Field label={ar ? 'الاسم' : 'Name'} htmlFor="new-client-name" required>
          <Input id="new-client-name" data-autofocus data-testid="new-client-name" value={name} onChange={(e) => setName(e.target.value)} maxLength={160} />
        </Field>
        <Field label={ar ? 'العملة' : 'Currency'} htmlFor="new-client-currency">
          <Select id="new-client-currency" data-testid="new-client-currency" value={currency} onChange={(e) => setCurrency(e.target.value)} options={CURRENCIES.map((c) => ({ value: c, label: c }))} />
        </Field>
        <Field label={ar ? 'مدير المشروع' : 'Project manager'} htmlFor="new-client-manager">
          <Select
            id="new-client-manager"
            data-testid="new-client-manager"
            value={manager}
            onChange={(e) => setManager(e.target.value)}
            options={[{ value: '', label: ar ? 'بدون' : 'None' }, ...(users.data ?? []).map((u) => ({ value: String(u.id), label: u.name }))]}
          />
        </Field>
        {reason !== '' && <p role="alert" data-testid="new-client-error" className="text-sm text-danger">{reason}</p>}
      </form>
    </Modal>
  )
}
