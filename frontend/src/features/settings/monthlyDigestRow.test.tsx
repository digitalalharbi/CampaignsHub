import { describe, expect, it } from 'vitest'
import { TYPE_LABELS, TYPE_NOTES } from './messageLabels'

/**
 * EMAIL-SETTINGS-DEPTH-001 — the monthly digest is a message, not a database key.
 *
 * Found by opening the notifications settings in a browser: every row in the table read as a
 * sentence except one, which read «monthly_digest». `MessageCatalogue::DIGEST_SWITCH` has mapped all
 * three rhythms since EMAIL-INTELLIGENCE-001 and the sender dispatches the monthly on each
 * recipient's chosen day; only this label map and the client's own `digest_switch` type stopped at
 * two, and the cadence column — written as a binary because the type said there were two — told
 * every monthly subscriber their summary arrives «Weekly, Monday morning».
 *
 * A raw identifier in front of a reader is the defect the portal audit already has an E2E against.
 * This holds the label map itself, so a fourth rhythm cannot ship unnamed either.
 */
const RHYTHMS = ['daily_digest', 'weekly_digest', 'monthly_digest'] as const

describe('every digest rhythm is named', () => {
  it.each(RHYTHMS)('%s reads as a sentence in both languages', (key) => {
    const label = TYPE_LABELS[key]

    expect(label, `${key} has no label — the table prints the raw key`).toBeTruthy()
    expect(label.ar).not.toMatch(/_/)
    expect(label.en).not.toMatch(/_/)
    expect(label.ar).not.toBe(key)
    expect(label.en).not.toBe(key)
  })

  /** And the note says what the mail actually covers, which is the finished month. */
  it('the monthly note says the month is finished, not in progress', () => {
    const note = TYPE_NOTES.monthly_digest

    expect(note).toBeTruthy()
    expect(note.en).toMatch(/finished/i)
  })

  /**
   * The day is chosen per recipient (`digest_monthday`) and does not reach this screen. A sentence
   * naming a day would be a guess printed as a fact, so the note must not name one.
   */
  it('does not invent a day of the month it cannot know', () => {
    const note = TYPE_NOTES.monthly_digest

    expect(note.en).not.toMatch(/\b(1st|first|on the \d+)\b/i)
    expect(note.ar).not.toMatch(/\d/)
  })
})
