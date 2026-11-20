import { useMemo, useState } from 'react'
import { History, RotateCw, Save, UserMinus, XCircle } from 'lucide-react'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { Alert } from '@/components/ui/Alert'
import { toApiError } from '@/lib/api/client'
import { fmtDate } from '@/lib/datetime'
import { canAssign, diffLevels, diffSections, levelsOf, partialOf, useAccessActions, useAccessHistory, type AccessData, type AccessInvitation, type AccessMember, type Level, type SectionStates } from './accessApi'
import { AreaChips, AreaToggles, ChipPicker } from './AreaControls'
import { ACTION_LABEL, PORTAL_ROLE_LABEL } from './labels'
import { SectionToggles } from './SectionToggles'

export type DrawerSubject = { kind: 'member'; row: AccessMember } | { kind: 'invitation'; row: AccessInvitation }

/**
 * ACCESS-CENTER-001 — one row, everything about it: category, areas for this person, clients, resend / withdraw /
 * disable / remove, and who changed what. No bulk delete anywhere; removing is one person, one confirmation.
 */
export function AccessDrawer({ subject, onClose, data, ar }: { subject: DrawerSubject | null; onClose: () => void; data: AccessData; ar: boolean }) {
  if (subject === null) return null
  return (
    <Modal open onClose={onClose} title={subject.kind === 'member' ? subject.row.name : subject.row.email} size="lg">
      <DrawerBody key={`${subject.kind}-${subject.row.id}`} subject={subject} onClose={onClose} data={data} ar={ar} />
    </Modal>
  )
}

function DrawerBody({ subject, onClose, data, ar }: { subject: DrawerSubject; onClose: () => void; data: AccessData; ar: boolean }) {
  const a = useAccessActions()
  const row = subject.row
  const team = row.audience === 'team'
  const isMember = subject.kind === 'member'
  const member = isMember ? subject.row : null
  // OWNER-DOWNGRADE-001 — another workspace manager may move an owner to a different category (a mistaken promotion is
  // reversible). An owner is still never narrowed in place: only the category picker is offered, with a confirmation.
  const ownerMovable = member !== null && member.is_owner && !member.is_self && data.me.is_owner && team
  const locked = member !== null && (member.is_owner || member.is_self)
  const [confirmDowngrade, setConfirmDowngrade] = useState(false)

  const [category, setCategory] = useState(row.category ?? '')
  const cat = data.categories.find((c) => c.slug === category)
  const base = useMemo(() => levelsOf(cat?.areas), [cat])
  // A person's current areas are their category plus their overrides; the toggles start from there.
  const [levels, setLevels] = useState<Record<string, Level>>(() => levelsOf(row.areas))
  // ACCESS-PARTIAL-LEVEL-001 — every area the reader clicks is sent at the chosen level, in full.
  const [touched, setTouched] = useState<string[]>([])
  const [clients, setClients] = useState<string[]>(row.scope.clients.map((c) => c.id))
  // ACCESS-SECTIONS-001 — layer three, pre-filled with the person's current sections; only changed ones are sent.
  const startSections: SectionStates = row.sections ?? {}
  const [sections, setSections] = useState<SectionStates>(startSections)
  const sectionChanges = diffSections(startSections, sections)
  const [confirmRemove, setConfirmRemove] = useState(false)
  const [err, setErr] = useState('')
  const [ok, setOk] = useState('')
  const history = useAccessHistory(isMember ? 'member' : 'invitation', row.id)

  const changedCategory = category !== row.category
  const chosen = changedCategory ? { ...base, ...diffLevels(levelsOf(row.areas), levels) } : levels
  const overrides: Record<string, Level> = { ...diffLevels(base, chosen), ...Object.fromEntries(touched.map((k) => [k, chosen[k] ?? 'none'])) }
  const rowPartial = changedCategory ? partialOf(cat?.areas) : partialOf(row.areas)

  const run = async (p: Promise<unknown>, done: string) => {
    setErr(''); setOk('')
    try { await p; setOk(done) } catch (e) { setErr(toApiError(e).message) }
  }

  const save = () => run((async () => {
    if (isMember) {
      // One save, one audit row: category, areas, sections and clients together (applies on the member's next request).
      const before = row.scope.clients.map((c) => c.id).sort().join()
      const clientsChanged = data.agency && before !== [...clients].sort().join()
      await a.updateMember.mutateAsync({
        id: row.id,
        ...(changedCategory ? { category } : {}),
        areas: overrides,
        sections: sectionChanges,
        ...(clientsChanged ? { client_ids: clients } : {}),
      })
    } else {
      await a.updateInvitation.mutateAsync({ id: row.id, category, areas: overrides, sections: sectionChanges, client_ids: clients })
    }
  })(), ar ? 'حُفظ' : 'Saved')

  return (
    <div className="space-y-4" data-testid="access-drawer">
      {err && <Alert severity="danger" title={ar ? 'لم يُنفّذ' : 'Not done'}>{err}</Alert>}
      {ok && <Alert severity="positive" title={ok} />}

      <div className="flex flex-wrap items-center gap-2 text-xs text-text-muted">
        <span dir="ltr">{row.email}</span>
        {member?.last_login_at && <span>· {ar ? 'آخر دخول' : 'Last sign-in'} {fmtDate(member.last_login_at)}</span>}
        {subject.kind === 'invitation' && <span>· {ar ? 'ينتهي' : 'Expires'} {fmtDate(subject.row.expires_at)}</span>}
      </div>

      {!team ? (
        <section className="space-y-3 rounded-xl border border-border p-3 text-sm" data-testid="drawer-portal">
          <div className="font-semibold text-text-primary">{ar ? 'بوابة العميل' : 'Client portal'} · <span className="text-text-muted">{row.scope.clients.map((c) => c.name).join('، ')}</span></div>
          {member ? (
            <>
              {/* ACCESS-PORTAL-ROLE-001 — raise or lower an active portal person here; their clients stay as granted. */}
              <ChipPicker testId="drawer-portal-role" label={ar ? 'الدور' : 'Role'} value={category} onChange={setCategory}
                options={Object.entries(PORTAL_ROLE_LABEL).map(([value, l]) => ({ value, label: ar ? l.ar : l.en }))} />
              <div className="flex justify-end">
                <Button disabled={!changedCategory} onClick={() => run(a.updateMember.mutateAsync({ id: row.id, category }), ar ? 'حُفظ' : 'Saved')} data-testid="drawer-portal-save">
                  <Save size={14} aria-hidden /> {ar ? 'حفظ الدور' : 'Save role'}
                </Button>
              </div>
            </>
          ) : (
            <div className="text-xs text-text-muted">{ar ? (PORTAL_ROLE_LABEL[row.category ?? '']?.ar ?? row.category) : (PORTAL_ROLE_LABEL[row.category ?? '']?.en ?? row.category)}</div>
          )}
        </section>
      ) : ownerMovable ? (
        <section className="space-y-3 rounded-xl border border-border p-3" data-testid="drawer-owner-move">
          <AreaChips areas={data.areas} levels={levelsOf(row.areas)} ar={ar} />
          <div>
            <h3 className="mb-1.5 text-sm font-bold text-text-primary">{ar ? 'ترقية أو تخفيض — اختر الفئة الجديدة' : 'Promote or demote — choose the new category'}</h3>
            <ChipPicker testId="drawer-owner-category" label={ar ? 'الفئة' : 'Category'} value={category} onChange={(s) => { setCategory(s); setConfirmDowngrade(false) }}
              options={data.categories.map((c) => ({ value: c.slug, label: ar ? c.name_ar : c.name_en }))} />
          </div>
          {changedCategory && (
            <Alert severity="warning" title={ar ? 'سيفقد صلاحية مدير المساحة' : 'They will lose workspace-manager access'}>
              {ar ? 'بعد الحفظ تقدر تحدد الأقسام والعملاء اللي يظهرون له من نفس النافذة.' : 'After saving you can choose the sections and clients they see from this same window.'}
            </Alert>
          )}
          <div className="flex justify-end">
            {!confirmDowngrade
              ? <Button disabled={!changedCategory} onClick={() => setConfirmDowngrade(true)} data-testid="drawer-owner-move-save"><Save size={14} aria-hidden /> {ar ? 'حفظ' : 'Save'}</Button>
              : <Button variant="danger" onClick={() => run(a.updateMember.mutateAsync({ id: row.id, category }).then(onClose), '')} data-testid="drawer-owner-move-confirm">{ar ? 'تأكيد تغيير الفئة' : 'Confirm category change'}</Button>}
          </div>
        </section>
      ) : locked ? (
        <section className="rounded-xl border border-border p-3" data-testid="drawer-locked">
          <AreaChips areas={data.areas} levels={levelsOf(row.areas)} ar={ar} />
          <p className="mt-2 text-xs text-text-muted">{member?.is_owner ? (ar ? 'المالك يملك كل شيء ولا يُقيَّد.' : 'The owner holds everything.') : (ar ? 'لا تعدّل صلاحياتك بنفسك.' : 'You cannot change your own access.')}</p>
        </section>
      ) : (
        <>
          <section>
            <h3 className="mb-1.5 text-sm font-bold text-text-primary">{ar ? 'الفئة' : 'Category'}</h3>
            <ChipPicker testId="drawer-category" label={ar ? 'الفئة' : 'Category'} value={category} onChange={(s) => { setCategory(s); setTouched([]); setLevels(levelsOf(data.categories.find((c) => c.slug === s)?.areas)) }}
              options={data.categories.map((c) => ({ value: c.slug, label: ar ? c.name_ar : c.name_en, disabled: c.slug !== row.category && !canAssign(c, data.me) }))} />
          </section>
          {(data.sections ?? []).length > 0 && (
            <section>
              <h3 className="mb-1.5 text-sm font-bold text-text-primary">{ar ? 'الأقسام' : 'Sections'}</h3>
              <SectionToggles sections={data.sections ?? []} states={sections} base={startSections} ar={ar} testId="drawer-sections"
                onChange={(k, on) => setSections((x) => ({ ...x, [k]: on }))} />
            </section>
          )}
          <section>
            <h3 className="mb-1.5 text-sm font-bold text-text-primary">{ar ? 'تخصيص له فقط' : 'For this person only'}</h3>
            <AreaToggles areas={data.areas} levels={chosen} base={base} grantable={data.me.grantable} ar={ar} partial={rowPartial} touched={touched}
              onChange={(area, lv) => { setLevels((l) => ({ ...l, [area]: lv })); setTouched((t) => (t.includes(area) ? t : [...t, area])) }} />
          </section>
          {data.agency && data.clients.length > 0 && (
            <section>
              <h3 className="mb-1.5 text-sm font-bold text-text-primary">{ar ? 'العملاء' : 'Clients'}</h3>
              <div className="flex flex-wrap gap-1.5" data-testid="drawer-clients">
                {data.clients.map((c) => {
                  const on = clients.includes(c.id)
                  return (
                    <button key={c.id} type="button" aria-pressed={on} onClick={() => setClients((xs) => on ? xs.filter((x) => x !== c.id) : [...xs, c.id])}
                      className={`rounded-full border px-3 py-1 text-xs font-semibold ${on ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-border text-text-secondary'}`}>
                      {c.name}
                    </button>
                  )
                })}
              </div>
            </section>
          )}
          <section>
            <h3 className="mb-1.5 text-sm font-bold text-text-primary">{ar ? 'ماذا سيرى' : 'What they will see'}</h3>
            <AreaChips areas={data.areas} levels={chosen} ar={ar} changed={Object.keys(overrides)} testId="drawer-preview" />
          </section>
          <div className="flex justify-end">
            <Button onClick={save} data-testid="drawer-save"><Save size={14} aria-hidden /> {ar ? 'حفظ' : 'Save'}</Button>
          </div>
        </>
      )}

      <div className="flex flex-wrap gap-2 border-t border-border pt-3">
        {subject.kind === 'invitation' && (
          <>
            <Button variant="secondary" size="sm" onClick={() => run(a.resend.mutateAsync(row.id), ar ? 'أُعيد الإرسال' : 'Sent again')} data-testid="drawer-resend"><RotateCw size={13} aria-hidden /> {ar ? 'أعد الإرسال' : 'Resend'}</Button>
            <Button variant="secondary" size="sm" onClick={() => run(a.revoke.mutateAsync(row.id).then(onClose), '')} data-testid="drawer-revoke"><XCircle size={13} aria-hidden /> {ar ? 'اسحب الدعوة' : 'Withdraw'}</Button>
          </>
        )}
        {member && team && !locked && (
          <>
            <Button variant="secondary" size="sm" onClick={() => run(a.toggle.mutateAsync(row.id), ar ? 'تم' : 'Done')} data-testid="drawer-toggle">{member.status === 'disabled' ? (ar ? 'فعّل' : 'Enable') : (ar ? 'عطّل' : 'Disable')}</Button>
            {!confirmRemove
              ? <Button variant="ghost" size="sm" onClick={() => setConfirmRemove(true)} data-testid="drawer-remove"><UserMinus size={13} aria-hidden /> {ar ? 'إزالة' : 'Remove'}</Button>
              : <Button variant="danger" size="sm" onClick={() => run(a.remove.mutateAsync(row.id).then(onClose), '')} data-testid="drawer-remove-confirm">{ar ? 'تأكيد الإزالة' : 'Confirm removal'}</Button>}
          </>
        )}
      </div>

      <section data-testid="drawer-history">
        <h3 className="mb-1.5 flex items-center gap-1.5 text-sm font-bold text-text-primary"><History size={14} aria-hidden /> {ar ? 'السجل' : 'History'}</h3>
        {(history.data?.entries ?? []).length === 0
          ? <p className="text-xs text-text-muted">{ar ? 'لا تغييرات بعد' : 'No changes yet'}</p>
          : (
            <ul className="space-y-1 text-xs">
              {history.data!.entries.map((h, i) => (
                <li key={i} className="flex flex-wrap gap-x-2 text-text-secondary">
                  <span className="font-semibold text-text-primary">{ar ? (ACTION_LABEL[h.action]?.ar ?? h.action) : (ACTION_LABEL[h.action]?.en ?? h.action)}</span>
                  <span>{fmtDate(h.at)}</span>
                  {h.by && <span>· {h.by}</span>}
                  {h.reason && <span>· {h.reason}</span>}
                </li>
              ))}
            </ul>
          )}
      </section>
    </div>
  )
}
