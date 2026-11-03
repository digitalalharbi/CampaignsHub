/**
 * The spec entry point: `@playwright/test` plus one automatic, always-on instrument.
 *
 * GATE-WK-ENGINE-FAULT-001 — intermittently the WebKit leg fails because the ENGINE fails to load the
 * app's own entry script («WebKit encountered an internal error», `#root` empty, page never mounts).
 * Playwright's artifacts describe the page that resulted, not the request that failed, so a failing
 * run could not say whether the server closed a kept-alive connection or WebKit's network process
 * errored. Every failed request to the entry bundle is now printed with its error text and phase
 * timings (`formatFailedRequest`) and attached to the test, so the next occurrence is readable from
 * the run page. It only OBSERVES: no retry, no reload, no tolerance (GATE-WK-001).
 *
 * Specs import `test`/`expect` from here instead of `@playwright/test`; everything else is re-exported
 * unchanged.
 */
import { test as base } from '@playwright/test'
import { formatFailedRequest, isEntryBundleUrl } from './failedRequest'

export * from '@playwright/test'

export const test = base.extend<{ entryBundleEvidence: void }>({
  entryBundleEvidence: [
    async ({ context }, use, testInfo) => {
      const lines: string[] = []
      context.on('requestfailed', (request) => {
        const line = formatFailedRequest(
          {
            method: request.method(),
            url: request.url(),
            resourceType: request.resourceType(),
            errorText: request.failure()?.errorText,
            timing: request.timing(),
          },
          { project: testInfo.project.name, title: testInfo.title, at: Date.now() },
        )
        // Entry-bundle failures are the subject; other failures (aborted fonts, cancelled navigations)
        // are routine and would bury them, so those are only attached, not printed.
        if (isEntryBundleUrl(request.url())) console.log(line)
        lines.push(line)
      })
      await use()
      if (lines.length > 0) {
        await testInfo.attach('failed-requests.txt', { body: lines.join('\n'), contentType: 'text/plain' })
      }
    },
    { auto: true },
  ],
})
