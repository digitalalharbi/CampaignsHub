import { describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { SchedulesPanel } from './SchedulesPanel'
import { renderWithProviders, signInWith } from '@/test/utils'

vi.mock('./api', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, listSchedules: vi.fn() }
})

import { listSchedules, type ReportScheduleRow } from './api'

const row = (over: Partial<ReportScheduleRow>): ReportScheduleRow => ({
  id: 's1', name: 'تقرير أسبوعي', type: 'executive', frequency: 'weekly', day: 'sunday', time: '08:00', timezone: 'Asia/Riyadh',
  audience: 'client', language: 'ar', formats: ['pdf'], recipients: [], cron: null, report_id: null, active: true, is_demo: false,
  last_run_at: null, next_run_at: null, deliveries: {}, ...over,
})

/** CONS-SCHEDULE-ONE — a copy switched off as a duplicate reads «نسخة غير مجدولة», not «موقوفة». */
describe('SchedulesPanel — unscheduled copies', () => {
  it('labels a disabled duplicate as an unscheduled copy and the kept one as active', async () => {
    signInWith(['reports.view', 'reports.export'])
    vi.mocked(listSchedules).mockResolvedValue([
      row({ id: 'keep' }),
      row({ id: 'copy', active: false, disabled_reason: 'duplicate_schedule', duplicate_of: 'keep' }),
    ])
    renderWithProviders(<SchedulesPanel projectId="p1" />, { locale: 'ar' })

    expect(await screen.findByTestId('schedule-copy-copy')).toHaveTextContent('نسخة غير مجدولة')
    expect(screen.queryByTestId('schedule-copy-keep')).not.toBeInTheDocument()
    expect(screen.getByText('مفعّلة')).toBeInTheDocument()
  })
})
