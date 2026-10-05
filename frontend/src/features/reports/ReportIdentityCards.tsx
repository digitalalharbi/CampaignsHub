import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Trash2, Upload } from 'lucide-react'
import { BrandMark } from '@/components/brand/BrandMark'
import { MARK_ACCEPT, markGuidance } from '@/features/branding/markSpec'
import { uploadBrandingAsset } from '@/features/branding/api'
import { projectReportIdentity, removeReportIdentityLogo } from './api'
import { toApiError } from '@/lib/api/client'
import { Skeleton } from '@/components/ui/States'

/**
 * REPORT-IDENTITY-CONTROLS-001 — the two identities, as things you can SEE and CHANGE.
 *
 * The owner's reading of the first version: the company upload «appears ineffective», the client
 * upload gives no sign it saved, there is no way back to no logo, and the controls «feel primitive».
 * Four separate complaints with one shape — the block showed an identity and offered a file picker,
 * and everything between those two facts was left to the operator to infer.
 *
 * So each role gets a CARD that states its own three facts: the mark as it will print, the name it
 * falls back to, and what can be done about it. The result of every action is said in words beside
 * the card that caused it, and nothing requires closing the builder to see whether it worked.
 *
 * ## Three identities, and this screen touches TWO of them
 *
 *   CampaignsHub product identity — the application's own chrome. Nothing here writes it, and the
 *                                   server cannot: these roles map only to the tenant and client
 *                                   layers, and the product's mark is the platform layer.
 *   Company report identity       — the preparer. Tenant-scoped, reused by every report this
 *                                   company sends.
 *   Client report identity        — the subject. Scoped to THIS client and never another.
 *
 * ## Why the preview carries a version
 *
 * The mark's URL is stable by design — `…/report-identity/logo/agency`, addressed by role so there
 * is no asset id to tamper with — and the response is cached for five minutes. Replacing a mark
 * therefore left the OLD one on screen, which reads exactly like an upload that did nothing. The
 * version is bumped locally on every success, which busts that cache without changing what the
 * server stores or how the report resolves it.
 */
export function ReportIdentityCards({ projectId, ar }: { projectId: string; ar: boolean }) {
  const identity = useQuery({
    queryKey: ['report-identity', projectId],
    queryFn: () => projectReportIdentity(projectId),
  })

  if (identity.isLoading) {
    return <Skeleton className="h-24 w-full rounded-xl" />
  }

  if (identity.data === undefined) {
    return null
  }

  const data = identity.data
  const canManage = data.upload != null

  return (
    <section className="grid gap-3 rounded-2xl border border-border bg-surface-secondary p-3" data-testid="report-identity-cards">
      <span className="text-xs font-bold text-text-muted">{ar ? 'هوية التقرير' : 'Report identity'}</span>

      <div className="grid gap-3 sm:grid-cols-2">
        {/* The preparer first in the form; the report itself leads with the subject. */}
        <IdentityCard
          projectId={projectId}
          ar={ar}
          role="agency"
          roleLabel={ar ? 'من إعداد' : 'Prepared by'}
          name={data.agency?.name ?? ''}
          logoUrl={data.agency?.logo_url ?? null}
          target={data.upload?.company ?? null}
          canManage={canManage}
          testid="report-identity-company"
        />

        {data.client != null && (
          <IdentityCard
            projectId={projectId}
            ar={ar}
            role="client"
            roleLabel={ar ? 'مقدم إلى' : 'Prepared for'}
            name={data.client.name}
            logoUrl={data.client.logo_url}
            target={data.upload?.client ?? null}
            canManage={canManage}
            testid="report-identity-client"
          />
        )}
      </div>

      {canManage && (
        <p className="text-[11px] leading-5 text-text-muted" data-testid="builder-upload-guidance">
          {markGuidance(ar)}
        </p>
      )}
    </section>
  )
}

type Role = 'agency' | 'client'

function IdentityCard({ projectId, ar, role, roleLabel, name, logoUrl, target, canManage, testid }: {
  projectId: string
  ar: boolean
  role: Role
  roleLabel: string
  name: string
  logoUrl: string | null
  target: { scope: string; scope_id: string | null } | null
  canManage: boolean
  testid: string
}) {
  const queryClient = useQueryClient()
  /** Bumped on every success, so a replaced mark is not the five-minute-old one. */
  const [version, setVersion] = useState(0)
  /*
   * The OUTCOME, not its sentence.
   *
   * Storing the words froze them in the language they were written in, so switching to English left
   * «تم حفظ شعار العميل» under an English card. The kind is what happened; the wording is read from
   * the current language every render.
   */
  const [said, setSaid] = useState<'saved' | 'removed' | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [confirming, setConfirming] = useState(false)

  const settled = (outcome: 'saved' | 'removed') => {
    setError(null)
    setSaid(outcome)
    setConfirming(false)
    setVersion((v) => v + 1)
    void queryClient.invalidateQueries({ queryKey: ['report-identity', projectId] })
  }

  const saidInWords = said === null
    ? null
    : said === 'removed'
      ? (ar ? 'تمت إزالة الشعار' : 'Logo removed')
      : role === 'client'
        ? (ar ? 'تم حفظ شعار العميل' : 'Client logo saved')
        : (ar ? 'تم حفظ شعار الشركة' : 'Company logo saved')

  const failed = (e: unknown) => {
    setSaid(null)
    setError(toApiError(e).message)
  }

  const save = useMutation({
    mutationFn: (file: File) => uploadBrandingAsset({
      scope: (target?.scope ?? 'tenant') as never,
      scopeId: target?.scope_id ?? null,
      kind: 'report_logo',
      theme: 'any',
      file,
    }),
    onSuccess: () => settled('saved'),
    onError: failed,
  })

  const remove = useMutation({
    mutationFn: () => removeReportIdentityLogo(projectId, role),
    onSuccess: () => settled('removed'),
    onError: failed,
  })

  const busy = save.isPending || remove.isPending

  return (
    <div className="grid gap-2 rounded-xl border border-border bg-surface p-3" data-testid={testid}>
      <span className="text-[11px] font-bold uppercase tracking-wide text-text-muted">{roleLabel}</span>

      <div className="flex min-w-0 items-center gap-2">
        {logoUrl !== null ? (
          <BrandMark src={`${logoUrl}${logoUrl.includes('?') ? '&' : '?'}v=${version}`} size="md" testid={`${testid}-mark`} />
        ) : (
          /*
            No mark is a STATE, not an empty space. A blank where a logo belongs reads as an image
            that failed to load — the one thing a report must never look like — so it is said in
            words, and the name beside it is what the report will actually print.
          */
          <span className="rounded-lg border border-dashed border-border px-2 py-1 text-[11px] text-text-muted" data-testid={`${testid}-no-logo`}>
            {ar ? 'لا يوجد شعار' : 'No logo'}
          </span>
        )}
        <span className="truncate text-sm font-bold text-text-primary" data-testid={`${testid}-name`}>{name}</span>
      </div>

      {canManage && (
        <div className="flex flex-wrap items-center gap-1.5">
          <label
            className={`inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-border bg-surface px-2 py-1 text-[11px] font-semibold text-text-primary hover:bg-surface-hover ${busy ? 'pointer-events-none opacity-60' : ''}`}
            data-testid={`${testid}-upload`}
          >
            <Upload size={12} aria-hidden />
            {save.isPending
              ? (ar ? 'جارٍ الحفظ…' : 'Saving…')
              : logoUrl !== null
                ? (ar ? 'استبدال الشعار' : 'Replace logo')
                : (ar ? 'إضافة شعار' : 'Add logo')}
            <input
              type="file"
              className="sr-only"
              accept={MARK_ACCEPT}
              disabled={busy}
              onChange={(e) => {
                const file = e.target.files?.[0]
                // Cleared so choosing the SAME file twice still fires a change — a retry after a
                // refusal is the most likely second attempt.
                e.target.value = ''
                if (file) save.mutate(file)
              }}
            />
          </label>

          {/*
            Removal asks once. It deletes a stored asset, and a misplaced click on a control sitting
            beside «replace» would take a client's mark off every report that company sends — so the
            button becomes the question, in place, and the answer is the destructive act.
          */}
          {logoUrl !== null && !confirming && (
            <button
              type="button"
              disabled={busy}
              onClick={() => { setConfirming(true); setSaid(null); setError(null) }}
              className="inline-flex items-center gap-1.5 rounded-lg border border-border px-2 py-1 text-[11px] font-semibold text-text-secondary hover:bg-surface-hover disabled:opacity-60"
              data-testid={`${testid}-remove`}
            >
              <Trash2 size={12} aria-hidden />
              {role === 'client'
                ? (ar ? 'إزالة شعار العميل' : 'Remove client logo')
                : (ar ? 'إزالة شعار الشركة' : 'Remove company logo')}
            </button>
          )}

          {confirming && (
            <span className="flex flex-wrap items-center gap-1.5" data-testid={`${testid}-confirm`}>
              <span className="text-[11px] text-text-secondary">{ar ? 'إزالة الشعار؟' : 'Remove the logo?'}</span>
              <button
                type="button"
                disabled={busy}
                onClick={() => remove.mutate()}
                className="rounded-lg border border-danger px-2 py-1 text-[11px] font-bold text-danger hover:bg-danger/10 disabled:opacity-60"
                data-testid={`${testid}-confirm-yes`}
              >
                {remove.isPending ? (ar ? 'جارٍ الإزالة…' : 'Removing…') : (ar ? 'تأكيد' : 'Confirm')}
              </button>
              <button
                type="button"
                onClick={() => setConfirming(false)}
                className="rounded-lg border border-border px-2 py-1 text-[11px] font-semibold text-text-secondary hover:bg-surface-hover"
                data-testid={`${testid}-confirm-no`}
              >
                {ar ? 'إلغاء' : 'Cancel'}
              </button>
            </span>
          )}
        </div>
      )}

      {/* The result, in words, beside the card that caused it — never a silent refetch. */}
      {saidInWords !== null && (
        <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-success" data-testid={`${testid}-saved`}>
          <Check size={12} aria-hidden />
          {saidInWords}
        </span>
      )}
      {error !== null && (
        <span className="text-[11px] text-danger" data-testid={`${testid}-error`}>{error}</span>
      )}
    </div>
  )
}
