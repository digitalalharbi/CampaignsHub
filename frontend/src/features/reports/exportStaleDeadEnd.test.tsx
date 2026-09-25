import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ExportChips } from './ReportsPage'

/**
 * REPORT-EXPORT-STALE-DEADEND-001 — a PDF chip must not be a link to a 409.
 *
 * ## The sequence a customer actually met
 *
 * The reports list found an export whose status was `completed`, drew a plain `<a href>`, and the
 * download endpoint refused it — 409, «This export is stale — regenerate it before downloading».
 * A plain anchor has nowhere to put that: the browser simply navigates away to a JSON error body.
 * There was no regenerate affordance either, because that existed only for exports whose STATUS was
 * `failed`, and a stale one is `completed`.
 *
 * So: a chip that looks ready, one click, a raw error page, and no way forward. Reported, fairly, as
 * «PDF export does not work» — while the renderer had done its job and the refusal was correct.
 *
 * ## Why it is not an edge case
 *
 * `renderer_version` is a CONFIGURED value. Any deploy that changes it turns every export made
 * before it into a dead link at once, for every customer, with no failed row anywhere to explain it.
 *
 * ## What is pinned
 *
 * That the offer matches what a click would get. A stale export offers REGENERATION, never a
 * download link — and a healthy one is untouched, because the fix must not take working downloads
 * away to make the broken ones safe.
 */
const exportRow = (over: Record<string, unknown> = {}) => ({
  id: 'e1',
  format: 'pdf',
  status: 'completed',
  size: 284_113,
  token: 'tok-1',
  failure_reason: null,
  stale_reason: null,
  ...over,
})

const report = (exports: unknown[]) => ({ id: 'r1', exports } as never)

describe('a stale export offers the way out instead of a dead link', () => {
  /** **The defect.** A completed-but-stale export is not a download. */
  it('offers regeneration for an export the server would refuse', () => {
    render(<ExportChips report={report([exportRow({ stale_reason: 'renderer_changed' })])} ar onExport={() => {}} />)

    expect(screen.getByTestId('export-stale-pdf-r1')).toBeInTheDocument()
    expect(screen.queryByTestId('download-pdf-r1')).not.toBeInTheDocument()
  })

  /** Each reason says what to do, because there is exactly one thing to do. */
  it.each([
    ['validation_failed', 'النص العربي'],
    ['renderer_changed', 'إصدار سابق'],
    ['template_changed', 'تغيّر تصميم'],
  ])('explains %s in words that name the action', (reason, words) => {
    render(<ExportChips report={report([exportRow({ stale_reason: reason })])} ar onExport={() => {}} />)

    expect(screen.getByTestId('export-stale-pdf-r1').getAttribute('title')).toContain(words)
  })

  /**
   * And a healthy export is untouched.
   *
   * A fix that made every download a regenerate button would «close» this by removing the feature,
   * which is the failure mode worth a test of its own.
   */
  it('still offers a real download when the file is current', () => {
    render(<ExportChips report={report([exportRow()])} ar onExport={() => {}} />)

    expect(screen.getByTestId('download-pdf-r1')).toHaveAttribute('href', '/api/v1/reports/download/tok-1')
    expect(screen.queryByTestId('export-stale-pdf-r1')).not.toBeInTheDocument()
  })

  /** A CSV carries no renderer and no text layer, so it can never be stale for these reasons. */
  it('leaves tabular exports alone', () => {
    render(<ExportChips report={report([exportRow({ format: 'csv', id: 'e2', stale_reason: null })])} ar onExport={() => {}} />)

    expect(screen.getByTestId('download-csv-r1')).toBeInTheDocument()
  })
})
