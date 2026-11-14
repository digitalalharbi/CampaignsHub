import { useEffect, useMemo, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { DateField } from '@/components/ui/DateField'
import { listClients } from '@/features/clients/api'
import { listProjects } from '@/features/projects/api'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { useAddBudget } from './fundingApi'
import { L, sar } from './labels'

const QUICK = [5_000, 10_000, 20_000, 50_000]

const today = (): string => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const newKey = (): string =>
  typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`

/**
 * FIN-LOOP-001 — add a budget in ONE step: client, project, amount, date, reference, then one button.
 *
 * Client and project are typed or picked; a name that already exists is the same client or project on
 * the server, so nothing is doubled. Renewing from a project opens this with both fixed. The key that
 * makes a double click count once is made when the dialog opens and kept until it closes.
 */
export function AddBudgetDialog({
  open,
  onClose,
  fixed,
  defaults,
  onAdded,
}: {
  open: boolean
  onClose: () => void
  fixed?: { clientId: string; clientName: string; projectId: string; projectName: string }
  /** Smart defaults: the project the reader is looking at is already chosen. */
  defaults?: { clientName: string; projectName: string }
  onAdded?: (projectId: string, entryId?: string) => void
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const t = (k: keyof typeof L) => {
    const v = L[k] as { ar: unknown; en: unknown }
    return (ar ? v.ar : v.en) as string
  }
  const add = useAddBudget()
  const [client, setClient] = useState('')
  const [project, setProject] = useState('')
  const [amount, setAmount] = useState('')
  const [date, setDate] = useState(today())
  const [reference, setReference] = useState('')
  // The period: empty start means «from the first day of spend» (the server's default); an empty end means open.
  const [periodStart, setPeriodStart] = useState('')
  const [periodEnd, setPeriodEnd] = useState('')
  const [key, setKey] = useState(newKey)
  const [error, setError] = useState<string | null>(null)

  // Reset only when the dialog OPENS: a refetch while it is open must not wipe what was typed or the key.
  const wasOpen = useRef(false)
  useEffect(() => {
    const opening = open && !wasOpen.current
    wasOpen.current = open
    if (!opening) return
    setClient(fixed?.clientName ?? defaults?.clientName ?? '')
    setProject(fixed?.projectName ?? defaults?.projectName ?? '')
    setPeriodStart('')
    setPeriodEnd('')
    setAmount('')
    setDate(today())
    setReference('')
    setKey(newKey())
    setError(null)
  }, [open, fixed, defaults])

  const clients = useQuery({ queryKey: ['funding', 'client-options'], enabled: open && !fixed, queryFn: () => listClients({}) })
  const projects = useQuery({ queryKey: ['funding', 'project-options'], enabled: open && !fixed, queryFn: () => listProjects() })

  const clientId = useMemo(() => {
    const name = client.trim().toLowerCase()
    return clients.data?.data.find((c) => c.name.trim().toLowerCase() === name)?.id
  }, [client, clients.data])
  const projectOptions = useMemo(
    () => (projects.data ?? []).filter((p) => clientId !== undefined && p.client_workspace_id === clientId),
    [projects.data, clientId],
  )

  const value = Number(amount)
  // A renewal keeps the budget's own period; only a first amount states one.
  const period = fixed ? {} : { period_start: periodStart || null, period_end: periodEnd || null }
  const periodOk = !(periodStart && periodEnd && periodEnd < periodStart)

  const ready = client.trim() !== '' && project.trim() !== '' && value > 0 && date !== '' && periodOk

  const submit = () => {
    setError(null)
    add.mutate(
      fixed
        ? { client_workspace_id: fixed.clientId, project_id: fixed.projectId, amount: value, paid_on: date, reference: reference || undefined, idempotency_key: key }
        : { client_name: client.trim(), project_name: project.trim(), amount: value, paid_on: date, reference: reference || undefined, idempotency_key: key, ...period },
      {
        onSuccess: (res) => { onAdded?.(res.project_id, res.payment_id); onClose() },
        onError: (e) => setError(toApiError(e).message),
      },
    )
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={fixed ? t('renew') : t('add')}
      footer={
        <Button onClick={submit} disabled={!ready} loading={add.isPending} data-testid="budget-submit" className="w-full sm:w-auto">
          {ready ? (ar ? L.addAmount.ar(sar(value)) : L.addAmount.en(sar(value))) : t('add')}
        </Button>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        {fixed ? (
          <p className="min-w-0 truncate text-sm font-bold sm:col-span-2" data-testid="budget-fixed-target">
            {fixed.clientName} · {fixed.projectName}
          </p>
        ) : (
          <>
            <Field label={t('client')} htmlFor="budget-client" required>
              <Input id="budget-client" list="budget-client-options" value={client} onChange={(e) => setClient(e.target.value)} autoComplete="off" />
              <datalist id="budget-client-options">
                {(clients.data?.data ?? []).map((c) => <option key={c.id} value={c.name} />)}
              </datalist>
            </Field>
            <Field label={t('project')} htmlFor="budget-project" required>
              <Input id="budget-project" list="budget-project-options" value={project} onChange={(e) => setProject(e.target.value)} autoComplete="off" />
              <datalist id="budget-project-options">
                {projectOptions.map((p) => <option key={p.id} value={p.name} />)}
              </datalist>
            </Field>
          </>
        )}
        <Field label={`${t('amount')} (${t('sar')})`} htmlFor="budget-amount" required>
          <Input id="budget-amount" inputMode="decimal" dir="ltr" value={amount} onChange={(e) => setAmount(e.target.value.replace(/[^\d.]/g, ''))} />
          <div className="mt-1.5 flex flex-wrap gap-1.5">
            {QUICK.map((q) => (
              <button
                key={q}
                type="button"
                onClick={() => setAmount(String(q))}
                aria-pressed={value === q}
                className={`rounded-[8px] border px-2 py-1 text-xs font-semibold ${value === q ? 'border-brand-600 text-brand-600' : 'border-border text-text-secondary'}`}
              >
                {sar(q)}
              </button>
            ))}
          </div>
        </Field>
        <Field label={t('date')} htmlFor="budget-date" required>
          <DateField id="budget-date" value={date} onChange={setDate} />
        </Field>
        {!fixed && (
          <>
            <Field label={`${t('period')} · ${t('from')}`} htmlFor="budget-period-start">
              <DateField id="budget-period-start" value={periodStart} onChange={setPeriodStart} placeholder={ar ? 'من أول إنفاق' : 'From first spend'} />
            </Field>
            <Field label={`${t('period')} · ${t('to')}`} htmlFor="budget-period-end">
              <DateField id="budget-period-end" value={periodEnd} onChange={setPeriodEnd} placeholder={t('open')} />
            </Field>
          </>
        )}
        <div className="sm:col-span-2">
          <Field label={t('reference')} htmlFor="budget-reference">
            <Input id="budget-reference" value={reference} onChange={(e) => setReference(e.target.value)} maxLength={120} />
          </Field>
        </div>
        {error && <p role="alert" className="text-sm font-semibold text-danger sm:col-span-2">{error}</p>}
      </div>
    </Modal>
  )
}
