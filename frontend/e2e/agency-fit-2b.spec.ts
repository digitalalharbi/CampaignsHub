import { test } from '@playwright/test'
import { AUTH } from './helpers'
import { registerFit, type Fitted } from './agency-fit-2.shared'

/** AF2, part b of 4 — see `agency-fit-2.shared.ts` for what is measured and why the spec is split. */
test.use({ storageState: AUTH.owner })

const FITTED: Fitted[] = [
  { path: '/agency/recommendations', tabs: { testid: 'recommendations-tabs', count: 2 } },
  { path: '/agency/delivery' },
  { path: '/agency/messages' },
]

registerFit(FITTED)
