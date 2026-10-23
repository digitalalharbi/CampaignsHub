import { expect, test } from '@playwright/test'
import { AUTH, API_HEADERS, csrfHeaders, seededProject } from './helpers'

/**
 * FORMS-HUB-001 / FORMS-REFERRALS-001 — the chain, crossing the real edge, in a real browser.
 *
 * A form is built and published through the API as the team would, then a STRANGER (a fresh browser context with no
 * session) opens its public address on a phone, consents and sends it. A lead appears, exactly one, in the project's Leads
 * Centre. Then the SIGNED REFERRAL LINK of that lead is followed by a second stranger, and the lead it brings is assigned
 * to the form's owner and the funnel counts one referral.
 *
 * Synthetic names only. No conversion destination is created, so nothing is reported anywhere.
 *
 * This spec exists because the referral code was verified by the server and covered by unit tests while the PUBLIC PAGE
 * never read it from its address: a referral link produced an unreferred lead, and only walking the whole chain saw it.
 */
test.describe('a lead form, from the team to a stranger and back', () => {
  test.use({ storageState: AUTH.owner })

  test('a submission becomes one lead, and a referral link brings a referred one', async ({ request, browser }) => {
    test.setTimeout(180_000)

    const stamp = Date.now().toString(36)
    const projectId = await seededProject(request, 'Q3 Launch — Demo')
    const headers = await csrfHeaders(request)

    const definition = {
      steps: [{ id: 's1', title: 'You', fields: [
        { id: 'full_name', type: 'text', label: { en: 'Name', ar: 'الاسم' }, maps_to: 'name' },
        { id: 'mobile', type: 'phone', label: { en: 'Mobile', ar: 'الجوال' }, maps_to: 'phone' },
      ] }],
      consent: { required: true, text: { en: 'May we contact you?', ar: 'هل نتواصل معك؟' } },
    }

    // The owner of new leads: the signed-in person themselves (the seeded agency owner), so «assigned» is provable.
    const users = (await (await request.get('/api/v1/users', { headers: API_HEADERS })).json()).data as Array<{ id: number; email: string }>
    const ownerId = users.find((u) => u.email === 'agency@campaignshub.io')?.id
    expect(ownerId, 'the signed-in owner is in the workspace user list').toBeTruthy()

    const created = await request.post(`/api/v1/projects/${projectId}/forms`, {
      headers,
      data: { name: `E2E enquiry ${stamp}`, definition, default_owner_id: ownerId, follow_up_minutes: 30 },
    })
    expect(created.status(), await created.text()).toBe(201)
    const form = (await created.json()).data as { id: string; slug: string }

    const published = await request.post(`/api/v1/projects/${projectId}/forms/${form.id}/publish`, { headers })
    expect(published.status()).toBe(200)

    // A stranger on a phone: no session at all.
    const stranger = await browser.newContext({ viewport: { width: 390, height: 844 }, baseURL: new URL(created.url()).origin })
    const first = await stranger.newPage()
    await first.goto(`/f/${form.slug}?utm_source=e2e&utm_campaign=chain-${stamp}`)
    await first.getByTestId('field-full_name').fill(`E2E Referrer ${stamp}`)
    await first.getByTestId('field-mobile').fill('0500012345')
    await first.getByTestId('form-next').click()
    await expect(first.getByTestId('form-consent-error'), 'consent is asked for before anything is sent').toBeVisible()
    await first.getByTestId('form-consent').check()
    await first.getByTestId('form-next').click()
    await expect(first.getByTestId('public-form-thanks')).toBeVisible({ timeout: 30_000 })

    // Exactly one lead, in this project, given to the form's owner on arrival.
    const leadsOf = async () => ((await (await request.get(`/api/v1/leads?project_id=${projectId}&per_page=100`, { headers: API_HEADERS })).json()).data as Array<{ id: string; name: string; owner_id: number | null; status: string }>)
    const found = (await leadsOf()).filter((l) => l.name === `E2E Referrer ${stamp}`)
    expect(found, 'exactly one lead for one submission').toHaveLength(1)
    const referrer = found[0]
    expect(referrer.owner_id).toBe(ownerId)

    // The referral link of THAT lead, for THAT form — signed, naming no person.
    const link = await request.get(`/api/v1/projects/${projectId}/leads/${referrer.id}/referral-link?form=${form.id}`, { headers: API_HEADERS })
    expect(link.status()).toBe(200)
    const url = new URL((await link.json()).data.url as string)
    expect(url.searchParams.get('ref')).toMatch(/^[a-f0-9]{32}\.[a-f0-9]{8}$/)
    expect(url.toString()).not.toContain('Referrer')

    // A second stranger follows it.
    const second = await stranger.newPage()
    await second.goto(`${url.pathname}${url.search}`)
    await second.getByTestId('field-full_name').fill(`E2E Referred ${stamp}`)
    await second.getByTestId('field-mobile').fill('0500012346')
    await second.getByTestId('form-consent').check()
    await second.getByTestId('form-next').click()
    await expect(second.getByTestId('public-form-thanks')).toBeVisible({ timeout: 30_000 })

    const referred = (await leadsOf()).filter((l) => l.name === `E2E Referred ${stamp}`)
    expect(referred, 'one lead for the referred submission').toHaveLength(1)
    expect(referred[0].owner_id, 'assigned to the form’s owner on arrival').toBe(ownerId)
    expect(referred[0].status).toBe('assigned')

    // The funnel counts the referral and both leads. (The Leads Centre's API does not expose the referrer id; the funnel's own
    // count of referred leads, built from `referred_by_lead_id`, is the proof here, and the backend suite reads the column itself.)
    const funnel = (await (await request.get(`/api/v1/projects/${projectId}/forms/${form.id}/funnel`, { headers: API_HEADERS })).json()).data as { leads: { received: number; referred: number } }
    expect(funnel.leads.received).toBe(2)
    expect(funnel.leads.referred).toBe(1)

    // A code that does not verify never costs the lead.
    const third = await stranger.newPage()
    await third.goto(`/f/${form.slug}?ref=${'a'.repeat(32)}.00000000`)
    await third.getByTestId('field-full_name').fill(`E2E Forged ${stamp}`)
    await third.getByTestId('field-mobile').fill('0500012347')
    await third.getByTestId('form-consent').check()
    await third.getByTestId('form-next').click()
    await expect(third.getByTestId('public-form-thanks')).toBeVisible({ timeout: 30_000 })
    const forged = (await leadsOf()).filter((l) => l.name === `E2E Forged ${stamp}`)
    expect(forged).toHaveLength(1)

    await stranger.close()

    // Leave nothing live behind: the form stops accepting submissions.
    await request.post(`/api/v1/projects/${projectId}/forms/${form.id}/archive`, { headers })
    const closed = await request.get(`/api/v1/public/forms/${form.slug}`, { headers: API_HEADERS })
    expect(closed.status()).toBe(404)
  })
})
