import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { ReportVersionHistory } from './ReportVersionHistory'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { useProject } from '@/stores/project'
import { fmtDateTime } from '@/lib/datetime'

const held = vi.hoisted(() => ({ list: ['reports.view', 'reports.manage'] as string[] | undefined }))
vi.mock('@/features/projects/capabilities', () => ({
  useProjectCapabilities: () => ({ capabilities: held.list, can: (c: string) => held.list === undefined || held.list.includes(c) }),
}))

vi.mock('./versionsApi', () => ({ listReportVersions: vi.fn(), restoreReportVersion: vi.fn() }))

import { listReportVersions, restoreReportVersion } from './versionsApi'

const report = { id: 'r1', name: 'August', status: 'completed' as const, generated_at: '2026-10-05T09:30:00+00:00' }
const versions = [
  { id: 'v2', generated_at: '2026-10-01T08:15:00+00:00', kept_at: '2026-10-05T09:30:00+00:00' },
  { id: 'v1', generated_at: '2026-09-01T10:00:00+00:00', kept_at: '2026-10-01T08:15:00+00:00' },
]

const open = async () => fireEvent.click(await screen.findByTestId('report-history-open-r1'))

/** REPORT-SNAPSHOT-PROTECT-001 — the screen for the copies a rebuild replaced. */
describe('the history of a report’s stored copies', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    held.list = ['reports.view', 'reports.manage']
    useProject.setState({ currentProjectId: 'p1' })
    signInWith(['reports.view'])
    vi.mocked(listReportVersions).mockResolvedValue(versions as never)
    vi.mocked(restoreReportVersion).mockResolvedValue({ generated_at: versions[1].generated_at } as never)
  })
  afterEach(() => {
    signOut()
    useProject.setState({ currentProjectId: null })
  })

  it('asks for nothing until it is opened, then lists each kept copy with both of its dates', async () => {
    renderWithProviders(<ReportVersionHistory report={report} />, { locale: 'ar' })
    expect(listReportVersions).not.toHaveBeenCalled()
    await open()

    const rows = await screen.findAllByTestId(/^report-version-v/)
    expect(rows).toHaveLength(2)
    expect(listReportVersions).toHaveBeenCalledWith('p1', 'r1')
    expect(rows[0]).toHaveTextContent(fmtDateTime(versions[0].generated_at))
    expect(rows[0]).toHaveTextContent(fmtDateTime(versions[0].kept_at))
    expect(rows[1]).toHaveTextContent(fmtDateTime(versions[1].generated_at))
    // Latin digits, whatever the language.
    expect(screen.getByTestId('report-history')).not.toHaveTextContent(/[٠-٩]/)
    expect(screen.getByTestId('report-history-current')).toHaveTextContent(fmtDateTime(report.generated_at))
  })

  it('restores only after a confirmation that says what happens to the current copy and to a fixed link', async () => {
    renderWithProviders(<ReportVersionHistory report={report} />, { locale: 'ar' })
    await open()
    fireEvent.click(await screen.findByTestId('report-version-restore-v1'))

    const ask = screen.getByTestId('report-version-confirm')
    expect(ask).toHaveTextContent('تبقى النسخة الحالية في السجل')
    expect(ask).toHaveTextContent('رابطًا ثابتًا')
    expect(ask).toHaveTextContent(fmtDateTime(versions[1].generated_at))
    expect(restoreReportVersion).not.toHaveBeenCalled()

    fireEvent.click(within(ask).getByTestId('report-version-confirm-yes'))
    await waitFor(() => expect(restoreReportVersion).toHaveBeenCalledWith('p1', 'r1', 'v1'))
    expect(await screen.findByTestId('report-version-restored')).toHaveTextContent(fmtDateTime(versions[1].generated_at))
    expect(screen.queryByTestId('report-version-confirm')).toBeNull()
    // The list is read again: the copy that was current is now in it.
    await waitFor(() => expect(listReportVersions).toHaveBeenCalledTimes(2))
  })

  it('cancelling the confirmation restores nothing', async () => {
    renderWithProviders(<ReportVersionHistory report={report} />)
    await open()
    fireEvent.click(await screen.findByTestId('report-version-restore-v2'))
    expect(screen.getByTestId('report-version-confirm')).toHaveTextContent('stays in the history')
    fireEvent.click(screen.getByTestId('report-version-confirm-no'))
    expect(screen.queryByTestId('report-version-confirm')).toBeNull()
    expect(restoreReportVersion).not.toHaveBeenCalled()
  })

  it('says a refused restore where it was asked, with the server’s reason, and keeps the question open', async () => {
    vi.mocked(restoreReportVersion).mockRejectedValue({ isAxiosError: true, response: { status: 409, data: { message: 'This report is being rebuilt right now.' } } })
    renderWithProviders(<ReportVersionHistory report={report} />)
    await open()
    fireEvent.click(await screen.findByTestId('report-version-restore-v1'))
    fireEvent.click(screen.getByTestId('report-version-confirm-yes'))

    expect(await screen.findByRole('alert')).toHaveTextContent('This report is being rebuilt right now.')
    expect(screen.getByTestId('report-version-confirm')).toBeInTheDocument()
    expect(screen.queryByTestId('report-version-restored')).toBeNull()
  })

  it('says so when there is no earlier copy', async () => {
    vi.mocked(listReportVersions).mockResolvedValue([] as never)
    renderWithProviders(<ReportVersionHistory report={report} />, { locale: 'ar' })
    await open()
    expect(await screen.findByTestId('report-history-empty')).toHaveTextContent('لا توجد نسخ سابقة')
  })

  it('says a failed load and offers to try again', async () => {
    vi.mocked(listReportVersions).mockRejectedValueOnce(new Error('offline'))
    renderWithProviders(<ReportVersionHistory report={report} />)
    await open()
    fireEvent.click(await screen.findByTestId('report-history-retry'))
    expect(await screen.findAllByTestId(/^report-version-v/)).toHaveLength(2)
  })

  it('lets a reader without reports.manage on the project see the list, with restore disabled and the reason stated', async () => {
    held.list = ['reports.view']
    renderWithProviders(<ReportVersionHistory report={report} />)
    await open()
    const restore = await screen.findByTestId('report-version-restore-v1')
    expect(restore).toBeDisabled()
    expect(screen.getByTestId('report-version-restore-reason')).toHaveTextContent(/permission/)
    fireEvent.click(restore)
    expect(screen.queryByTestId('report-version-confirm')).toBeNull()
  })

  it('offers the restore while the project’s capabilities are not known yet; the server decides', async () => {
    held.list = undefined
    renderWithProviders(<ReportVersionHistory report={report} />)
    await open()
    expect(await screen.findByTestId('report-version-restore-v1')).toBeEnabled()
    expect(screen.queryByTestId('report-version-restore-reason')).toBeNull()
  })

  it('does not offer a restore while the report is being rebuilt, and says why', async () => {
    renderWithProviders(<ReportVersionHistory report={{ ...report, status: 'processing' }} />)
    await open()
    expect(await screen.findByTestId('report-version-restore-v1')).toBeDisabled()
    expect(screen.getByTestId('report-version-restore-reason')).toHaveTextContent(/being rebuilt/)
  })

  it('marks a kept copy that is the current one, as after a restore, and offers no restore of it', async () => {
    renderWithProviders(<ReportVersionHistory report={{ ...report, generated_at: '2026-09-01T10:00:00.000000Z' }} />)
    await open()
    expect(await screen.findByTestId('report-version-current-v1')).toHaveTextContent('current copy')
    expect(screen.queryByTestId('report-version-restore-v1')).toBeNull()
    expect(screen.getByTestId('report-version-restore-v2')).toBeEnabled()
  })

  it('opens in place when mounted inline, with no dialog of its own', async () => {
    renderWithProviders(<ReportVersionHistory report={report} inline />)
    const trigger = await screen.findByTestId('report-history-open-r1')
    expect(trigger).toHaveAttribute('aria-expanded', 'false')
    fireEvent.click(trigger)
    expect(trigger).toHaveAttribute('aria-expanded', 'true')
    expect(await screen.findAllByTestId(/^report-version-v/)).toHaveLength(2)
    expect(screen.queryByRole('dialog')).toBeNull()
  })
})
