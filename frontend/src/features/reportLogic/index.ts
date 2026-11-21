/**
 * REPORT-LOGIC-001 — the one report-logic layer.
 *
 * Everything the /r/ report, the analytics board and the content library derive from a live-report payload is derived here, once.
 * The content library imports `contentLists`, `basisLine` and (with the payload) `resultSource` from this module; the board and the
 * report both call every function below.
 */
export * from './results'
export * from './platforms'
export * from './ranking'
export * from './trend'
export * from './content'
export * from './funnel'
export * from './kpis'
export * from './compare'
