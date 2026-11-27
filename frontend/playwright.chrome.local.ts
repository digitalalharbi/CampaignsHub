import base from './playwright.config'
// Local only (never committed): drive the installed Google Chrome instead of a downloaded browser.
export default { ...base, projects: (base.projects ?? []).filter((p) => p.name === 'setup' || p.name === 'chromium').map((p) => ({ ...p, use: { ...(p.use ?? {}), channel: 'chrome' } })) }
