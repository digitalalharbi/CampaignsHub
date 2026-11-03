import { AccessRecovery } from './AccessRecovery'
import type { Membership } from './memberships'
import { useUi } from '@/stores/ui'

/** The move was refused or did not take. The screens stay closed: under the old session they would mix two workspaces. */
export function PortalEntryFailed({ memberships }: { memberships: Membership[] }) {
  const ar = useUi((s) => s.locale) === 'ar'

  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-5 py-10">
      <div data-testid="portal-entry-failed" className="w-full max-w-md rounded-2xl border border-border bg-surface p-7 text-center">
        <h1 className="font-heading text-xl font-extrabold text-text-primary">
          {ar ? 'تعذّر فتح هذه البوابة' : 'This portal could not be opened'}
        </h1>
        <p className="mt-2 text-sm text-text-secondary">
          {ar
            ? 'ما زلت في مساحة العمل السابقة ولم يتغيّر شيء. اختر مساحة العمل التي تريدها من هنا.'
            : 'You are still in your previous workspace and nothing has changed. Choose the workspace you want from here.'}
        </p>
        <AccessRecovery memberships={memberships} onboarding={memberships.length === 0} />
      </div>
    </div>
  )
}
