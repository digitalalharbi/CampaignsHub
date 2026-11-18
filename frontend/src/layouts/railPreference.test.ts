import { beforeEach, describe, expect, it } from 'vitest'
import { railStorageKey, readRailCollapsed, useRail } from './railPreference'

describe('NOV-SIDEBAR-001 — the rail preference is per person and starts collapsed', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
    useRail.setState({ userId: null, collapsed: true })
  })

  it('is the 64px strip for somebody who never chose', () => {
    expect(readRailCollapsed('u1')).toBe(true)
    expect(readRailCollapsed(null)).toBe(true)
  })

  it('keys the choice by user id, so a shared browser does not share a rail', () => {
    expect(railStorageKey('u1')).not.toBe(railStorageKey('u2'))

    useRail.getState().hydrate('u1')
    useRail.getState().toggle()
    expect(useRail.getState().collapsed).toBe(false)
    expect(readRailCollapsed('u1')).toBe(false)

    useRail.getState().hydrate('u2')
    expect(useRail.getState().collapsed).toBe(true)

    useRail.getState().hydrate('u1')
    expect(useRail.getState().collapsed).toBe(false)
  })

  it('closes again with the same button and remembers that too', () => {
    useRail.getState().hydrate('u1')
    useRail.getState().toggle()
    useRail.getState().toggle()
    expect(useRail.getState().collapsed).toBe(true)
    expect(readRailCollapsed('u1')).toBe(true)
  })

  it('ignores a choice left in long-term storage, so a new visit starts collapsed', () => {
    localStorage.setItem(railStorageKey('u1'), 'open')
    useRail.getState().hydrate('u1')
    expect(useRail.getState().collapsed).toBe(true)
  })

  it('never throws when storage is unavailable', () => {
    const real = Object.getOwnPropertyDescriptor(window, 'sessionStorage')!
    Object.defineProperty(window, 'sessionStorage', { configurable: true, get() { throw new Error('blocked') } })
    try {
      expect(readRailCollapsed('u1')).toBe(true)
      expect(() => useRail.getState().toggle()).not.toThrow()
      expect(useRail.getState().collapsed).toBe(false)
    } finally {
      Object.defineProperty(window, 'sessionStorage', real)
    }
  })
})
