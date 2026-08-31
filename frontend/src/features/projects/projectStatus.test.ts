import { describe, expect, it } from 'vitest'

import { PROJECT_STATUS_LABELS, projectStatusLabel } from './ProjectsPage'

/**
 * PROJECTS-STATUS-LABEL-001 — the filter row was «الكل draft onboarding active paused completed
 * archived»: one translated chip and six raw column values, in an Arabic page.
 */
describe('project status labels', () => {
  const STATUSES = ['draft', 'onboarding', 'active', 'paused', 'completed', 'archived']

  it.each(STATUSES)('labels %s in both languages', (status) => {
    expect(projectStatusLabel(status, true)).not.toBe(status)
    expect(projectStatusLabel(status, false)).not.toBe(status)
  })

  it('covers exactly the statuses the page filters by, so neither list can drift', () => {
    expect(Object.keys(PROJECT_STATUS_LABELS).sort()).toEqual([...STATUSES].sort())
  })

  /*
   * A status the product does not recognise is a fact about the data. Showing it as itself keeps a
   * broken row visibly broken; substituting «unknown» would make it look like a normal one.
   */
  it('shows an unrecognised status as itself rather than hiding it', () => {
    expect(projectStatusLabel('sunsetting', true)).toBe('sunsetting')
  })
})
