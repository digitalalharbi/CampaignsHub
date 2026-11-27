// Untracked: the repo config with the installed Google Chrome channel (bundled browser version mismatch on this host).
import base from './playwright.config'
export default {
  ...base,
  use: { ...base.use, channel: 'chrome' },
  projects: (base.projects ?? []).map((p) => ({ ...p, use: { ...(p.use ?? {}), channel: 'chrome' } })),
}
