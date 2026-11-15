import { ListChecks, ScrollText, SlidersHorizontal, Users } from 'lucide-react'
import { useSearchParams } from 'react-router-dom'
import { TabStrip } from '@/components/fit/TabStrip'
import type { WorkspaceSettingsData } from './api'
import { EventsTab, GeneralSettingsTab, LogTab } from './ExternalTabs'
import { RecipientsTab } from './RecipientsTab'
import { useTypeWords } from './typeWords'

const TABS = ['recipients', 'events', 'settings', 'log'] as const

export function ExternalSection({ data, userEmail, isOwner }: { data: WorkspaceSettingsData; userEmail?: string; isOwner: boolean }) {
  const { t } = useTypeWords(data.workspace.type)
  const [params, setParams] = useSearchParams()
  const asked = params.get('tab2') ?? ''
  const tab = (TABS as readonly string[]).includes(asked) ? asked : 'recipients'
  const setTab = (k: string) => { const p = new URLSearchParams(params); p.set('tab2', k); setParams(p, { replace: true }) }

  return (
    <div data-testid="external-section" className="flex min-h-0 flex-1 flex-col gap-2.5">
      <TabStrip label={t('external')} testid="external-tabs" active={tab} onChange={setTab}
        items={[
          { key: 'recipients', label: t('tRecipients'), icon: Users },
          { key: 'events', label: t('tEvents'), icon: ListChecks },
          { key: 'settings', label: t('tSettings'), icon: SlidersHorizontal },
          { key: 'log', label: t('tLog'), icon: ScrollText },
        ]} />
      {tab === 'recipients' && <RecipientsTab data={data} userEmail={userEmail} isOwner={isOwner} />}
      {tab === 'events' && <EventsTab data={data} />}
      {tab === 'settings' && <GeneralSettingsTab data={data} />}
      {tab === 'log' && <LogTab data={data} />}
    </div>
  )
}
