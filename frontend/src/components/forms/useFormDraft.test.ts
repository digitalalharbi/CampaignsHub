import { describe, expect, it, beforeEach, vi } from 'vitest'
import { act, renderHook } from '@testing-library/react'
import { useFormDraft } from './useFormDraft'

describe('useFormDraft', () => {
  beforeEach(() => {
    window.localStorage.clear()
    vi.useRealTimers()
  })

  it('starts from the initial value when no draft is stored', () => {
    const { result } = renderHook(() => useFormDraft('t1', { name: '' }))
    expect(result.current.value).toEqual({ name: '' })
    expect(result.current.restored).toBe(false)
  })

  it('restores a previously stored draft on mount', () => {
    window.localStorage.setItem('chub:draft:t2', JSON.stringify({ name: 'saved' }))
    const { result } = renderHook(() => useFormDraft('t2', { name: '' }))
    expect(result.current.value).toEqual({ name: 'saved' })
    expect(result.current.restored).toBe(true)
  })

  it('persists updates (debounced) and clear() removes the draft', async () => {
    vi.useFakeTimers()
    const { result } = renderHook(() => useFormDraft('t3', { name: '' }, { debounceMs: 100 }))
    act(() => result.current.setValue({ name: 'typed' }))
    act(() => vi.advanceTimersByTime(150))
    expect(JSON.parse(window.localStorage.getItem('chub:draft:t3')!)).toEqual({ name: 'typed' })
    act(() => result.current.clear())
    expect(window.localStorage.getItem('chub:draft:t3')).toBeNull()
    vi.useRealTimers()
  })
})
