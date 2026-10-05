import { describe, expect, it } from 'vitest'

/**
 * A form that restores its values when a modal OPENS must do it before the browser paints.
 *
 * `Modal` renders nothing while it is closed, so opening it commits the inputs to the document in one
 * render and the restore runs afterwards. In a passive `useEffect` "afterwards" is a separate task,
 * and on a busy main thread the gap between the commit and that task is wide enough to reach into:
 * the field is attached, visible and editable, it takes what is typed into it, and the restore then
 * wipes it. Nothing types it again, so the value is simply gone — for a person mid-keystroke and for
 * a test alike.
 *
 * That is what failed on chromium in CI on `campaigns-linking`: `createCampaign` filled the name and
 * `toHaveValue` re-read the same input 34 times over a full fifteen seconds and saw "" every time. A
 * render that merely had not happened yet resolves in a retry or two; a value that was taken away
 * never resolves. It passed for the spec's first campaign and failed for the second — the one opened
 * while the list refetch caused by the first was still running.
 *
 * A layout effect runs synchronously inside the commit, so the pre-restore values are never
 * observable from outside React and never flash on screen either.
 *
 * ## Why this reads the SOURCE
 *
 * The defect is WHEN the restore runs relative to paint, and that ordering is a property of which
 * hook the file calls. Reproducing it needs a loaded main thread, which is exactly what makes the
 * e2e failure intermittent and what no mounted-component test can arrange. Which hook wraps the
 * restore, on the other hand, is stated plainly in the file — and a rewrite back to `useEffect` is
 * the regression being guarded.
 *
 * `import.meta.glob` rather than `node:fs`: CI typechecks these files, and `node:fs` types are not
 * available to them.
 */
const SOURCES = import.meta.glob('/src/features/**/*.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

/** Each restore-on-open site, named by the call that performs the restore. */
const GUARDED = [
  {
    file: '/src/features/campaigns/CampaignFormModal.tsx',
    restore: 'reset(isEdit ? defaults',
  },
]

describe('restore-on-open runs before paint', () => {
  for (const { file, restore } of GUARDED) {
    it(`${file} restores from a layout effect`, () => {
      const source = SOURCES[file]
      expect(source, `${file} is not in the glob — did it move?`).toBeTruthy()

      const at = source.indexOf(restore)
      expect(at, `${file} no longer contains \`${restore}\` — update this guard with the new call`)
        .toBeGreaterThan(-1)

      const before = source.slice(0, at)
      const layout = before.lastIndexOf('useLayoutEffect(')
      const passive = before.lastIndexOf('useEffect(')

      expect(
        layout,
        `${file} restores its fields from a passive useEffect. That runs in a task AFTER the commit `
          + 'that attached the inputs, so the empty field is reachable and typing into it is lost. '
          + 'Use useLayoutEffect.',
      ).toBeGreaterThan(passive)
    })
  }
})
