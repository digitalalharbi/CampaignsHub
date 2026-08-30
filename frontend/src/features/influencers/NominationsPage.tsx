import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, Loader2, UserPlus, X } from 'lucide-react'
import {
  convertNomination, decideNomination, fetchNominations, fetchRoster, proposeNomination,
  withdrawNomination, type Nomination,
} from './api'
import { Button } from '@/components/ui/Button'
import { toApiError } from '@/lib/api/client'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

/**
 * The shortlist, and what was decided about it (INFL-003).
 *
 * The page exists because the decision was the missing artefact. A collaboration records what was
 * AGREED; nothing recorded what was asked and what came back — so a creator who was turned down left
 * no trace and got proposed again next quarter by somebody who had not been in the room.
 *
 * Rejections are therefore first-class here rather than filtered away, and each carries its reason.
 */
const COPY = {
  ar: {
    title: 'الترشيحات',
    subtitle: 'اقترح صانع محتوى لحملة، وسجّل القرار — بالقبول أو بالرفض مع سببه.',
    propose: 'ترشيح صانع محتوى',
    creator: 'صانع المحتوى', fee: 'الأتعاب المقترحة', why: 'سبب الترشيح',
    submit: 'إرسال الترشيح', cancel: 'إلغاء',
    empty: 'لا توجد ترشيحات بعد.',
    st_proposed: 'بانتظار القرار', st_approved: 'مقبول', st_rejected: 'مرفوض', st_withdrawn: 'مسحوب',
    approve: 'قبول', reject: 'رفض', withdraw: 'سحب',
    reject_reason: 'سبب الرفض (مطلوب)',
    confirm_reject: 'تأكيد الرفض',
    make_collab: 'إنشاء التعاون',
    collab_title: 'عنوان التعاون',
    decided: 'القرار', became: 'أصبح تعاونًا',
    followers: 'متابع',
    all: 'الكل',
    reject_needs_reason: 'الرفض يحتاج سببًا مكتوبًا.',
  },
  en: {
    title: 'Nominations',
    subtitle: 'Put a creator forward for a campaign, and record the answer — including a no, with its reason.',
    propose: 'Nominate a creator',
    creator: 'Creator', fee: 'Proposed fee', why: 'Why this creator',
    submit: 'Send the nomination', cancel: 'Cancel',
    empty: 'No nominations yet.',
    st_proposed: 'Awaiting a decision', st_approved: 'Approved', st_rejected: 'Rejected', st_withdrawn: 'Withdrawn',
    approve: 'Approve', reject: 'Reject', withdraw: 'Withdraw',
    reject_reason: 'Reason for rejecting (required)',
    confirm_reject: 'Confirm the rejection',
    make_collab: 'Create the collaboration',
    collab_title: 'Collaboration title',
    decided: 'Decision', became: 'Became a collaboration',
    followers: 'followers',
    all: 'All',
    reject_needs_reason: 'A rejection needs a written reason.',
  },
}

type Copy = (typeof COPY)['en']

const STATUS_TONE: Record<Nomination['status'], string> = {
  proposed: 'bg-info/15 text-info',
  approved: 'bg-success/15 text-success',
  rejected: 'bg-[var(--negative-background)] text-danger',
  withdrawn: 'bg-surface-secondary text-text-muted',
}

export function NominationsPage() {
  const locale = useUi((s) => s.locale)
  const c = COPY[locale]
  const canView = useAuth((s) => s.hasPermission('influencers.view'))
  const canManage = useAuth((s) => s.hasPermission('influencers.manage'))
  // Deciding is its own permission: proposing a creator and committing the agency to them are two
  // different acts, and the interface must not offer the second to somebody who only has the first.
  const canApprove = useAuth((s) => s.hasPermission('influencers.approve'))
  const qc = useQueryClient()

  const [filter, setFilter] = useState<'' | Nomination['status']>('')
  const [proposing, setProposing] = useState(false)

  const nominations = useQuery({
    queryKey: ['influencer-nominations', filter],
    queryFn: () => fetchNominations(filter || undefined),
    enabled: canView,
  })

  const invalidate = () => qc.invalidateQueries({ queryKey: ['influencer-nominations'] })

  if (!canView) {
    return (
      <div className="p-6">
        <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">
          {locale === 'ar' ? 'لا تملك صلاحية عرض الترشيحات.' : 'You do not have permission to view nominations.'}
        </p>
      </div>
    )
  }

  return (
    <div data-testid="nominations-page" className="mx-auto flex w-full max-w-5xl flex-col gap-5 p-4 md:p-6">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="font-heading text-2xl font-extrabold text-text-primary">{c.title}</h1>
          <p className="mt-1 max-w-2xl text-sm text-text-secondary">{c.subtitle}</p>
        </div>
        {canManage && (
          <Button size="sm" onClick={() => setProposing((v) => !v)} data-testid="open-propose">
            <UserPlus size={15} className="me-1.5" /> {c.propose}
          </Button>
        )}
      </header>

      <div className="flex flex-wrap gap-1.5">
        {([['', c.all], ['proposed', c.st_proposed], ['approved', c.st_approved], ['rejected', c.st_rejected]] as const).map(
          ([value, label]) => (
            <button
              key={value || 'all'}
              type="button"
              data-testid={`nomination-filter-${value || 'all'}`}
              onClick={() => setFilter(value as '' | Nomination['status'])}
              className={`rounded-full border px-3 py-1.5 text-[12px] font-semibold transition-colors ${
                filter === value ? 'border-brand-500 bg-brand-primary-soft text-brand-700' : 'border-border text-text-secondary hover:bg-surface-hover'
              }`}
            >
              {label}
            </button>
          ),
        )}
      </div>

      {proposing && canManage && <ProposeForm c={c} onDone={() => { setProposing(false); invalidate() }} />}

      {nominations.isPending ? (
        <div className="flex justify-center p-10"><Loader2 className="animate-spin text-brand-600" /></div>
      ) : nominations.isError ? (
        <p className="text-sm text-danger">{toApiError(nominations.error).message}</p>
      ) : (nominations.data ?? []).length === 0 ? (
        <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-text-secondary">{c.empty}</p>
      ) : (
        <ul className="flex flex-col gap-3">
          {nominations.data!.map((n) => (
            <NominationCard
              key={n.id}
              nomination={n}
              c={c}
              canManage={canManage}
              canApprove={canApprove}
              onChanged={invalidate}
            />
          ))}
        </ul>
      )}
    </div>
  )
}

function ProposeForm({ c, onDone }: { c: Copy; onDone: () => void }) {
  const [influencerId, setInfluencerId] = useState('')
  const [fee, setFee] = useState('')
  const [rationale, setRationale] = useState('')

  const roster = useQuery({ queryKey: ['influencer-roster', 'for-nomination'], queryFn: () => fetchRoster({}) })

  const propose = useMutation({
    mutationFn: () => proposeNomination({
      influencer_id: influencerId,
      proposed_fee: fee || null,
      currency: fee ? 'SAR' : null,
      rationale: rationale || null,
    }),
    onSuccess: onDone,
  })

  return (
    <form
      data-testid="propose-form"
      className="flex flex-col gap-3 rounded-2xl border border-brand-500 bg-surface p-4"
      onSubmit={(e) => { e.preventDefault(); propose.mutate() }}
    >
      <label className="flex flex-col gap-1 text-sm">
        <span className="font-semibold text-text-secondary">{c.creator}</span>
        <select
          data-testid="propose-creator"
          required
          value={influencerId}
          onChange={(e) => setInfluencerId(e.target.value)}
          className="h-11 rounded-xl border border-border bg-surface px-3 text-sm text-text-primary"
        >
          <option value="">—</option>
          {(roster.data?.influencers ?? []).map((i) => (
            <option key={i.id} value={i.id}>{i.name} {i.handle ? `(${i.handle})` : ''}</option>
          ))}
        </select>
      </label>

      <label className="flex flex-col gap-1 text-sm">
        <span className="font-semibold text-text-secondary">{c.fee}</span>
        <input
          data-testid="propose-fee" inputMode="decimal" value={fee} onChange={(e) => setFee(e.target.value)}
          className="h-11 rounded-xl border border-border bg-surface px-3 text-sm text-text-primary" dir="ltr"
        />
      </label>

      <label className="flex flex-col gap-1 text-sm">
        <span className="font-semibold text-text-secondary">{c.why}</span>
        <textarea
          data-testid="propose-rationale" rows={3} value={rationale} onChange={(e) => setRationale(e.target.value)}
          className="rounded-xl border border-border bg-surface p-3 text-sm text-text-primary"
        />
      </label>

      {propose.isError && <p className="text-sm text-danger">{toApiError(propose.error).message}</p>}

      <div className="flex gap-2">
        <Button type="submit" size="sm" loading={propose.isPending} data-testid="propose-submit">{c.submit}</Button>
        <Button type="button" size="sm" variant="secondary" onClick={onDone}>{c.cancel}</Button>
      </div>
    </form>
  )
}

function NominationCard({
  nomination: n, c, canManage, canApprove, onChanged,
}: {
  nomination: Nomination
  c: Copy
  canManage: boolean
  canApprove: boolean
  onChanged: () => void
}) {
  const [rejecting, setRejecting] = useState(false)
  const [note, setNote] = useState('')
  const [title, setTitle] = useState('')

  const decide = useMutation({
    mutationFn: (v: { decision: 'approved' | 'rejected'; note?: string }) => decideNomination(n.id, v.decision, v.note),
    onSuccess: () => { setRejecting(false); onChanged() },
  })
  const withdraw = useMutation({ mutationFn: () => withdrawNomination(n.id), onSuccess: onChanged })
  const convert = useMutation({ mutationFn: () => convertNomination(n.id, { title }), onSuccess: onChanged })

  const statusLabel = {
    proposed: c.st_proposed, approved: c.st_approved, rejected: c.st_rejected, withdrawn: c.st_withdrawn,
  }[n.status]

  return (
    <li data-testid="nomination-card" data-status={n.status} className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-bold text-text-primary">{n.influencer?.name ?? '—'}</span>
        {n.influencer?.handle && <span className="text-sm text-text-muted" dir="ltr">{n.influencer.handle}</span>}
        {n.influencer?.followers != null && (
          <span className="tnum text-xs text-text-muted" dir="ltr">{n.influencer.followers.toLocaleString('en-US')} {c.followers}</span>
        )}
        <span className={`ms-auto rounded-full px-2.5 py-1 text-xs font-semibold ${STATUS_TONE[n.status]}`}>{statusLabel}</span>
      </div>

      {n.rationale && <p className="text-[13px] leading-relaxed text-text-secondary">{n.rationale}</p>}
      {n.proposed_fee && (
        <p className="tnum text-sm font-semibold text-text-primary" dir="ltr">{n.proposed_fee} {n.currency ?? ''}</p>
      )}

      {/* A rejection is shown WITH its reason. Hiding it is what makes the same creator come back. */}
      {n.decision_note && (
        <p data-testid="decision-note" className="rounded-xl bg-surface-secondary p-3 text-[13px] text-text-secondary">
          <span className="font-semibold text-text-primary">{c.decided}: </span>{n.decision_note}
        </p>
      )}

      {n.collaboration_id && (
        <p className="text-xs font-semibold text-success">{c.became}</p>
      )}

      {n.status === 'proposed' && (
        <div className="flex flex-wrap gap-2">
          {canApprove && (
            <>
              <Button size="sm" data-testid="approve-nomination" loading={decide.isPending}
                onClick={() => decide.mutate({ decision: 'approved' })}>
                <Check size={14} className="me-1" /> {c.approve}
              </Button>
              <Button size="sm" variant="secondary" data-testid="reject-nomination" onClick={() => setRejecting((v) => !v)}>
                <X size={14} className="me-1" /> {c.reject}
              </Button>
            </>
          )}
          {canManage && (
            <Button size="sm" variant="secondary" loading={withdraw.isPending} onClick={() => withdraw.mutate()}>
              {c.withdraw}
            </Button>
          )}
        </div>
      )}

      {rejecting && (
        <div className="flex flex-col gap-2">
          <textarea
            data-testid="reject-note" rows={2} value={note} onChange={(e) => setNote(e.target.value)}
            placeholder={c.reject_reason}
            className="rounded-xl border border-border bg-surface p-3 text-sm text-text-primary"
          />
          {/* Said before the request is made, because the server refuses it either way — this is the
              explanation, not the enforcement. */}
          {note.trim() === '' && <p className="text-xs text-text-muted">{c.reject_needs_reason}</p>}
          <Button
            size="sm" variant="danger" data-testid="confirm-reject"
            disabled={note.trim() === ''} loading={decide.isPending}
            onClick={() => decide.mutate({ decision: 'rejected', note })}
          >
            {c.confirm_reject}
          </Button>
        </div>
      )}

      {/* Offered only where it is real: approved, and not already turned into work. */}
      {n.is_convertible && canManage && (
        <div className="flex flex-wrap items-end gap-2 border-t border-border pt-3">
          <label className="flex flex-1 flex-col gap-1 text-sm">
            <span className="font-semibold text-text-secondary">{c.collab_title}</span>
            <input
              data-testid="collab-title" value={title} onChange={(e) => setTitle(e.target.value)}
              className="h-11 rounded-xl border border-border bg-surface px-3 text-sm text-text-primary"
            />
          </label>
          <Button size="sm" data-testid="convert-nomination" disabled={title.trim() === ''} loading={convert.isPending}
            onClick={() => convert.mutate()}>
            {c.make_collab}
          </Button>
        </div>
      )}

      {decide.isError && <p className="text-sm text-danger">{toApiError(decide.error).message}</p>}
      {convert.isError && <p className="text-sm text-danger">{toApiError(convert.error).message}</p>}
    </li>
  )
}
