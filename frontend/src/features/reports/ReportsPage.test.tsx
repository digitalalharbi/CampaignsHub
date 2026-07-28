import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ReportsPage } from './ReportsPage'
import type { Option } from '@/components/forms'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'

// The report builder's type & audience selects must be fed by the taxonomy engine, not a hardcoded list.
const TAX: Record<string, Option[]> = {
  'report.type': [
    { value: 'executive', label_en: 'Executive', label_ar: 'تنفيذي' },
    { value: 'monthly', label_en: 'Monthly', label_ar: 'شهري' },
  ],
  'report.audience': [
    { value: 'client', label_en: 'Client', label_ar: 'العميل' },
    { value: 'internal', label_en: 'Internal', label_ar: 'داخلي' },
  ],
}

vi.mock('@/features/taxonomy/taxonomyApi', () => ({
  useTaxonomyOptions: (key: string) => ({
    options: TAX[key] ?? [],
    isPending: false,
    isError: false,
    refetch: vi.fn(),
  }),
}))

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listReports: vi.fn(), createReport: vi.fn() }
})

import { createReport, listReports } from './api'

describe('ReportsPage — engine-fed builder', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useProject.setState({ currentProjectId: 'p1' })
    vi.mocked(listReports).mockResolvedValue({
      reports: [],
      summary: { total: 0, completed: 0, processing: 0, failed: 0 },
    })
    signInWith(['reports.view', 'reports.create'])
  })
  afterEach(() => signOut())

  it('feeds the type & audience selects from the taxonomy engine (report.type / report.audience)', async () => {
    renderWithProviders(<ReportsPage />, { locale: 'en' })

    fireEvent.click(screen.getByText('تقرير جديد'))
    // Default keys come through the engine option labels — a value only present in the mocked hook.
    const typeSelect = await screen.findByRole('combobox', { name: 'نوع التقرير' })
    expect(typeSelect).toHaveTextContent('Executive')
    expect(screen.getByRole('combobox', { name: 'هذا التقرير موجّه إلى' })).toHaveTextContent('Client')

    // The engine's second option is selectable (proves the list is the mocked engine set).
    fireEvent.click(typeSelect)
    fireEvent.mouseDown(await screen.findByText('Monthly'))
    await waitFor(() => expect(typeSelect).toHaveTextContent('Monthly'))
  })

  it('submits the engine option KEYS unchanged (no 422)', async () => {
    vi.mocked(createReport).mockResolvedValue({} as never)
    renderWithProviders(<ReportsPage />, { locale: 'en' })

    fireEvent.click(screen.getByText('تقرير جديد'))
    await screen.findByRole('combobox', { name: 'نوع التقرير' })
    fireEvent.click(screen.getByText('إنشاء وتوليد'))

    await waitFor(() =>
      expect(createReport).toHaveBeenCalledWith(
        'p1',
        expect.objectContaining({ type: 'executive', audience: 'client' }),
      ),
    )
  })
})
