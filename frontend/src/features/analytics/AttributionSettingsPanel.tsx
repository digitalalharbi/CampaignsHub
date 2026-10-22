import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getAttributionSettings, updateAttributionSettings, type AttributionAdvanced, type AttributionSettingsUpdate } from './api'
import { toApiError } from '@/lib/api/client'
import { useProject } from '@/stores/project'

/**
 * ATTRIB-PRESETS-001 — the preset chooser: BASIC / STANDARD / ADVANCED / CUSTOM over the one engine.
 *
 * It renders nothing when the module is off for this project (the endpoint answers 403) or while the
 * catalogue has not arrived, so the attribution panel reads exactly as before for everyone else. A
 * preset is a decision, not a suggestion: choosing one writes the preset's models; only ADVANCED and
 * CUSTOM expose the advanced options, and only CUSTOM exposes the weights. Every save is a version,
 * listed newest first, so the comparison above can be named with the configuration it ran under.
 */
export function AttributionSettingsPanel({ ar, projectId: given }: { ar: boolean; projectId?: string | null }) {
  // The analytics page names its project explicitly (the agency portal's picker is not the global store); the store is the fallback.
  const stored = useProject((s) => s.currentProjectId)
  const projectId = given ?? stored
  const qc = useQueryClient()
  const q = useQuery({
    queryKey: ['attribution-settings', projectId],
    queryFn: () => getAttributionSettings(projectId as string),
    enabled: !!projectId,
    retry: false,
  })
  const [preset, setPreset] = useState<string | null>(null)
  const [advanced, setAdvanced] = useState<AttributionAdvanced | null>(null)
  const [weights, setWeights] = useState<Record<string, string>>({ first: '0.4', middle: '0.2', last: '0.4' })

  useEffect(() => {
    if (!q.data) return
    const current = q.data.current
    setPreset(current?.preset ?? q.data.default_preset)
    setAdvanced(current?.advanced ?? q.data.advanced_defaults)
    if (current?.weights) setWeights(Object.fromEntries(Object.entries(current.weights).map(([k, v]) => [k, String(v)])))
  }, [q.data])

  const save = useMutation({
    mutationFn: (body: AttributionSettingsUpdate) => updateAttributionSettings(projectId as string, body),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['attribution-settings', projectId] }) },
  })

  if (!projectId || q.isError || !q.data || !preset) return null
  const catalogue = q.data.presets
  const chosen = catalogue[preset]
  const showsAdvanced = chosen?.advanced === true
  const isCustom = preset === 'custom'
  const channels = advanced?.eligible_channels?.join(', ') ?? ''

  const submit = () => {
    const body: AttributionSettingsUpdate = { preset }
    if (showsAdvanced && advanced) body.advanced = advanced
    if (isCustom) body.weights = Object.fromEntries(Object.entries(weights).map(([k, v]) => [k, Number(v)]))
    save.mutate(body)
  }
  const err = save.isError ? toApiError(save.error) : null

  return (
    <section data-testid="attribution-presets" className="min-w-0 border-t border-border pt-4">
      <h4 className="text-xs font-bold uppercase tracking-wide text-text-muted">{ar ? 'نموذج الإسناد — الإعداد المسبق' : 'Attribution — preset'}</h4>
      <p className="mt-1 text-xs text-text-secondary">
        {ar
          ? 'اختيار واحد يحدّد النماذج التي يحسبها المحرك. «أساسي» هو الافتراضي؛ المتقدم مطفأ ما لم تختره.'
          : 'One choice decides which models the engine runs. Basic is the default; advanced is off unless you choose it.'}
      </p>
      <div role="radiogroup" aria-label={ar ? 'الإعداد المسبق' : 'Preset'} className="mt-3 grid gap-2 sm:grid-cols-2">
        {Object.entries(catalogue).map(([key, p]) => (
          <label key={key} className={`flex cursor-pointer items-start gap-2 rounded-xl border p-3 text-sm ${preset === key ? 'border-brand-500 bg-surface-hover' : 'border-border'}`}>
            <input type="radio" name="attribution-preset" value={key} checked={preset === key} onChange={() => setPreset(key)} data-testid={`attr-preset-${key}`} className="mt-0.5" />
            <span className="min-w-0">
              <span className="block font-semibold text-text-primary">{ar ? p.label_ar : p.label_en}</span>
              <span className="block text-[11px] text-text-muted" dir="ltr">{p.models.join(' · ')}</span>
            </span>
          </label>
        ))}
      </div>

      {showsAdvanced && advanced && (
        <fieldset data-testid="attr-advanced" className="mt-3 grid gap-2 rounded-xl border border-border p-3 text-xs">
          <legend className="px-1 font-bold text-text-muted">{ar ? 'الخيارات المتقدمة' : 'Advanced options'}</legend>
          <label className="flex items-center gap-2"><input type="checkbox" data-testid="attr-honour-consent" checked={advanced.honour_consent} onChange={(e) => setAdvanced({ ...advanced, honour_consent: e.target.checked })} />{ar ? 'احترام موافقة الزائر' : 'Honour visitor consent'}</label>
          <label className="flex items-center gap-2"><input type="checkbox" data-testid="attr-exclude-refunds" checked={advanced.exclude_refunds} onChange={(e) => setAdvanced({ ...advanced, exclude_refunds: e.target.checked })} />{ar ? 'استبعاد المرتجعات من الإسناد' : 'Exclude refunds from credit'}</label>
          <label className="grid gap-1">
            <span>{ar ? 'القنوات المؤهلة (فارغ = كل القنوات)' : 'Eligible channels (empty = every channel)'}</span>
            <input data-testid="attr-channels" dir="ltr" className="rounded-lg border border-border bg-transparent px-2 py-1" value={channels} placeholder="snapchat, tiktok" onChange={(e) => {
              const list = e.target.value.split(',').map((s) => s.trim()).filter(Boolean)
              setAdvanced({ ...advanced, eligible_channels: list.length === 0 ? null : list })
            }} />
          </label>
        </fieldset>
      )}

      {isCustom && (
        <fieldset data-testid="attr-weights" className="mt-3 grid grid-cols-3 gap-2 rounded-xl border border-border p-3 text-xs">
          <legend className="px-1 font-bold text-text-muted">{ar ? 'الأوزان (الأولى · الوسطى · الأخيرة)' : 'Weights (first · middle · last)'}</legend>
          {(['first', 'middle', 'last'] as const).map((slot) => (
            <input key={slot} data-testid={`attr-weight-${slot}`} dir="ltr" type="number" min="0" step="0.05" className="rounded-lg border border-border bg-transparent px-2 py-1" value={weights[slot] ?? ''} onChange={(e) => setWeights({ ...weights, [slot]: e.target.value })} />
          ))}
        </fieldset>
      )}

      <div className="mt-3 flex items-center gap-3">
        <button type="button" data-testid="attr-preset-save" onClick={submit} disabled={save.isPending} className="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-bold text-white disabled:opacity-60">
          {save.isPending ? (ar ? 'جارٍ الحفظ…' : 'Saving…') : (ar ? 'حفظ الإعداد' : 'Save preset')}
        </button>
        {save.isSuccess && <span className="text-xs text-success">{ar ? 'حُفظ — نسخة جديدة' : 'Saved — a new version'}</span>}
        {err && <span className="text-xs text-danger">{err.message}</span>}
      </div>

      {q.data.versions.length > 0 && (
        <ol data-testid="attr-versions" className="mt-3 grid gap-1 text-[11px] text-text-secondary">
          {q.data.versions.slice(0, 5).map((v) => (
            <li key={v.number} data-testid={`attr-version-${v.number}`}>
              <span dir="ltr">#{v.number}</span> · {ar ? catalogue[v.preset]?.label_ar ?? v.preset : catalogue[v.preset]?.label_en ?? v.preset}
              {v.created_at && <span dir="ltr"> · {v.created_at.slice(0, 16).replace('T', ' ')}</span>}
            </li>
          ))}
        </ol>
      )}
    </section>
  )
}
