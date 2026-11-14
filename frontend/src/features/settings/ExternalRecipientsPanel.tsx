import { useState } from 'react'
import { useExternalBindableProjects, useExternalRecipientActions, useExternalRecipients, type ExternalCadence } from './api'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Checkbox } from '@/components/ui/Checkbox'
import { Alert } from '@/components/ui/Alert'
import { Skeleton } from '@/components/ui/States'

/**
 * SPEND-SUMMARY-EXTERNAL-RECIPIENTS-001 — recipients OUTSIDE the platform (a client's project manager, say), each bound to one
 * or more projects, who receive the remaining budget of those projects to follow up and renew with the client.
 *
 * The server decides everything that matters — who may manage this (`settings.manage`), which projects may be bound (this
 * workspace's, that the member can see), the cap and the address — and this screen only shows its answers. «أرسل تجربة لي»
 * reaches the signed-in member's own address, never the recipient's.
 */
export function ExternalRecipientsPanel({ ar }: { ar: boolean }) {
  const { data, isLoading, isError } = useExternalRecipients()
  const projects = useExternalBindableProjects().data ?? []
  const actions = useExternalRecipientActions()
  const [email, setEmail] = useState('')
  const [name, setName] = useState('')
  const [cadence, setCadence] = useState<ExternalCadence>('weekly')
  const [chosen, setChosen] = useState<string[]>([])
  const [message, setMessage] = useState<{ tone: 'positive' | 'danger'; text: string } | null>(null)

  const cadenceWord = (c: ExternalCadence) => (c === 'daily' ? (ar ? 'يومي' : 'Daily') : c === 'monthly' ? (ar ? 'شهري' : 'Monthly') : (ar ? 'أسبوعي' : 'Weekly'))
  const projectName = (id: string) => projects.find((p) => p.id === id)?.name ?? (ar ? 'مشروع' : 'Project')

  const add = async () => {
    setMessage(null)
    try {
      await actions.add.mutateAsync({ email: email.trim(), name: name.trim() || null, cadence, project_ids: chosen })
      setEmail('')
      setName('')
      setChosen([])
      setMessage({ tone: 'positive', text: ar ? 'تمت الإضافة' : 'Added' })
    } catch {
      setMessage({ tone: 'danger', text: ar ? 'ما تمت الإضافة: تأكد من البريد والمشاريع' : 'Not added: check the address and the projects' })
    }
  }

  const test = async () => {
    setMessage(null)
    try {
      await actions.test.mutateAsync({ cadence, project_ids: chosen.length > 0 ? chosen : undefined })
      setMessage({ tone: 'positive', text: ar ? 'أرسلنا نسخة تجربة لبريدك' : 'A test was sent to your address' })
    } catch {
      setMessage({ tone: 'danger', text: ar ? 'ما أرسلنا التجربة' : 'The test was not sent' })
    }
  }

  return (
    <div className="rounded-2xl border border-border bg-surface p-6 shadow-[var(--shadow-small)]" data-testid="external-recipients">
      <h3 className="text-base font-bold text-text-primary">{ar ? 'مستلمون من خارج المنصة' : 'Recipients outside the platform'}</h3>
      <p className="mt-1 max-w-2xl text-xs leading-6 text-text-secondary">
        {ar
          ? 'يوصلهم المتبقي من ميزانية مشاريعهم فقط، للمتابعة والتجديد مع العميل. بدون روابط للمنصة، ويقدرون يلغون الاشتراك بضغطة.'
          : 'They receive the remaining budget of their projects only, to follow up and renew with the client. No links into the platform; they can leave with one click.'}
      </p>

      {isLoading && <Skeleton className="mt-4 h-16" />}
      {isError && <p className="mt-4 text-xs text-text-muted">{ar ? 'هذا القسم لمدير المساحة.' : 'This section is for the workspace manager.'}</p>}

      {data && data.length > 0 && (
        <ul className="mt-4 divide-y divide-border rounded-xl border border-border" data-testid="external-recipient-list">
          {data.map((r) => (
            <li key={r.id} className="flex flex-wrap items-center gap-3 p-3 text-sm">
              <span className="min-w-0 flex-1 truncate font-semibold text-text-primary">{r.name ? `${r.name} · ` : ''}<bdi>{r.email}</bdi></span>
              <span className="text-xs text-text-secondary">{cadenceWord(r.cadence)} · {r.project_ids.map(projectName).join('، ')}</span>
              <Button size="sm" variant="ghost" onClick={() => actions.remove.mutate(r.id)} disabled={actions.remove.isPending}>{ar ? 'احذف' : 'Remove'}</Button>
            </li>
          ))}
        </ul>
      )}

      {data !== undefined && (
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          <Input value={email} onChange={(e) => setEmail(e.target.value)} placeholder={ar ? 'البريد' : 'Email'} aria-label={ar ? 'البريد' : 'Email'} type="email" dir="ltr" />
          <Input value={name} onChange={(e) => setName(e.target.value)} placeholder={ar ? 'الاسم (اختياري)' : 'Name (optional)'} aria-label={ar ? 'الاسم' : 'Name'} />
          <Select
            value={cadence}
            onChange={(e) => setCadence(e.target.value as ExternalCadence)}
            aria-label={ar ? 'التكرار' : 'Cadence'}
            options={(['daily', 'weekly', 'monthly'] as const).map((c) => ({ value: c, label: cadenceWord(c) }))}
          />
        </div>
      )}
      {data !== undefined && projects.length > 0 && (
        <div className="mt-3 flex flex-wrap gap-3" data-testid="external-recipient-projects">
          {projects.map((p) => (
            <Checkbox
              key={p.id}
              id={`external-project-${p.id}`}
              checked={chosen.includes(p.id)}
              onChange={(e) => setChosen(e.target.checked ? [...chosen, p.id] : chosen.filter((x) => x !== p.id))}
              label={p.name}
            />
          ))}
        </div>
      )}
      {data !== undefined && (
        <div className="mt-4 flex flex-wrap gap-2">
          <Button onClick={add} disabled={actions.add.isPending || email.trim() === '' || chosen.length === 0} data-testid="external-recipient-add">{ar ? 'أضف' : 'Add'}</Button>
          <Button variant="secondary" onClick={test} disabled={actions.test.isPending}>{ar ? 'أرسل تجربة لي' : 'Send me a test'}</Button>
        </div>
      )}
      {message && <div className="mt-3"><Alert severity={message.tone} title={message.text} /></div>}
    </div>
  )
}
