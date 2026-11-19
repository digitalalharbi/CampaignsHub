import { test } from '@playwright/test'
import { AUTH } from './helpers'
import { registerFit, type Fitted } from './agency-fit-2.shared'

/** AF2, part c of 4 — see `agency-fit-2.shared.ts` for what is measured and why the spec is split. */
test.use({ storageState: AUTH.owner })

const FITTED: Fitted[] = [
  { path: '/agency/forms', tabs: { testid: 'forms-tabs', count: 3, flow: [1, 2] } },
  { path: '/agency/agents', tabs: { selector: '[role="tablist"] [role="tab"]', count: 3, flow: [1, 2] } },
]

registerFit(FITTED)
