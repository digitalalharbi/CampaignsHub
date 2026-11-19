import { test } from '@playwright/test'
import { AUTH } from './helpers'
import { registerFit, type Fitted } from './agency-fit-2.shared'

/** AF2, part a of 4 — see `agency-fit-2.shared.ts` for what is measured and why the spec is split. */
test.use({ storageState: AUTH.owner })

const FITTED: Fitted[] = [
  { path: '/agency/campaigns', views: ['', '?view=table', '?view=cards'] },
  { path: '/agency/ad-groups', tabs: { testid: 'entity-tabs-ad_set', count: 3, flow: [2] } },
]

registerFit(FITTED)
