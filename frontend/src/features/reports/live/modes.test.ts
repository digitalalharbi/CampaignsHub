import { describe, expect, it } from 'vitest'
import { modesFor, ownsPlatformChoice, readMode } from './modes'

describe('the live link modes', () => {
  it('offers a summary link nothing but the summary', () => {
    expect(modesFor('executive_summary')).toEqual(['summary'])
    // An address asking a summary link for the dashboard does not get a product the operator did not send.
    expect(readMode('dashboard', 'executive_summary')).toBe('summary')
    expect(readMode('content', 'executive_summary')).toBe('summary')
  })

  it('opens a detailed link on the dashboard and honours a mode it offers', () => {
    expect(readMode(null, 'detailed')).toBe('dashboard')
    expect(readMode('platforms', 'detailed')).toBe('platforms')
    expect(readMode('content', 'detailed')).toBe('content')
    expect(readMode('summary', 'detailed')).toBe('summary')
  })

  it('offers no campaign mode, whatever the address asks for', () => {
    expect(modesFor('detailed') as string[]).not.toContain('campaigns')
    expect(readMode('campaigns', 'detailed')).toBe('dashboard')
  })

  it('lets only the platform and content modes choose their own platform', () => {
    expect(ownsPlatformChoice('platforms')).toBe(true)
    expect(ownsPlatformChoice('content')).toBe(true)
    expect(ownsPlatformChoice('dashboard')).toBe(false)
    expect(ownsPlatformChoice('summary')).toBe(false)
  })
})
