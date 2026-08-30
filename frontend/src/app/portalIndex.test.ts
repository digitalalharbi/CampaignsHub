import { describe, expect, it } from 'vitest'
import type { RouteObject } from 'react-router-dom'
import { router } from './router'

/**
 * ROUTE-INDEX-001 — a portal's own address renders the portal.
 *
 * `/app` was a blank screen. Not a 404 — nothing at all: the advertiser tree declared `dashboard`,
 * `analytics`, `campaigns` and thirty more, and no index, so the shell mounted with an empty outlet
 * and the page came back with an empty root. `/agency` has carried its index redirect since ADR
 * 0002; the portal that owns `/app/*` simply never got one.
 *
 * It is the address people actually reach. A bookmark saved before the section moved, a link whose
 * last segment was trimmed, a customer typing what they remember — every one of those lands here,
 * and a blank screen gives them nothing to do next.
 *
 * The check walks the real route tree rather than asserting on a string, because the defect was
 * structural: a child was missing, and only the shape of the tree says so.
 */
function find(routes: RouteObject[], path: string): RouteObject | undefined {
  for (const route of routes) {
    if (route.path === path) return route
    const nested = route.children && find(route.children, path)
    if (nested) return nested
  }
  return undefined
}

/** The portal roots that own a `/<name>/*` tree and are addressable on their own. */
const PORTALS = ['app', 'agency']

describe('every portal root renders something', () => {
  for (const portal of PORTALS) {
    it(`/${portal} has an index route`, () => {
      const root = find(router.routes, portal)
      expect(root, `the ${portal} tree is not in the router at all`).toBeDefined()

      /*
       * The index sits a layer or two below the path node — the tree wraps its children in a portal
       * guard and a shell — but never below another PATH. Descending through paths would let
       * `/app/settings`'s own index answer for `/app`, which is exactly the false pass that would
       * have let this defect through: the tree is full of nested indexes and had none at the top.
       */
      const hasIndex = (routes: RouteObject[] | undefined): boolean =>
        (routes ?? []).some((r) => r.index === true || (r.path === undefined && hasIndex(r.children)))

      expect(hasIndex(root?.children), `/${portal} renders an empty outlet`).toBe(true)
    })
  }
})
