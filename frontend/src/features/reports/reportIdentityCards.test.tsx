import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { ReportIdentityCards } from './ReportIdentityCards'
import { renderWithProviders } from '@/test/utils'

/**
 * REPORT-IDENTITY-CONTROLS-001 — the owner's four complaints about the first version.
 *
 * «رفع شعار الشركة يبدو غير فعّال» · «لا يوجد تأكيد أنه حُفظ» · «لا يوجد إزالة واضحة» · «الأدوات
 * بدائية». Four complaints with one shape: the block showed an identity and offered a file picker,
 * and everything between those two facts was left to the operator to infer.
 *
 * Each card now states its own three facts — the mark, the name it falls back to, and what can be
 * done — and the result of every action is said in words beside the card that caused it.
 */
const state = vi.hoisted(() => ({
  identity: {} as Record<string, unknown>,
  uploads: [] as Array<{ scope: string; scopeId: string | null }>,
  removed: [] as string[],
  failUpload: null as string | null,
}))

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  projectReportIdentity: () => Promise.resolve(state.identity),
  removeReportIdentityLogo: (_p: string, role: string) => {
    state.removed.push(role)
    return Promise.resolve({ removed: 1, role })
  },
}))

vi.mock('@/features/branding/api', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  uploadBrandingAsset: (input: { scope: string; scopeId: string | null }) => {
    /*
       An axios-shaped refusal, not a bare Error: `toApiError` reads the envelope, and a plain Error
       would exercise the «unexpected» branch instead of the server's own words — which is the thing
       this test exists to prove reaches the screen.
    */
    if (state.failUpload !== null) {
      return Promise.reject({
        isAxiosError: true,
        response: { status: 422, data: { success: false, message: state.failUpload, data: null, errors: null } },
      })
    }
    state.uploads.push({ scope: input.scope, scopeId: input.scopeId })
    return Promise.resolve({})
  },
}))

const identity = (over: Record<string, unknown> = {}) => ({
  name: 'العميل', logo_url: null, logo_source: 'none', by: 'الشركة',
  agency: { name: 'الشركة', logo_url: '/l/agency' },
  client: { name: 'العميل', logo_url: '/l/client' },
  upload: { company: { scope: 'tenant', scope_id: null }, client: { scope: 'client', scope_id: 'c-1' } },
  ...over,
})

const png = () => new File([new Uint8Array([137, 80, 78, 71])], 'mark.png', { type: 'image/png' })

const open = async () => {
  renderWithProviders(<ReportIdentityCards projectId="p1" ar />, { locale: 'ar' })
  await screen.findByTestId('report-identity-cards')
}

describe('the report identity cards', () => {
  beforeEach(() => {
    state.identity = identity()
    state.uploads = []
    state.removed = []
    state.failUpload = null
  })

  /** Each role shows its own mark and its own name — the two facts a report prints. */
  it('previews each mark beside the name it falls back to', async () => {
    await open()

    expect(screen.getByTestId('report-identity-company-mark')).toBeInTheDocument()
    expect(screen.getByTestId('report-identity-company-name')).toHaveTextContent('الشركة')
    expect(screen.getByTestId('report-identity-client-mark')).toBeInTheDocument()
    expect(screen.getByTestId('report-identity-client-name')).toHaveTextContent('العميل')
  })

  /**
   * «No logo» is a STATE, in words.
   *
   * A blank where a mark belongs reads as an image that failed to load — the one thing a report must
   * never look like — and the control says «add» rather than «replace».
   */
  it('says when a role has no logo and offers to add one', async () => {
    state.identity = identity({ client: { name: 'العميل', logo_url: null } })
    await open()

    expect(screen.getByTestId('report-identity-client-no-logo')).toHaveTextContent('لا يوجد شعار')
    expect(screen.queryByTestId('report-identity-client-mark')).toBeNull()
    expect(screen.getByTestId('report-identity-client-upload')).toHaveTextContent('إضافة شعار')
    // And no removal offered for a mark that does not exist.
    expect(screen.queryByTestId('report-identity-client-remove')).toBeNull()
  })

  /** A save says so, by name, beside the card that was saved. */
  it('confirms a company save in words', async () => {
    await open()

    fireEvent.change(screen.getByTestId('report-identity-company-upload').querySelector('input')!, {
      target: { files: [png()] },
    })

    expect(await screen.findByTestId('report-identity-company-saved')).toHaveTextContent('تم حفظ شعار الشركة')
    expect(state.uploads).toEqual([{ scope: 'tenant', scopeId: null }])
    // The client card did not claim anything.
    expect(screen.queryByTestId('report-identity-client-saved')).toBeNull()
  })

  it('confirms a client save in its own words and its own slot', async () => {
    await open()

    fireEvent.change(screen.getByTestId('report-identity-client-upload').querySelector('input')!, {
      target: { files: [png()] },
    })

    expect(await screen.findByTestId('report-identity-client-saved')).toHaveTextContent('تم حفظ شعار العميل')
    expect(state.uploads).toEqual([{ scope: 'client', scopeId: 'c-1' }])
  })

  /**
   * The preview changes after a save.
   *
   * The mark's URL is stable by design and cached for five minutes, so a replacement left the OLD
   * image on screen — which reads exactly like an upload that did nothing. The version in the query
   * string is what makes the new mark visible without reopening the builder.
   */
  it('shows the new mark without reopening the builder', async () => {
    await open()

    const before = screen.getByTestId('report-identity-company-mark').getAttribute('src')

    fireEvent.change(screen.getByTestId('report-identity-company-upload').querySelector('input')!, {
      target: { files: [png()] },
    })
    await screen.findByTestId('report-identity-company-saved')

    expect(screen.getByTestId('report-identity-company-mark').getAttribute('src')).not.toBe(before)
  })

  /** A failed upload says what the server said, beside the card that failed. */
  it('shows the real failure beside the right card', async () => {
    state.failUpload = 'Brand assets must be SVG, PNG, JPG or WebP.'
    await open()

    fireEvent.change(screen.getByTestId('report-identity-client-upload').querySelector('input')!, {
      target: { files: [png()] },
    })

    expect(await screen.findByTestId('report-identity-client-error')).toHaveTextContent('must be SVG')
    expect(screen.queryByTestId('report-identity-company-error')).toBeNull()
  })

  /** Removal asks once: it deletes a stored asset, and it sits beside «replace». */
  it('asks before removing, and removes only the role asked for', async () => {
    await open()

    fireEvent.click(screen.getByTestId('report-identity-client-remove'))
    expect(screen.getByTestId('report-identity-client-confirm')).toHaveTextContent('إزالة الشعار؟')
    expect(state.removed).toEqual([])

    fireEvent.click(screen.getByTestId('report-identity-client-confirm-yes'))

    await waitFor(() => expect(state.removed).toEqual(['client']))
    expect(await screen.findByTestId('report-identity-client-saved')).toHaveTextContent('تمت إزالة الشعار')
  })

  it('can be called off without deleting anything', async () => {
    await open()

    fireEvent.click(screen.getByTestId('report-identity-company-remove'))
    fireEvent.click(screen.getByTestId('report-identity-company-confirm-no'))

    expect(screen.queryByTestId('report-identity-company-confirm')).toBeNull()
    expect(state.removed).toEqual([])
  })

  /**
   * Somebody who may not manage branding still SEES both identities.
   *
   * What is withheld is the ability to change them — a report's identity is not a secret from the
   * person building the report.
   */
  it('shows the identities and no controls when branding may not be managed', async () => {
    state.identity = identity({ upload: null })
    await open()

    expect(screen.getByTestId('report-identity-company-name')).toHaveTextContent('الشركة')
    expect(screen.queryByTestId('report-identity-company-upload')).toBeNull()
    expect(screen.queryByTestId('report-identity-company-remove')).toBeNull()
    expect(screen.queryByTestId('builder-upload-guidance')).toBeNull()
  })
})
