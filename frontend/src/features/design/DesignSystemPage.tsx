import { useState } from 'react'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardDescription, CardTitle } from '@/components/ui/Card'
import { Checkbox } from '@/components/ui/Checkbox'
import { DataTable, type Column } from '@/components/ui/DataTable'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Modal } from '@/components/ui/Modal'
import { Select } from '@/components/ui/Select'
import { Switch } from '@/components/ui/Switch'
import { Tabs, TabPanel } from '@/components/ui/Tabs'
import { Textarea } from '@/components/ui/Textarea'
import { EmptyState, ErrorState, NoPermission, Skeleton } from '@/components/ui/States'

interface Lead {
  id: string
  name: string
  source: string
  value: number
  status: string
}

const leads: Lead[] = [
  { id: '1', name: 'Acme Co', source: 'Website', value: 12000, status: 'Qualified' },
  { id: '2', name: 'Nova Retail', source: 'Referral', value: 8400, status: 'New' },
  { id: '3', name: 'Zahra Store', source: 'WhatsApp', value: 21000, status: 'Proposal Sent' },
  { id: '4', name: 'Falcon Media', source: 'Event', value: 5600, status: 'Negotiation' },
]

const statusTone = (s: string) =>
  s === 'Qualified' ? 'success' : s === 'Negotiation' ? 'warning' : s === 'New' ? 'info' : 'neutral'

export function DesignSystemPage() {
  const [tab, setTab] = useState('components')
  const [modalOpen, setModalOpen] = useState(false)
  const [notify, setNotify] = useState(true)

  const columns: Column<Lead>[] = [
    { key: 'name', header: 'Client', sortable: true },
    { key: 'source', header: 'Source', sortable: true },
    {
      key: 'value',
      header: 'Est. value (SAR)',
      align: 'end',
      sortable: true,
      render: (r) => <span className="tnum">{r.value.toLocaleString('en-US')}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      render: (r) => <Badge tone={statusTone(r.status)}>{r.status}</Badge>,
    },
  ]

  return (
    <section className="space-y-6">
      <div>
        <h1 className="font-[var(--font-heading)] text-xl font-extrabold">Design System</h1>
        <p className="mt-1 text-sm text-text-secondary">
          Reusable, token-driven components. Toggle theme/language from the top bar to preview both modes.
        </p>
      </div>

      <Tabs
        items={[
          { key: 'components', label: 'Components' },
          { key: 'table', label: 'Data Table' },
          { key: 'states', label: 'States' },
        ]}
        active={tab}
        onChange={setTab}
      />

      {tab === 'components' && (
        <TabPanel>
          <div className="grid gap-4 md:grid-cols-2">
            <Card>
              <CardTitle>Buttons</CardTitle>
              <div className="mt-3 flex flex-wrap gap-2">
                <Button>Primary</Button>
                <Button variant="secondary">Secondary</Button>
                <Button variant="ghost">Ghost</Button>
                <Button variant="danger">Danger</Button>
                <Button loading>Loading</Button>
              </div>
            </Card>

            <Card>
              <CardTitle>Badges</CardTitle>
              <div className="mt-3 flex flex-wrap gap-2">
                <Badge tone="success">Active</Badge>
                <Badge tone="warning">Pending</Badge>
                <Badge tone="danger">Failed</Badge>
                <Badge tone="info">Syncing</Badge>
                <Badge>Neutral</Badge>
              </div>
            </Card>

            <Card>
              <CardTitle>Form controls</CardTitle>
              <div className="mt-3 space-y-3">
                <Field label="Campaign name" required>
                  <Input placeholder="Summer launch" />
                </Field>
                <Field label="Platform">
                  <Select
                    placeholder="Choose platform"
                    options={[
                      { value: 'meta', label: 'Meta' },
                      { value: 'tiktok', label: 'TikTok' },
                      { value: 'google', label: 'Google Ads' },
                    ]}
                  />
                </Field>
                <Field label="Notes" hint="Optional brief for the media buyer">
                  <Textarea placeholder="Objective, audience, budget…" />
                </Field>
                <div className="flex items-center gap-6">
                  <Checkbox id="terms" label="Approved by client" defaultChecked />
                  <Switch id="notify" checked={notify} onCheckedChange={setNotify} label="Email alerts" />
                </div>
              </div>
            </Card>

            <Card>
              <CardTitle>Alerts & overlay</CardTitle>
              <div className="mt-3 space-y-2">
                <Alert severity="positive" title="Campaign approved">Ready for launch.</Alert>
                <Alert severity="warning" title="Budget 80% consumed">Review pacing.</Alert>
                <Alert severity="danger" title="Token expired">Reconnect the ad account.</Alert>
                <Button variant="secondary" onClick={() => setModalOpen(true)}>
                  Open modal
                </Button>
              </div>
            </Card>
          </div>
        </TabPanel>
      )}

      {tab === 'table' && (
        <TabPanel>
          <DataTable columns={columns} rows={leads} rowKey={(r) => r.id} emptyTitle="No leads yet" />
        </TabPanel>
      )}

      {tab === 'states' && (
        <TabPanel>
          <div className="grid gap-4 md:grid-cols-2">
            <Card>
              <CardTitle>Loading</CardTitle>
              <div className="mt-3 space-y-2">
                <Skeleton className="h-4 w-3/4" />
                <Skeleton className="h-4 w-full" />
                <Skeleton className="h-4 w-1/2" />
              </div>
            </Card>
            <EmptyState title="No campaigns yet" description="Create your first campaign to see it here." />
            <ErrorState title="Failed to load" description="A network error occurred." onRetry={() => {}} />
            <NoPermission />
          </div>
          <div className="mt-4">
            <Card>
              <CardTitle>KPI card</CardTitle>
              <CardDescription>Metric with trend & data freshness</CardDescription>
              <div className="mt-3 flex items-end justify-between">
                <div>
                  <p className="text-xs text-text-muted">ROAS</p>
                  <p className="font-[var(--font-heading)] text-2xl font-extrabold tnum">3.84x</p>
                </div>
                <Badge tone="success">+12%</Badge>
              </div>
            </Card>
          </div>
        </TabPanel>
      )}

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title="Confirm launch"
        footer={
          <>
            <Button variant="secondary" onClick={() => setModalOpen(false)}>
              Cancel
            </Button>
            <Button onClick={() => setModalOpen(false)}>Confirm</Button>
          </>
        }
      >
        This is a focus-trapped, escape-closable modal. Launching a campaign always requires an explicit
        human confirmation and a documented permission.
      </Modal>
    </section>
  )
}
