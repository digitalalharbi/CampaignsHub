import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { InstallAppItem } from './InstallAppItem'
import { captureInstallPrompt, resetInstallStateForTests } from '@/app/pwaInstall'
import { useUi } from '@/stores/ui'

/** NOTIF-PWA-001 — «ثبّت التطبيق»: a real prompt on Android, the steps on iPhone, nothing when it cannot help. */
describe('InstallAppItem', () => {
  beforeEach(() => {
    useUi.setState({ locale: 'ar' })
    resetInstallStateForTests()
    window.matchMedia = vi.fn().mockReturnValue({ matches: false }) as never
  })
  afterEach(() => vi.restoreAllMocks())

  it('renders nothing in a browser that cannot install', () => {
    const { container } = render(<InstallAppItem onDone={() => undefined} />)

    expect(container.innerHTML).toBe('')
  })

  it('shows the button once Chrome offers the prompt, and presses it', async () => {
    captureInstallPrompt()
    render(<InstallAppItem onDone={() => undefined} />)
    const e = new Event('beforeinstallprompt', { cancelable: true }) as Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: string }> }
    e.prompt = vi.fn().mockResolvedValue(undefined)
    e.userChoice = Promise.resolve({ outcome: 'accepted' })
    window.dispatchEvent(e)

    const button = await screen.findByTestId('install-app')
    expect(button.textContent).toBe('ثبّت التطبيق')
    fireEvent.click(button)

    await waitFor(() => expect(e.prompt).toHaveBeenCalledTimes(1))
    // A prompt can only be used once: the button goes away rather than offering a second, dead press.
    await waitFor(() => expect(screen.queryByTestId('install-app')).toBeNull())
  })

  it('on iPhone shows «مشاركة ← إضافة إلى الشاشة الرئيسية»', () => {
    vi.spyOn(navigator, 'userAgent', 'get').mockReturnValue('Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) Safari')
    render(<InstallAppItem onDone={() => undefined} />)

    fireEvent.click(screen.getByTestId('install-app'))

    const steps = screen.getByTestId('install-ios-steps').textContent ?? ''
    expect(steps).toContain('مشاركة')
    expect(steps).toContain('إضافة إلى الشاشة الرئيسية')
  })
})
