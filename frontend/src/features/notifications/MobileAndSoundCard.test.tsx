import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { MobileAndSoundCard } from './MobileAndSoundCard'
import * as push from './push'
import * as sound from '@/lib/alertSound'
import { resetInstallStateForTests } from '@/app/pwaInstall'
import { useUi } from '@/stores/ui'

/**
 * NOTIF-PUSH-001 — the preferences card: permission only on the button, the iPhone says «install first», devices can be revoked.
 */
const settings: push.AlertSettings = {
  sound_enabled: true, sound_volume: 35,
  push_types: { critical: true, budget_90: true, budget_100: true, sync_failed: true, approval: true },
  quiet_hours: { enabled: true, start: '22:00', end: '08:00', is_default: true, timezone: 'Asia/Riyadh' },
}
const device: push.PushDevice = { id: 'd-1', label: 'Chrome · Android', created_at: '2026-11-15T09:00:00Z', last_used_at: null, failing: false }

function mount() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(<QueryClientProvider client={qc}><MobileAndSoundCard /></QueryClientProvider>)
}

function stubBrowser(permission: NotificationPermission = 'default') {
  const requestPermission = vi.fn().mockResolvedValue('granted')
  vi.stubGlobal('Notification', Object.assign(function () { /* constructor stub */ }, { permission, requestPermission }))
  Object.defineProperty(window, 'PushManager', { value: function () { /* stub */ }, configurable: true })
  Object.defineProperty(navigator, 'serviceWorker', { value: { getRegistration: vi.fn(), register: vi.fn(), ready: Promise.resolve() }, configurable: true })
  window.matchMedia = vi.fn().mockReturnValue({ matches: false }) as never

  return requestPermission
}

describe('MobileAndSoundCard', () => {
  beforeEach(() => {
    useUi.setState({ locale: 'ar' })
    resetInstallStateForTests()
    vi.spyOn(push, 'getAlertSettings').mockResolvedValue(settings)
    vi.spyOn(push, 'getPushConfig').mockResolvedValue({ configured: true, public_key: 'BPUBLIC', devices: [device], types: ['critical', 'budget_90', 'budget_100', 'sync_failed', 'approval'] })
  })
  afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals() })

  it('never asks the browser for permission by itself — only after the button is pressed', async () => {
    const request = stubBrowser()
    const enable = vi.spyOn(push, 'enablePush').mockImplementation(async () => { await Notification.requestPermission() })
    mount()

    const button = await screen.findByTestId('enable-push')
    await waitFor(() => expect((button as HTMLButtonElement).disabled).toBe(false))
    expect(button.textContent).toContain('فعّل إشعارات الجوال')
    expect(request).not.toHaveBeenCalled()
    expect(enable).not.toHaveBeenCalled()

    fireEvent.click(button)

    await waitFor(() => expect(enable).toHaveBeenCalledWith('BPUBLIC'))
    expect(request).toHaveBeenCalledTimes(1)
  })

  it('on an iPhone that has not installed the app, says so instead of offering a prompt that would be ignored', async () => {
    stubBrowser()
    vi.spyOn(navigator, 'userAgent', 'get').mockReturnValue('Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) Safari')
    mount()

    expect(await screen.findByText(/مشاركة ← إضافة إلى الشاشة الرئيسية/)).toBeInTheDocument()
    expect(screen.getByText(/iOS 16.4/)).toBeInTheDocument()
    expect(screen.queryByTestId('enable-push')).toBeNull()
  })

  it('says the server has no keys yet when it has none, without a button', async () => {
    stubBrowser()
    vi.spyOn(push, 'getPushConfig').mockResolvedValue({ configured: false, public_key: null, devices: [], types: [] })
    mount()

    expect(await screen.findByText(/VAPID/)).toBeInTheDocument()
    expect(screen.queryByTestId('enable-push')).toBeNull()
  })

  it('explains a browser that blocks notifications and disables the button', async () => {
    stubBrowser('denied')
    mount()

    expect(await screen.findByText(/المتصفح يمنع الإشعارات/)).toBeInTheDocument()
    expect((screen.getByTestId('enable-push') as HTMLButtonElement).disabled).toBe(true)
  })

  it('lists the devices and revokes one', async () => {
    stubBrowser()
    const revoke = vi.spyOn(push, 'removeDevice').mockResolvedValue({} as never)
    mount()

    expect(await screen.findByText('Chrome · Android')).toBeInTheDocument()
    fireEvent.click(screen.getByTestId('push-revoke-d-1'))

    await waitFor(() => expect(revoke).toHaveBeenCalledWith('d-1'))
  })

  it('saves the sound switch at once and a push type on its own', async () => {
    stubBrowser()
    const save = vi.spyOn(push, 'saveAlertSettings').mockResolvedValue(settings)
    mount()

    const sw = await screen.findByTestId('sound-enabled')
    await waitFor(() => expect((sw as HTMLButtonElement).disabled).toBe(false))
    fireEvent.click(sw)
    await waitFor(() => expect(save).toHaveBeenCalledWith({ sound_enabled: false }))
    fireEvent.click(await screen.findByTestId('push-type-budget_90'))
    await waitFor(() => expect(save).toHaveBeenCalledWith({ push_types: { budget_90: false } }))
  })

  it('«جرّب الصوت» plays the ping at the chosen volume from the click itself', async () => {
    stubBrowser()
    const ping = vi.spyOn(sound, 'playPing').mockReturnValue(true)
    mount()

    fireEvent.click(await screen.findByTestId('sound-try'))

    expect(ping).toHaveBeenCalledWith(35, { fromGesture: true })
  })

  it('tells the person when the browser would not play the sound', async () => {
    stubBrowser()
    vi.spyOn(sound, 'playPing').mockReturnValue(false)
    mount()

    fireEvent.click(await screen.findByTestId('sound-try'))

    expect(await screen.findByText(/ما سمح بتشغيل الصوت/)).toBeInTheDocument()
  })

  it('says which quiet hours apply and that nothing sounds during them', async () => {
    stubBrowser()
    mount()

    expect(await screen.findByText(/من 10 م إلى 8 ص/)).toBeInTheDocument()
    expect(screen.getByText(/ملخص واحد/)).toBeInTheDocument()
  })
})
