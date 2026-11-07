import { getData, postData } from '@/lib/api/client'

/** REPORT-SNAPSHOT-PROTECT-001 — a copy of a report that a rebuild or a restore replaced. The list never carries the document. */
export interface ReportVersionRow {
  id: string
  /** When this copy was generated: the date a client saw on it. */
  generated_at: string | null
  /** When it was put in the history, which is when a newer copy took its place. */
  kept_at: string | null
}

const base = (p: string, id: string) => `/projects/${p}/reports/${id}/versions`

export const listReportVersions = (p: string, id: string) => getData<ReportVersionRow[]>(base(p, id))

/** The confirmation is asked on the screen before this is called; the server refuses a restore without it. */
export const restoreReportVersion = (p: string, id: string, version: string) =>
  postData<{ generated_at: string | null }>(`${base(p, id)}/${version}/restore`, { confirm: true })
