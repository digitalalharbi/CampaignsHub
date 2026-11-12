import { describe, expect, it, beforeEach } from 'vitest'
import { render, screen, waitFor, act, fireEvent } from '@testing-library/react'
import { queryClient } from '@/app/providers'
import { Refusal } from '@/lib/api/errors'
import { useSaveFeedback } from '@/stores/saveFeedback'
import { SaveFeedback } from '@/components/ui/SaveFeedback'
import { DialogHost } from '@/components/ui/DialogHost'
import { confirmDialog, promptDialog } from '@/stores/dialogs'
import { useUi } from '@/stores/ui'

/** SAVE-STATE-001 / DIALOG-001 — every save says what it did, the screen refreshes, and no browser box is used. */
function run<T>(fn: () => Promise<T>, meta?: Record<string, unknown>) {
  return queryClient.getMutationCache().build(queryClient, { mutationFn: fn, meta }).execute(undefined)
}

describe('shared save state', () => {
  beforeEach(() => {
    useUi.setState({ locale: 'ar' })
    useSaveFeedback.setState({ pending: 0, notice: null })
    queryClient.clear()
  })

  it('shows «يحفظ…» while in flight and «انحفظ» once the server answered', async () => {
    render(<SaveFeedback />)
    let release: () => void = () => {}
    const p = run(() => new Promise<string>((resolve) => { release = () => resolve('ok') }))
    await waitFor(() => expect(screen.getByTestId('save-feedback')).toHaveAttribute('data-state', 'saving'))
    expect(screen.getByTestId('save-feedback').textContent).toContain('يحفظ…')
    await act(async () => { release(); await p })
    await waitFor(() => expect(screen.getByTestId('save-feedback')).toHaveAttribute('data-state', 'saved'))
    expect(screen.getByTestId('save-feedback').textContent).toContain('انحفظ')
  })

  it('says «ما انحفظ» with the reason when it refused', async () => {
    render(<SaveFeedback />)
    await act(async () => { await run(() => Promise.reject(new Refusal('الاسم مستخدم من قبل.'))).catch(() => undefined) })
    const box = await screen.findByTestId('save-feedback')
    expect(box).toHaveAttribute('data-state', 'failed')
    expect(box.textContent).toContain('ما انحفظ')
    expect(box.textContent).toContain('الاسم مستخدم من قبل.')
  })

  it('stays silent for an action that is not a save', async () => {
    render(<SaveFeedback />)
    await act(async () => { await run(async () => 'x', { feedback: 'none' }) })
    expect(screen.queryByTestId('save-feedback')).toBeNull()
  })

  it('refetches the screen that is open so the saved value shows without a reload', async () => {
    let value = 1
    let fetches = 0
    const observer = queryClient.getQueryCache().build(queryClient, { queryKey: ['shown'], queryFn: async () => { fetches += 1; return value } })
    await observer.fetch()
    expect(fetches).toBe(1)
    const { QueryObserver } = await import('@tanstack/react-query')
    const watcher = new QueryObserver(queryClient, { queryKey: ['shown'], queryFn: async () => { fetches += 1; return value } })
    const stop = watcher.subscribe(() => undefined)
    value = 2
    await act(async () => { await run(async () => 'saved') })
    await waitFor(() => expect(watcher.getCurrentResult().data).toBe(2))
    stop()
  })
})

describe('in-product dialogs replace window.confirm and window.prompt', () => {
  it('resolves a confirm with the button pressed', async () => {
    render(<DialogHost />)
    const answer = confirmDialog({ title: 'أرشف العميل؟', danger: true })
    fireEvent.click(await screen.findByTestId('dialog-confirm'))
    await expect(answer).resolves.toBe(true)
    const second = confirmDialog({ title: 'مرة ثانية' })
    fireEvent.click(await screen.findByTestId('dialog-cancel'))
    await expect(second).resolves.toBe(false)
  })

  it('refuses a reason shorter than asked and returns the typed text otherwise', async () => {
    render(<DialogHost />)
    const answer = promptDialog({ title: 'سبب التغيير', minLength: 5 })
    const input = await screen.findByTestId('dialog-input')
    fireEvent.change(input, { target: { value: 'قصير' } })
    expect(screen.getByTestId('dialog-confirm')).toBeDisabled()
    fireEvent.change(input, { target: { value: 'قصير جدًا لا' } })
    fireEvent.click(screen.getByTestId('dialog-confirm'))
    await expect(answer).resolves.toBe('قصير جدًا لا')
  })
})

describe('no browser dialogs', () => {
  const TREE: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', { query: '?raw', import: 'default', eager: true })
  /** Registrations and payments are another batch's files; they leave this list when that batch lands. */
  const DEFERRED = ['RegistrationsPage', 'PaymentSettingsPage']

  it('uses neither window.confirm nor window.prompt nor alert()', () => {
    const offenders = Object.entries(TREE)
      .filter(([p]) => !/\.test\.(ts|tsx)$/.test(p) && !p.includes('saveFeedback') && !DEFERRED.some((d) => p.includes(d)))
      .filter(([, s]) => /window\.(confirm|prompt|alert)\(/.test(s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|\s)\/\/.*$/gm, '$1')))
      .map(([p]) => p)
    expect(offenders).toEqual([])
  })
})
