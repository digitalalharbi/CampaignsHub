import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toApiError } from '@/lib/api/client'
import { useUi } from '@/stores/ui'
import { unlinkAccount } from './api'

/**
 * INTEGRATION-UNLINK-001 — «remove this account from the project», where the account is.
 *
 * An account linked to the wrong client had no way back once it had synced: the only doors were a binding id on a project page and the
 * «Manage accounts» wizard, which opens on a connection's organisations. This sits on the account's own row and asks once, in words, what
 * will and will not happen — nothing is deleted, the account stops feeding THIS project, linking it again restores it.
 */
export function UnlinkAccountButton({ projectId, projectName, accountId, accountName }: {
  projectId: string
  projectName: string | null
  accountId: string
  accountName: string
}) {
  const ar = useUi((s) => s.locale) === 'ar'
  const qc = useQueryClient()
  const [asking, setAsking] = useState(false)

  const remove = useMutation({
    mutationFn: () => unlinkAccount(projectId, accountId),
    onSuccess: async () => {
      setAsking(false)
      await Promise.all([
        qc.invalidateQueries({ queryKey: ['connection-hub'] }),
        qc.invalidateQueries({ queryKey: ['integration-accounts'] }),
        qc.invalidateQueries({ queryKey: ['connection-hierarchy'] }),
      ])
    },
  })

  if (!asking) {
    return (
      <button
        type="button"
        data-testid="unlink-account"
        onClick={() => setAsking(true)}
        className="mt-1.5 text-[11px] font-semibold text-danger hover:underline"
      >
        {ar ? 'إزالة من المشروع' : 'Remove from project'}
      </button>
    )
  }

  const project = projectName ?? (ar ? 'هذا المشروع' : 'this project')

  return (
    <div className="mt-2 rounded-lg border border-danger/40 bg-danger/5 p-2.5 text-[11px] leading-5" data-testid="unlink-confirm" role="alertdialog" aria-label={ar ? 'تأكيد الإزالة' : 'Confirm removal'}>
      <p className="font-semibold text-text-primary">
        {ar ? `إزالة «${accountName}» من «${project}»؟` : `Remove “${accountName}” from “${project}”?`}
      </p>
      <p className="mt-1 text-text-secondary">
        {ar
          ? 'تتوقف المزامنة لهذا المشروع، وتخرج أرقام هذا الحساب من تقاريره ومجاميعه. لا يُحذف شيء: يبقى السجل كاملًا، وإعادة ربط الحساب تُرجعه. لا يتأثر أي مشروع آخر ولا ربط المنصة.'
          : 'Syncing stops for this project and this account’s figures leave its reports and totals. Nothing is deleted: the history is kept, and linking the account again restores it. No other project and not the platform connection is affected.'}
      </p>
      {remove.isError && <p className="mt-1 text-danger" role="alert">{toApiError(remove.error).message}</p>}
      <div className="mt-2 flex gap-2">
        <button
          type="button"
          data-testid="unlink-confirm-yes"
          disabled={remove.isPending}
          onClick={() => remove.mutate()}
          className="rounded-lg bg-danger px-2.5 py-1 font-bold text-white disabled:opacity-60"
        >
          {ar ? 'نعم، أزل الحساب' : 'Yes, remove it'}
        </button>
        <button type="button" data-testid="unlink-confirm-no" disabled={remove.isPending} onClick={() => setAsking(false)} className="rounded-lg border border-border px-2.5 py-1 font-semibold text-text-primary">
          {ar ? 'إلغاء' : 'Cancel'}
        </button>
      </div>
    </div>
  )
}
