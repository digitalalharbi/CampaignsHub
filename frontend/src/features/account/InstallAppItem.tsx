import { useState } from 'react'
import { Download, Share, SquarePlus } from 'lucide-react'
import { promptInstall, useInstallState } from '@/app/pwaInstall'
import { Modal } from '@/components/ui/Modal'
import { useUi } from '@/stores/ui'

/**
 * NOTIF-PWA-001 — «ثبّت التطبيق» in the user menu.
 *
 * Shown only where installing is possible: Android and desktop Chrome / Edge (a real install prompt), and iPhone / iPad Safari
 * (the steps — Apple offers no prompt). Hidden once the app is installed and in browsers that cannot install it.
 */
export function InstallAppItem({ onDone }: { onDone: () => void }) {
  const ar = useUi((s) => s.locale) === 'ar'
  const state = useInstallState()
  const [steps, setSteps] = useState(false)

  if (state === 'installed' || state === 'unavailable') return null

  const press = async () => {
    if (state === 'ios') { setSteps(true); return }
    await promptInstall()
    onDone()
  }

  return (
    <>
      <button
        type="button"
        role="menuitem"
        data-testid="install-app"
        onClick={press}
        className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-start text-sm font-semibold text-text-secondary transition-colors hover:bg-surface-hover hover:text-text-primary"
      >
        <Download size={16} className="shrink-0 text-text-muted" />
        {ar ? 'ثبّت التطبيق' : 'Install the app'}
      </button>
      <Modal open={steps} onClose={() => { setSteps(false); onDone() }} title={ar ? 'ثبّت التطبيق على الآيفون' : 'Install on iPhone'} size="sm">
        <ol data-testid="install-ios-steps" className="flex list-decimal flex-col gap-3 ps-5 text-sm text-text-primary">
          <li className="flex items-center gap-2">
            {ar ? 'افتح الموقع في سفاري، واضغط زر' : 'Open the site in Safari and tap'}
            <Share size={16} className="shrink-0" aria-hidden />
            <b>{ar ? 'مشاركة' : 'Share'}</b>
          </li>
          <li className="flex items-center gap-2">
            {ar ? 'اختر' : 'Choose'}
            <SquarePlus size={16} className="shrink-0" aria-hidden />
            <b>{ar ? 'إضافة إلى الشاشة الرئيسية' : 'Add to Home Screen'}</b>
          </li>
          <li>{ar ? 'اضغط «إضافة»، وافتح التطبيق من الشاشة الرئيسية.' : 'Tap Add, then open the app from your Home Screen.'}</li>
        </ol>
        <p className="mt-4 text-xs text-text-muted">
          {ar ? 'لازم تستخدم سفاري (آيفون iOS 16.4 أو أحدث). الإشعارات على الجوال تشتغل بعد التثبيت فقط.' : 'Use Safari (iOS 16.4 or newer). Mobile notifications work only after installing.'}
        </p>
      </Modal>
    </>
  )
}
