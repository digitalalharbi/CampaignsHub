import { ensureCsrfCookie, getData, postData } from '@/lib/api/client'

export interface Connector {
  key: string
  label: string
  status: 'awaiting_credentials' | 'connected' | 'error' | 'disconnected'
  ad_account_id: string | null
  last_synced_at: string | null
  last_sync_error: string | null
}

export function listConnectors(): Promise<Connector[]> {
  return getData<Connector[]>('/integrations')
}

export async function connectConnector(key: string): Promise<{ key: string; status: string }> {
  await ensureCsrfCookie()
  return postData<{ key: string; status: string }>(`/integrations/${key}/connect`)
}

export async function syncConnector(key: string): Promise<{ success: boolean; count: number }> {
  await ensureCsrfCookie()
  return postData<{ success: boolean; count: number }>(`/integrations/${key}/sync`)
}
