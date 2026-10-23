import { describe, expect, it } from 'vitest'
import { matchRoutes } from 'react-router-dom'
import { router } from './router'

/**
 * Owner 2026-10-23 — the legacy /welcome page is retired. Its address still arrives from search and chat results, with or
 * without a utm_source, so it must land on the homepage and never render the old page.
 */
describe('the retired /welcome page', () => {
  it('redirects to the homepage, whatever query string it arrives with', () => {
    for (const path of ['/welcome', '/welcome?utm_source=chatgpt.com']) {
      const matches = matchRoutes(router.routes, path.split('?')[0]!)
      expect(matches, `${path} must still resolve to a route`).not.toBeNull()
      const element = matches!.at(-1)!.route.element as { type: unknown; props: { to?: string; replace?: boolean } }
      expect(element.props.to).toBe('/')
      expect(element.props.replace).toBe(true)
    }
  })
})
