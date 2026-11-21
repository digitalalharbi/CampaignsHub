import { describe, expect, it } from 'vitest'
import { fireEvent, screen } from '@testing-library/react'
import { ProviderErrorNote } from './ProviderErrorNote'
import { readProviderError } from './providerError'
import { renderWithProviders } from '@/test/utils'

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §15 — the failure says who acts, and keeps the evidence.
 *
 * The first case is not invented. It is the sentence a real LinkedIn connection put on a real
 * customer's integrations page in production, and it is the whole argument for this file: nothing in
 * it is about the customer's account, and every move it invites — disconnect, re-authorise, raise a
 * ticket about LinkedIn — was wasted, because the defect was in the request this product sent.
 */
const LINKEDIN_PRODUCTION_ERROR =
  "Projected field 'pivotValues%2CdateRange%2CcostInLocalCurrency' not present in schema " +
  "'com.linkedin.ads.externalapi.reportingapi.v9.AdAnalyticsV9'"

describe('reading a provider’s refusal', () => {
  it('does not blame the customer for a request this product got wrong', () => {
    const reading = readProviderError(LINKEDIN_PRODUCTION_ERROR, 'en')

    expect(reading?.actor).toBe('product')
    expect(reading?.category).toBe('request_rejected')
    expect(reading?.message).toMatch(/not your account/i)
    // And it must not be the sentence that sends them through OAuth for our defect.
    expect(reading?.message).not.toMatch(/reconnect/i)
  })

  it('tells the customer to reconnect when the authorisation is the thing that failed', () => {
    expect(readProviderError('invalid_grant: token has been revoked', 'en')?.actor).toBe('customer')
    expect(readProviderError('invalid_grant: token has been revoked', 'en')?.message).toMatch(/Reconnect/)
  })

  it('names the account owner, not this product, for a withdrawn permission', () => {
    const reading = readProviderError('403 Forbidden: user does not have access to ad account', 'ar')

    expect(reading?.actor).toBe('customer')
    expect(reading?.category).toBe('permission_withdrawn')
    expect(reading?.message).toContain('مالك الحساب')
  })

  /** Nobody is paged for a platform having a bad minute — and nobody is told to fix it either. */
  it('asks for nothing when the platform is rate-limiting or briefly down', () => {
    expect(readProviderError('429 Too Many Requests', 'en')?.actor).toBe('nobody')
    expect(readProviderError('Service Unavailable', 'en')?.actor).toBe('nobody')
    expect(readProviderError('cURL error 28: Operation timed out', 'en')?.actor).toBe('nobody')
  })

  it('sends a missing key to the operator of this install', () => {
    expect(readProviderError('client_secret is not configured for this platform', 'en')?.actor).toBe('operator')
  })

  /**
   * An unrecognised failure is stated as unrecognised.
   *
   * A confident instruction under a message this code has never seen is worse than none: the reader
   * follows it, and the one act that would have helped — showing somebody the raw text — is the one
   * they skip.
   */
  it('does not guess at a failure it has never seen', () => {
    const reading = readProviderError('flurb: 17', 'en')

    expect(reading?.category).toBe('unclassified')
    expect(reading?.message).toMatch(/refused the request/i)
  })

  it('says nothing at all when there is no error', () => {
    expect(readProviderError(null, 'en')).toBeNull()
    expect(readProviderError('   ', 'en')).toBeNull()
  })
})

describe('the note on the card', () => {
  it('keeps the provider’s own words, verbatim, one press away', () => {
    renderWithProviders(
      <ProviderErrorNote error={LINKEDIN_PRODUCTION_ERROR} locale="en" testId="connector-error-linkedin" />,
      { locale: 'en' },
    )

    expect(screen.getByTestId('connector-error-linkedin')).toHaveTextContent(/not your account/i)

    fireEvent.click(screen.getByText('Technical details'))

    const raw = screen.getByTestId('connector-error-linkedin-raw')
    expect(raw).toHaveTextContent('AdAnalyticsV9')
    // Machine text stays left-to-right, or the field list is no longer a string anybody can search.
    expect(raw).toHaveAttribute('dir', 'ltr')
  })
})

/**
 * META-GRAPH-REFUSAL-001 — Meta's stored refusal is read by Meta's own code. The numbers inside a trace id
 * or a subcode decide nothing: `A401x` is not a 401.
 */
describe('a Meta refusal, by its code', () => {
  const read = (raw: string) => readProviderError(raw, 'en', 'meta')

  it('sends a dead session to reconnect', () => {
    const reading = read('Error validating access token: The session has been invalidated · code 190 · subcode 460 · trace AbC')
    expect(reading?.category).toBe('authorisation_lost')
    expect(reading?.actor).toBe('customer')
    expect(reading?.message).toMatch(/Reconnect/)
  })

  it('sends a checkpointed person to facebook.com first', () => {
    const reading = readProviderError('Checkpoint · code 190 · subcode 459', 'ar', 'meta')
    expect(reading?.category).toBe('authorisation_checkpoint')
    expect(reading?.message).toContain('facebook.com')
  })

  it('sends a missing grant to the account owner, not to reconnect, whatever the trace looks like', () => {
    const reading = read('(#200) Ad account owner has NOT grant ads_management or ads_read permission · code 200 · trace A401x')
    expect(reading?.category).toBe('permission_withdrawn')
    expect(reading?.message).toContain('ads_read')
    expect(reading?.message).not.toMatch(/Reconnect/)
  })

  it.each([
    'User request limit reached · code 17 · subcode 2446079 · trace X',
    'Application request limit reached · code 4',
    'Too many calls · code 613 · subcode 1487742',
    'There have been too many calls from this ad-account · code 80004',
  ])('asks for nothing on a throttle: %s', (raw) => {
    expect(read(raw)?.category).toBe('rate_limited')
    expect(read(raw)?.actor).toBe('nobody')
  })

  it('tells an unreachable account apart from a refused grant', () => {
    expect(read('Unsupported get request. Object with ID act_1 does not exist · code 100 · subcode 33')?.category).toBe('account_unreachable')
  })

  it('reads a code 100 that names a permission as the grant', () => {
    expect(read('(#100) Missing Permission · code 100')?.category).toBe('permission_withdrawn')
  })

  it('owns a rejected parameter', () => {
    const reading = read('Invalid parameter · Must specify True or False · code 100 · subcode 4834011')
    expect(reading?.category).toBe('request_rejected')
    expect(reading?.actor).toBe('product')
  })

  it('keeps a too-large page and a passing fault from reading as anyone’s to fix', () => {
    expect(read("Please reduce the amount of data you're asking for · code 1")?.actor).toBe('nobody')
    expect(read('An unknown error has occurred · code 1')?.category).toBe('platform_unavailable')
  })

  it('does not read Meta’s numbers on another platform’s refusal', () => {
    expect(readProviderError('Something odd · code 190', 'en', 'tiktok')?.category).toBe('unclassified')
  })

  it('does not take a trace id for a status on any platform', () => {
    expect(readProviderError('Something odd · trace A50344', 'en')?.category).toBe('unclassified')
    expect(readProviderError('Something odd · request 401-99', 'en')?.category).toBe('unclassified')
  })
})
