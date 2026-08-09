import { Component, type ErrorInfo, type ReactNode } from 'react'

/**
 * Keeps a globally-mounted overlay from taking the whole application with it.
 *
 * ## Why this exists
 *
 * `UpgradeRequiredDialog` is mounted in `Providers`, ABOVE the router, so that a commercial refusal
 * is answered the same way in every portal. Above the router there is no boundary at all: this
 * application's only error handling is react-router's `errorElement`, which covers route elements
 * and nothing else. A throw in a sibling of `<RouterProvider>` therefore unmounts the entire React
 * root — the page goes blank, and the failure looks like a dead server rather than one broken
 * component.
 *
 * That is not theoretical. The first live run of the dialog threw, because `<Link>` requires a
 * router context it does not have there, and the whole interface went with it. The `<Link>` is gone,
 * but the exposure it revealed is structural: anything mounted outside the router is one bug away
 * from a white screen, and an overlay that only appears when something has ALREADY gone wrong is a
 * bad place to discover that.
 *
 * ## Why it renders nothing on failure
 *
 * An overlay is not the page. If the upgrade prompt cannot render, the right outcome is that the
 * customer keeps the application they were using and loses the prompt — not that they are shown a
 * second error about the error. The refusal itself still reached them: the mutation failed, and the
 * calling screen surfaces that as it always did.
 *
 * The error is reported to the console rather than swallowed, so it is visible to anyone looking —
 * and, in the gate, to `read_console_messages`, which is exactly how the `<Link>` throw was found.
 */
export class OverlayBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false }

  static getDerivedStateFromError() {
    return { failed: true }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    // eslint-disable-next-line no-console
    console.error('[overlay] a globally mounted overlay failed to render', error, info.componentStack)
  }

  render() {
    return this.state.failed ? null : this.props.children
  }
}
