import { describe, expect, it } from 'vitest'
import { LIVE_PROJECT_STATUSES, PROJECT_TRANSITIONS, isLiveProject, lifecycleLabel } from './projectLifecycle'

describe('project lifecycle contract (PROJECT-LIFECYCLE-001)', () => {
  it('live is active + paused only, like LiveProjects::STATUSES', () => {
    expect([...LIVE_PROJECT_STATUSES]).toEqual(['active', 'paused'])
    expect(isLiveProject('archived')).toBe(false)
    expect(isLiveProject('draft')).toBe(false)
  })

  it('mirrors ProjectStatus::allowedTargets — no state moves to itself', () => {
    for (const [from, targets] of Object.entries(PROJECT_TRANSITIONS)) {
      expect(targets).not.toContain(from)
    }
    expect(PROJECT_TRANSITIONS.draft).not.toContain('paused')
  })

  it('labels every state through keys and never shows the raw value', () => {
    expect(lifecycleLabel('archived', true)).toBe('مؤرشف')
    expect(lifecycleLabel('active', false)).toBe('Active')
    expect(lifecycleLabel('needs_attention', true, 'clients')).toBe('يحتاج متابعة')
    expect(lifecycleLabel('weird', true)).not.toBe('weird')
  })
})
