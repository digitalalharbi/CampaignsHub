import { describe, expect, it } from 'vitest'
import { overBudget } from './bundleBudget'

describe('the entry chunk budget', () => {
  it('names an entry chunk over budget', () => {
    expect(overBudget([{ fileName: 'index.js', isEntry: true, bytes: 4_763_480 }])).toHaveLength(1)
  })

  it('leaves route chunks and an entry within budget alone', () => {
    expect(overBudget([
      { fileName: 'index.js', isEntry: true, bytes: 407_000 },
      { fileName: 'CampaignsPage.js', isEntry: false, bytes: 900_000 },
    ])).toEqual([])
  })
})
