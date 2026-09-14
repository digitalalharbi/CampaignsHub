import { expect, test } from '@playwright/test'
import { API_HEADERS, AUTH, csrfHeaders, seededProject, selectProject } from './helpers'

/**
 * REPORT-SUMMARY-DECISION-001 — a summary and a full report are different DOCUMENTS.
 *
 * The composition is decided in the backend and the renderer draws whatever `config.slides` holds,
 * so the backend suite proves the list. What it cannot prove is that the two forms produce visibly
 * different documents for a reader — that a summary actually ends on what to do, and that it is
 * still shorter than the full report rather than quietly becoming it.
 *
 * Built through the API rather than the builder UI: this case is about what a report IS, and
 * driving a modal to assert a composition would make it a test about the modal.
 */
const sections = (slides: Array<{ type: string; visible?: boolean }>) =>
  slides.filter((s) => s.visible !== false).map((s) => s.type)

async function generate(request: import('@playwright/test').APIRequestContext, projectId: string, form: string) {
  const created = await request.post(`/api/v1/projects/${projectId}/reports`, {
    // A write needs the CSRF header the app itself sends; API_HEADERS alone is a read contract.
    headers: await csrfHeaders(request),
    data: {
      name: `E2E ${form} ${Date.now()}`,
      type: 'monthly',
      form,
      audience: 'internal',
      period_start: '2026-06-01',
      period_end: '2026-06-30',
      currency: 'SAR',
    },
  })
  expect(created.ok(), `creating the ${form} report failed: ${created.status()} ${await created.text()}`).toBe(true)
  const id = (await created.json()).data.id as string

  await expect
    .poll(
      async () => {
        const r = await request.get(`/api/v1/projects/${projectId}/reports/${id}`, { headers: API_HEADERS })
        return r.ok() ? (await r.json()).data.status : 'error'
      },
      { timeout: 90_000, message: `the ${form} report never finished generating` },
    )
    .toBe('completed')

  const detail = await request.get(`/api/v1/projects/${projectId}/reports/${id}`, { headers: API_HEADERS })
  return (await detail.json()).data.config.slides as Array<{ type: string; visible?: boolean }>
}

test.describe('what the two report forms produce', () => {
  test.use({ storageState: AUTH.owner })
  test.describe.configure({ timeout: 180_000 })

  test('a summary ends on what to do, and stays shorter than the full report', async ({ page, request }) => {
    const projectId = await seededProject(request, 'متجر تجريبي — Demo')
    await selectProject(page, projectId)

    const summary = sections(await generate(request, projectId, 'executive_summary'))
    const detailed = sections(await generate(request, projectId, 'detailed'))

    // The owner's summary contract names both by name: concise recommendations, concise next actions.
    expect(summary, 'a summary with no recommendations is a diagnosis with no prescription').toContain('recommendations')
    expect(summary).toContain('next_steps')

    // And it is still a SUMMARY: the depth belongs to the full report.
    expect(summary).not.toContain('platform_performance')
    expect(summary).not.toContain('data_quality')
    expect(summary).not.toContain('campaigns')
    expect(summary.length).toBeLessThan(detailed.length)

    // The full report is a superset — nothing a summary shows is missing from it.
    for (const type of summary) expect(detailed, `«${type}» is in the summary but not the full report`).toContain(type)

    // Campaign analysis is the depth the owner asked for, and it is internal-only by construction.
    expect(detailed).toContain('campaigns')
  })
})
