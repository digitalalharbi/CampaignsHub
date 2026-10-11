import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { RequestDetailPage } from './RequestDetailPage'
import type { RequestDetail } from './internalApi'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'

vi.mock('./internalApi', async (orig) => ({
  ...(await orig<typeof import('./internalApi')>()),
  getRequest: vi.fn(),
}))

import { getRequest } from './internalApi'

/**
 * REQ-DETAIL-LABELS-001 — the live review of /agency/requests/:requestId (2026-10-09) read «المسمى
 * الوظيفي» over the objective, «Email» in English on an Arabic page, «تنسيق الأرقام» over the
 * budget with the raw «45000.00 SAR» beside it, raw priority tokens in the priority select,
 * Arabic-only statuses for an English reader, and «paid» as the invoice status. Each is pinned here.
 */
function detail(): RequestDetail {
  return {
    id: 'r1', reference: 'REQ-DEMO-P1', service: 'paid_media', service_ar: 'إطلاق حملة إعلانية مدفوعة', module: 'paid_media',
    status: 'in_progress', status_label: 'قيد التنفيذ', status_label_en: 'In progress',
    priority: 'medium', priority_label: 'متوسطة', priority_label_en: 'Medium',
    contact: 'Acme', assignee: null, assigned_to: null, source: 'web', sla_due_at: null, sla_breached: false,
    submitted_at: null, last_activity_at: null,
    objective: 'إطلاق حملة اليوم الوطني', contact_email: 'client@campaignshub.io', contact_phone: null, company_name: 'Acme',
    budget: '45000.00', currency: 'SAR', metadata: null, service_details: null,
    sla: { due_at: null, started_at: null, paused_at: null, breached_at: null, remaining_seconds: null },
    comments: [], events: [], files: [], archived_at: null, conversion: null,
    billing: [{ quote_id: 'q1', number: 'Q-DEMO-1002', status: 'approved', total: '51750.00', currency: 'SAR', tax_treatment: null,
      invoice: { invoice_id: 'i1', number: 'INV-DEMO-2001', status: 'paid', total: '51750.00', amount_paid: '51750.00' } }],
  }
}

function render(locale: 'ar' | 'en') {
  return renderWithProviders(<RequestDetailPage />, { locale, route: '/app/requests/r1', path: '/app/requests/:requestId' })
}

describe('request detail labels', () => {
  beforeEach(() => { vi.clearAllMocks(); signInWith(['requests.view', 'requests.manage']); vi.mocked(getRequest).mockResolvedValue(detail()) })
  afterEach(() => signOut())

  it('names the objective, the email and the budget for what they are, in Arabic, and formats the budget as money', async () => {
    render('ar')
    await screen.findByText('REQ-DEMO-P1')

    expect(screen.getByText('الهدف')).toBeTruthy()
    expect(screen.getByText('البريد الإلكتروني')).toBeTruthy()
    expect(screen.getByText('الميزانية')).toBeTruthy()
    expect(screen.getByText('45,000 SAR')).toBeTruthy()
    expect(screen.queryByText('المسمى الوظيفي')).toBeNull()
    expect(screen.queryByText('تنسيق الأرقام')).toBeNull()
    expect(screen.queryByText('Email')).toBeNull()
    expect(screen.queryByText('45000.00 SAR')).toBeNull()

    // The priority choices and the invoice status are words, not stored tokens.
    expect(screen.getByRole('option', { name: 'حرجة' })).toBeTruthy()
    expect(screen.queryByRole('option', { name: 'critical' })).toBeNull()
    expect(screen.getByText('مدفوعة')).toBeTruthy()
    expect(screen.queryByText('paid')).toBeNull()
  })

  it('offers the status and priority choices in English to an English reader', async () => {
    render('en')
    await screen.findByText('REQ-DEMO-P1')

    expect(screen.getByRole('option', { name: 'Under review' })).toBeTruthy()
    expect(screen.queryByRole('option', { name: 'تحت المراجعة' })).toBeNull()
    expect(screen.getByRole('option', { name: 'High' })).toBeTruthy()
    expect(screen.getByText('Objective')).toBeTruthy()
    expect(screen.getByText('Budget')).toBeTruthy()
    expect(screen.getByText('Paid')).toBeTruthy()
  })
})
