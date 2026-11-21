import { z } from 'zod'

/**
 * CONTENT-STUDIO-001 batch 4 — a layout family is a RULE for composing one output, not a fixed design: which blocks it has, how big
 * each is in grid cells, where each prefers to sit, and which motion recipes it uses. A family is a JSON file validated here, so adding
 * one is adding a file, not code. Every family obeys the safe margins and reads the design tokens; the placement engine decides the
 * actual position from the picture's calm zones.
 */
export const ANCHORS = ['top', 'upper', 'middle', 'lower', 'bottom', 'start-side', 'end-side'] as const
export type Anchor = (typeof ANCHORS)[number]

export const ROLES = ['logo', 'hook', 'message', 'cta', 'support', 'price', 'surface'] as const
export type Role = (typeof ROLES)[number]

const blockSchema = z.object({
  role: z.enum(ROLES),
  /** Size in cells of the 12×20 grid. */
  cols: z.number().int().min(1).max(12),
  rows: z.number().int().min(1).max(20),
  /** `calm` asks the engine to choose from the picture; the others pin a band of the live area. */
  place: z.enum(['calm', 'live-top', 'live-bottom', 'live-center']),
  /** `start` is the side the language starts on: right in Arabic, left in English. */
  align: z.enum(['start', 'center', 'end']),
})

export const familySchema = z
  .object({
    key: z.string().regex(/^[a-z][a-z0-9-]*$/),
    version: z.number().int().positive(),
    label: z.object({ ar: z.string().min(1), en: z.string().min(1) }),
    kind: z.enum(['video', 'image', 'both']),
    anchor: z.enum(ANCHORS),
    blocks: z.array(blockSchema).min(1),
    /** Motion recipe keys (see batch 5); an image ignores them. */
    motion: z.array(z.string().min(1)),
    /** Detail cuts: how many close crops of the same picture the family cuts between (0 for a still composition). */
    detailCuts: z.number().int().min(0).max(4),
  })
  .superRefine((f, ctx) => {
    // A call to action is mandatory in every output, so every family carries one.
    if (!f.blocks.some((b) => b.role === 'cta')) ctx.addIssue({ code: 'custom', message: 'a family must carry a cta block', path: ['blocks'] })
    if (!f.blocks.some((b) => b.role === 'hook')) ctx.addIssue({ code: 'custom', message: 'a family must carry a hook block', path: ['blocks'] })
  })

export type Family = z.infer<typeof familySchema>
