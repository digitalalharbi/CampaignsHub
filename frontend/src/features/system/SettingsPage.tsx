import { Settings, Shield, BellRing, Webhook } from 'lucide-react'
import { Card, CardTitle, CardDescription } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { useT } from '@/lib/i18n'
import { useProject } from '@/stores/project'
import { EmptyState } from '@/components/ui/States'

export function SettingsPage() {
  const t = useT()
  const { currentProjectId } = useProject()

  if (!currentProjectId) {
    return <EmptyState title={t('no_project_selected')} />
  }

  return (
    <section className="space-y-6">
      <div>
        <h1 className="font-[var(--font-heading)] text-xl font-extrabold">{t('settings') || 'Settings'}</h1>
        <p className="mt-1 text-[13px] text-text-secondary">Manage project preferences and configurations.</p>
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <Card className="p-5">
          <div className="flex items-start gap-4">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-surface-secondary text-text-primary">
              <Settings size={20} />
            </div>
            <div>
              <CardTitle>General Settings</CardTitle>
              <div className="mt-1 leading-relaxed text-sm text-text-secondary">
                <CardDescription>
                Update project name, description, and currency settings.
                </CardDescription>
              </div>
              <div className="mt-4"><Button variant="secondary">Configure</Button></div>
            </div>
          </div>
        </Card>

        <Card className="p-5">
          <div className="flex items-start gap-4">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-surface-secondary text-text-primary">
              <Shield size={20} />
            </div>
            <div>
              <CardTitle>Permissions & Roles</CardTitle>
              <div className="mt-1 leading-relaxed text-sm text-text-secondary">
                <CardDescription>
                Manage who has access to this project and their capabilities.
                </CardDescription>
              </div>
              <div className="mt-4"><Button variant="secondary">Configure</Button></div>
            </div>
          </div>
        </Card>

        <Card className="p-5">
          <div className="flex items-start gap-4">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-surface-secondary text-text-primary">
              <BellRing size={20} />
            </div>
            <div>
              <CardTitle>Alert Preferences</CardTitle>
              <div className="mt-1 leading-relaxed text-sm text-text-secondary">
                <CardDescription>
                Set up automated alerts for budget pacing and KPIs.
                </CardDescription>
              </div>
              <div className="mt-4"><Button variant="secondary">Configure</Button></div>
            </div>
          </div>
        </Card>

        <Card className="p-5">
          <div className="flex items-start gap-4">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-surface-secondary text-text-primary">
              <Webhook size={20} />
            </div>
            <div>
              <CardTitle>Webhooks</CardTitle>
              <div className="mt-1 leading-relaxed text-sm text-text-secondary">
                <CardDescription>
                Configure outgoing webhooks for external system integrations.
                </CardDescription>
              </div>
              <div className="mt-4"><Button variant="secondary">Configure</Button></div>
            </div>
          </div>
        </Card>
      </div>
    </section>
  )
}
