import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { createLead } from './api'
import { sourceLabel } from './labels'
import { LEAD_SOURCES } from './types'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { toApiError } from '@/lib/api/client'
import { useT } from '@/lib/i18n'
import { useUi } from '@/stores/ui'

export function NewLeadModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const t = useT()
  const locale = useUi((s) => s.locale)
  const queryClient = useQueryClient()

  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [phone, setPhone] = useState('')
  const [source, setSource] = useState('manual')
  const [value, setValue] = useState('')
  const [notes, setNotes] = useState('')

  const reset = () => {
    setName('')
    setEmail('')
    setPhone('')
    setSource('manual')
    setValue('')
    setNotes('')
  }

  const mutation = useMutation({
    mutationFn: createLead,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['leads'] })
      reset()
      onClose()
    },
  })

  const error = mutation.isError ? toApiError(mutation.error) : null

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('new_lead')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('cancel')}
          </Button>
          <Button
            loading={mutation.isPending}
            onClick={() =>
              mutation.mutate({
                name,
                email: email || undefined,
                phone: phone || undefined,
                source,
                estimated_value: value ? Number(value) : undefined,
                notes: notes || undefined,
              })
            }
          >
            {t('save')}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        {error && !error.errors && <Alert severity="danger" title={error.message} />}
        <Field label={t('name')} required error={error?.errors?.name?.[0]}>
          <Input value={name} onChange={(e) => setName(e.target.value)} data-autofocus />
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label={t('email')} error={error?.errors?.email?.[0]}>
            <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
          </Field>
          <Field label={t('phone')}>
            <Input value={phone} onChange={(e) => setPhone(e.target.value)} />
          </Field>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label={t('source_label')}>
            <Select
              value={source}
              onChange={(e) => setSource(e.target.value)}
              options={LEAD_SOURCES.map((s) => ({ value: s, label: sourceLabel(s, locale) }))}
            />
          </Field>
          <Field label={`${t('value_label')} (SAR)`} error={error?.errors?.estimated_value?.[0]}>
            <Input type="number" min="0" value={value} onChange={(e) => setValue(e.target.value)} />
          </Field>
        </div>
        <Field label={t('notes')}>
          <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} />
        </Field>
      </div>
    </Modal>
  )
}
