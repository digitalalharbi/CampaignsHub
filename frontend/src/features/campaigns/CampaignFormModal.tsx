import { useEffect, useMemo } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createCampaign, updateCampaign, type CampaignInput } from './api'
import { objectiveLabel } from './labels'
import { CAMPAIGN_OBJECTIVES, type UnifiedCampaign } from './types'
import { listUsers } from '@/features/projects/api'
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

const CURRENCIES = ['SAR', 'USD', 'AED', 'EGP', 'KWD', 'BHD', 'QAR']

interface Props {
  open: boolean
  onClose: () => void
  projectId: string
  campaign?: UnifiedCampaign | null
}

export function CampaignFormModal({ open, onClose, projectId, campaign }: Props) {
  const t = useT()
  const locale = useUi((s) => s.locale)
  const queryClient = useQueryClient()
  const isEdit = Boolean(campaign)

  const schema = useMemo(
    () =>
      z
        .object({
          name: z.string().trim().min(1, t('error')).max(160),
          objective: z.string(),
          total_budget: z
            .string()
            .refine((v) => v === '' || (!Number.isNaN(Number(v)) && Number(v) >= 0), t('error')),
          budget_currency: z.string().length(3),
          starts_on: z.string(),
          ends_on: z.string(),
          audience: z.string(),
          owner_id: z.string(),
        })
        .refine((d) => !d.starts_on || !d.ends_on || d.ends_on >= d.starts_on, {
          path: ['ends_on'],
          message: t('error'),
        }),
    [t],
  )

  type FormValues = z.infer<typeof schema>

  const defaults: FormValues = useMemo(
    () => ({
      name: campaign?.name ?? '',
      objective: campaign?.objective ?? 'other',
      total_budget: campaign?.total_budget != null ? String(campaign.total_budget) : '',
      budget_currency: campaign?.budget_currency ?? 'SAR',
      starts_on: campaign?.starts_on ?? '',
      ends_on: campaign?.ends_on ?? '',
      audience: campaign?.audience ?? '',
      owner_id: campaign?.owner_id != null ? String(campaign.owner_id) : '',
    }),
    [campaign],
  )

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: defaults })

  // Reset the form whenever the modal opens or the edited campaign changes.
  useEffect(() => {
    if (open) reset(defaults)
  }, [open, defaults, reset])

  const users = useQuery({ queryKey: ['users'], queryFn: () => listUsers(), enabled: open })

  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const payload: CampaignInput = {
        name: values.name,
        objective: values.objective,
        total_budget: values.total_budget === '' ? null : Number(values.total_budget),
        budget_currency: values.budget_currency,
        starts_on: values.starts_on || null,
        ends_on: values.ends_on || null,
        audience: values.audience || null,
        owner_id: values.owner_id === '' ? null : Number(values.owner_id),
      }
      return isEdit ? updateCampaign(projectId, campaign!.id, payload) : createCampaign(projectId, payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project', projectId, 'campaigns'] })
      reset(defaults)
      onClose()
    },
    onError: (err) => {
      // Surface server-side (422) validation errors on the matching fields.
      const apiError = toApiError(err)
      if (apiError.errors) {
        for (const [field, messages] of Object.entries(apiError.errors)) {
          if (field in defaults) setError(field as keyof FormValues, { message: messages[0] })
        }
      }
    },
  })

  const apiError = mutation.isError ? toApiError(mutation.error) : null

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={isEdit ? t('edit_campaign') : t('new_campaign')}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            {t('cancel')}
          </Button>
          <Button loading={mutation.isPending} onClick={handleSubmit((v) => mutation.mutate(v))}>
            {t('save')}
          </Button>
        </>
      }
    >
      <form className="space-y-3" onSubmit={handleSubmit((v) => mutation.mutate(v))}>
        {apiError && !apiError.errors && <Alert severity="danger" title={apiError.message} />}

        <Field label={t('campaign_name')} htmlFor="campaign-name" required error={errors.name?.message}>
          <Input id="campaign-name" {...register('name')} data-autofocus />
        </Field>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label={t('objective_field')} htmlFor="campaign-objective" error={errors.objective?.message}>
            <Select
              id="campaign-objective"
              {...register('objective')}
              options={CAMPAIGN_OBJECTIVES.map((o) => ({ value: o, label: objectiveLabel(o, locale) }))}
            />
          </Field>
          <Field label={t('owner_label')} htmlFor="campaign-owner">
            <Select
              id="campaign-owner"
              {...register('owner_id')}
              placeholder="—"
              options={(users.data ?? []).map((u) => ({ value: String(u.id), label: u.name }))}
            />
          </Field>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label={t('budget_label')} htmlFor="campaign-budget" error={errors.total_budget?.message}>
            <Input id="campaign-budget" type="number" min="0" step="0.01" {...register('total_budget')} />
          </Field>
          <Field label={t('currency_label')} htmlFor="campaign-currency" error={errors.budget_currency?.message}>
            <Select id="campaign-currency" {...register('budget_currency')} options={CURRENCIES.map((c) => ({ value: c, label: c }))} />
          </Field>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label={t('start_date')} htmlFor="campaign-start" error={errors.starts_on?.message}>
            <Input id="campaign-start" type="date" {...register('starts_on')} />
          </Field>
          <Field label={t('end_date')} htmlFor="campaign-end" error={errors.ends_on?.message}>
            <Input id="campaign-end" type="date" {...register('ends_on')} />
          </Field>
        </div>

        <Field label={t('audience_label')} htmlFor="campaign-audience">
          <Textarea id="campaign-audience" rows={3} {...register('audience')} />
        </Field>
      </form>
    </Modal>
  )
}
