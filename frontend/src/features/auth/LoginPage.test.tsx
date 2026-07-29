import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { LoginPage } from './LoginPage'
import { renderWithProviders, signOut } from '@/test/utils'

/** AUTH-002: the /login marketing panel adapts to the portal the user arrived from — content only, same auth. */
describe('LoginPage — adaptive marketing panel (AUTH-002)', () => {
  it('default panel = paid campaign management', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login', locale: 'en' })
    expect(screen.getByText(/Ad campaign management platform/i)).toBeInTheDocument()
  })

  it('?portal=influencer adapts the panel to influencer campaigns', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login?portal=influencer', locale: 'en' })
    expect(screen.getByText('Influencer & content campaigns')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /Run influencer & content campaigns/i })).toBeInTheDocument()
  })

  it('a /client redirect adapts the panel to request tracking', () => {
    signOut()
    renderWithProviders(<LoginPage />, { route: '/login?redirect=%2Fclient', locale: 'en' })
    expect(screen.getByText('Track your requests')).toBeInTheDocument()
  })
})
