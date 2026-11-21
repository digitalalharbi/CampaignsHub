import { familySchema, type Family } from './familySchema'
import boldHook from './data/bold-hook.json'
import detailCuts from './data/detail-cuts.json'
import editorialFrame from './data/editorial-frame.json'
import minimal from './data/minimal.json'
import offerCard from './data/offer-card.json'
import split from './data/split.json'
import stackedReveal from './data/stacked-reveal.json'

/** The seven system families. Each file is parsed on load, so a malformed one fails the build, not a customer's batch. */
export const SYSTEM_FAMILIES: ReadonlyArray<Family> = [stackedReveal, boldHook, offerCard, detailCuts, split, editorialFrame, minimal].map((f) => familySchema.parse(f))

export const familyByKey = (key: string): Family | undefined => SYSTEM_FAMILIES.find((f) => f.key === key)

export { familySchema, ANCHORS, ROLES } from './familySchema'
export type { Family, Anchor, Role } from './familySchema'
