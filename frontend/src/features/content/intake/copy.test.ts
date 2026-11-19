import { describe, expect, it } from 'vitest'
import { INTAKE_ACTIONS, INTAKE_STATUSES } from './api'
import { ACTION, AI_FIELD, AI_STATE, CHECK_NAME, CHECK_STATUS, EVENT, FAMILY, PLACEMENT, STATUS, STATUS_TONE, UI } from './copy'

/** CONTENT-INTAKE-001 — every status, action, check and AI word exists in both languages, with no Arabic-Indic digit typed in. */
describe('quality gate copy', () => {
  it('names every status and action in both languages', () => {
    for (const s of INTAKE_STATUSES) {
      expect(STATUS[s].ar).toBeTruthy()
      expect(STATUS[s].en).toBeTruthy()
      expect(STATUS_TONE[s]).toBeTruthy()
    }
    for (const a of INTAKE_ACTIONS) {
      expect(ACTION[a].ar).toBeTruthy()
      expect(ACTION[a].en).toBeTruthy()
      expect(EVENT[a]).toBeTruthy()
    }
  })

  it('covers every check the server can return', () => {
    for (const k of ['target', 'assets', 'spec', 'format', 'file_size', 'aspect_ratio', 'resolution', 'duration', 'safe_zone', 'copy_length', 'utm', 'naming', 'duplicate']) {
      expect(CHECK_NAME[k]?.ar, k).toBeTruthy()
    }
    for (const k of ['pass', 'warn', 'fail', 'not_measured'] as const) expect(CHECK_STATUS[k].en).toBeTruthy()
  })

  it('keeps every table complete in both languages and free of typed Arabic-Indic digits', () => {
    const pairs = [AI_FIELD, AI_STATE, FAMILY, PLACEMENT, EVENT, CHECK_NAME]
    for (const table of pairs) {
      for (const [key, pair] of Object.entries(table)) {
        expect(pair.ar, key).toBeTruthy()
        expect(pair.en, key).toBeTruthy()
        expect(/[٠-٩۰-۹]/.test(pair.ar), key).toBe(false)
      }
    }
    expect(JSON.stringify(UI.ar)).not.toMatch(/[٠-٩۰-۹]/)
  })

  it('states the AI state honestly: not enabled, never a generated value', () => {
    expect(AI_STATE.awaiting_credentials.en).toMatch(/Not enabled/)
    expect(AI_STATE.awaiting_credentials.ar).toMatch(/غير مفعّل/)
  })
})
