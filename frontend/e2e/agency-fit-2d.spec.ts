import { test } from '@playwright/test'
import { AUTH } from './helpers'
import { registerFit, type Fitted } from './agency-fit-2.shared'

/** AF2, part d of 4 — see `agency-fit-2.shared.ts` for what is measured and why the spec is split. */
test.use({ storageState: AUTH.owner })

const FITTED: Fitted[] = [
  { path: '/agency/billing', tabs: { testid: 'finance-tabs', count: 3 } },
  { path: '/agency/automation', tabs: { selector: '[data-testid^="automation-tab-"]', count: 3 } },
]

registerFit(FITTED)
