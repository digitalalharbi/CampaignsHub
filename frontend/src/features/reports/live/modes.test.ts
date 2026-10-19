import { describe, expect, it } from 'vitest'
import { modesFor, offeredViews, ownsPlatformChoice, readMode } from './modes'

describe('the live link modes', () => {
  it('offers a summary link nothing but the summary', () => {
    expect(modesFor('executive_summary')).toEqual(['summary'])
    // An address asking a summary link for the dashboard does not get a product the operator did not send.
    expect(readMode('dashboard', 'executive_summary')).toBe('summary')
    expect(readMode('content', 'executive_summary')).toBe('summary')
  })

  it('opens a detailed link on the dashboard and honours a mode it offers', () => {
    expect(readMode(null, 'detailed')).toBe('summary')
    expect(readMode('platforms', 'detailed')).toBe('platforms')
    expect(readMode('content', 'detailed')).toBe('content')
    expect(readMode('summary', 'detailed')).toBe('summary')
  })

  it('offers no campaign mode, whatever the address asks for', () => {
    expect(modesFor('detailed') as string[]).not.toContain('campaigns')
    expect(readMode('campaigns', 'detailed')).toBe('summary')
  })

  it('gives each level its own composition, not the same views with a dial', () => {
    expect(modesFor('detailed', 'standard')).toEqual(['standard'])
    expect(modesFor('detailed', 'detailed')).toEqual(['summary', 'dashboard', 'platforms', 'content', 'tables'])
    expect(modesFor('executive_summary', 'detailed')).toEqual(['summary'])
    // Tables exist only in the detailed product; a standard link asked for them still gets the standard report.
    expect(readMode('tables', 'detailed', 'standard')).toBe('standard')
    expect(readMode('tables', 'detailed', 'detailed')).toBe('tables')
    // A link whose level is not known yet is the detailed product it always was.
    expect(modesFor('detailed', null)).toEqual(modesFor('detailed', 'detailed'))
  })

  it('lets only the platform and content modes choose their own platform', () => {
    expect(ownsPlatformChoice('platforms')).toBe(true)
    expect(ownsPlatformChoice('content')).toBe(true)
    expect(ownsPlatformChoice('dashboard')).toBe(false)
    expect(ownsPlatformChoice('summary')).toBe(false)
  })

  it('narrows the level\u2019s views to the main sections the server names, never to nothing', () => {
    const all = modesFor('detailed', 'detailed')
    expect(offeredViews(all, undefined)).toEqual(all)
    expect(offeredViews(all, ['tables', 'summary'])).toEqual(['summary', 'tables'])
    expect(offeredViews(all, ['campaigns'])).toEqual(all)
  })
})
