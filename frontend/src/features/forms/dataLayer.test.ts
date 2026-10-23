import { beforeEach, describe, expect, it } from 'vitest'
import { initConsentMode, loadContainer, pushFormEvent, updateConsent } from './dataLayer'

describe('the dataLayer contract', () => {
  beforeEach(() => {
    window.dataLayer = []
    document.head.querySelectorAll('script[data-ch-gtm]').forEach((e) => e.remove())
  })

  it('pushes gtag-shaped arguments objects, which is the shape a container reads', () => {
    initConsentMode()
    const first = window.dataLayer![0] as IArguments
    expect(Object.prototype.toString.call(first)).toBe('[object Arguments]')
    expect(Array.from(first).slice(0, 2)).toEqual(['consent', 'default'])
  })

  it('denies every signal by default and grants them together only on an explicit update', () => {
    initConsentMode()
    updateConsent(true)
    updateConsent(false)
    const [d, g, r] = window.dataLayer!.map((x) => Array.from(x as IArguments)[2] as Record<string, string>)
    expect(Object.values({ ...d, wait_for_update: undefined }).filter(Boolean)).toEqual(['denied', 'denied', 'denied', 'denied'])
    expect(Object.values(g)).toEqual(['granted', 'granted', 'granted', 'granted'])
    expect(Object.values(r)).toEqual(['denied', 'denied', 'denied', 'denied'])
  })

  it('names each event once, with one payload shape', () => {
    pushFormEvent('generate_lead', { form_name: 'F', event_id: 'abc' })
    pushFormEvent('ch_form_step', { form_name: 'F', step: 2 })
    expect(window.dataLayer).toEqual([{ event: 'generate_lead', form_name: 'F', event_id: 'abc' }, { event: 'ch_form_step', form_name: 'F', step: 2 }])
  })

  it('loads a well-formed container once and refuses anything else, so an id never becomes part of a script address unchecked', () => {
    expect(loadContainer(null)).toBe(false)
    expect(loadContainer('GTM-ABC1234"><script>')).toBe(false)
    expect(loadContainer('gtm-abc1234')).toBe(false)
    expect(document.head.querySelector('script[data-ch-gtm]')).toBeNull()

    expect(loadContainer('GTM-ABC1234')).toBe(true)
    expect(loadContainer('GTM-ABC1234')).toBe(true)
    const scripts = document.head.querySelectorAll('script[data-ch-gtm]')
    expect(scripts).toHaveLength(1)
    expect((scripts[0] as HTMLScriptElement).src).toBe('https://www.googletagmanager.com/gtm.js?id=GTM-ABC1234')
  })
})
