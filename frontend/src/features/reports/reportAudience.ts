/**
 * Whether this document is addressed to a CLIENT — CLIENT-DIAGNOSTIC-SEPARATION-001.
 *
 * `executive` is a client audience for this purpose: it is the shorter document a client's own
 * management reads, and nobody in that room can act on a connector state either. `internal` and an
 * absent audience are the operator's, because an unlabelled report is one somebody generated for
 * themselves.
 *
 * PERF-RECOVERY-001 — in a file of its own: it lived in `InteractiveReport.tsx`, and the shared
 * page's branding helper importing it pulled the whole slide renderer onto every LIVE link.
 * `InteractiveReport` re-exports it, so every existing import keeps working.
 */
export function isClientAudience(audience: string | undefined): boolean {
  return audience === 'client' || audience === 'executive'
}
