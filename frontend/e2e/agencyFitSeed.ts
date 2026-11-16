import type { APIRequestContext } from '@playwright/test'
import { csrfHeaders } from './helpers'

/**
 * AGENCY-FIT-001 — a full estate for the fit specs.
 *
 * The seeded workspace holds a handful of budgets and limits, which fits on any screen and proves nothing about a
 * page that has to PAGE. This adds enough projects (under the seeded clients, named «AF1 fit n») with a budget each
 * that a 1366×768 board has more rows than it can show, and takes the budgets away again afterwards (a void, with a
 * reason; the projects stay, which no other spec reads). Every call goes through the real endpoints.
 */
export const SEED_PREFIX = 'AF1 fit '

export interface SeededBudget { projectId: string }

export async function seedBudgets(request: APIRequestContext, count: number): Promise<SeededBudget[]> {
  const headers = await csrfHeaders(request)
  const clients = (await (await request.get('/api/v1/app/clients', { headers })).json()).data as { id: string }[]
  const today = new Date().toISOString().slice(0, 10)
  const stamp = Date.now().toString(36)
  const out: SeededBudget[] = []

  for (let i = 0; i < count; i++) {
    const res = await request.post('/api/v1/funding/payments', {
      headers,
      data: {
        client_workspace_id: clients[i % clients.length]!.id,
        project_name: `${SEED_PREFIX}${i + 1} ${stamp}`,
        amount: 10_000 + i * 500,
        payment_mode: 'direct',
        paid_on: today,
        idempotency_key: `af1-fit-${stamp}-${i}`,
      },
    })
    if (res.ok()) out.push({ projectId: (await res.json()).data.project_id as string })
  }

  return out
}

export async function voidBudgets(request: APIRequestContext, seeded: SeededBudget[]): Promise<void> {
  const headers = await csrfHeaders(request)
  for (const b of seeded) {
    await request.post(`/api/v1/funding/projects/${b.projectId}/void`, { headers, data: { reason: 'AF1 fit spec clean-up' } })
  }
}

const PLATFORMS = ['meta', 'tiktok', 'snapchat', 'google', 'x', 'linkedin', 'chatgpt_ads'] as const

/** Spend limits on a project: the whole project, then one per platform — as many as asked for. Returns their ids. */
export async function seedLimits(request: APIRequestContext, projectId: string, count: number): Promise<string[]> {
  const headers = await csrfHeaders(request)
  const ids: string[] = []
  for (let i = 0; i < count; i++) {
    const platform = i === 0 ? null : PLATFORMS[(i - 1) % PLATFORMS.length]!
    const res = await request.post(`/api/v1/projects/${projectId}/spend-limits`, {
      headers,
      data: {
        scope: platform === null ? 'project' : 'platform',
        ...(platform === null ? {} : { scope_id: platform }),
        amount: 5_000 + i * 750,
        currency: 'SAR',
        starts_on: '2026-11-01',
        ends_on: '2026-11-30',
        thresholds: [80],
      },
    })
    const id = res.ok() ? (await res.json()).data?.id : null
    if (typeof id === 'string') ids.push(id)
  }

  return ids
}

export async function removeLimits(request: APIRequestContext, projectId: string, ids: string[]): Promise<void> {
  const headers = await csrfHeaders(request)
  for (const id of ids) await request.delete(`/api/v1/projects/${projectId}/spend-limits/${id}`, { headers })
}
