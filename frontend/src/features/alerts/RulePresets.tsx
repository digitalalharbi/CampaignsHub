import { Check, Plus } from 'lucide-react'
import type { AlertRule, NewAlertRule } from './api'
import { RULE_TEMPLATES, TEMPLATE_GROUPS, type RuleTemplate } from './ruleTemplates'

/**
 * ALERTS-PRESETS-001 — the ready-made rules, one click each.
 *
 * A preset counts as ADDED when the account already has a rule of that type with the same threshold, not when
 * the names match: a rule somebody renamed is still the rule, and offering it again would make the same
 * alert fire twice. The click posts the template's own payload through the one create path the form uses.
 */
const same = (a: Record<string, number> | null | undefined, b: Record<string, number> | undefined): boolean => {
  const ka = Object.keys(a ?? {}).sort()
  const kb = Object.keys(b ?? {}).sort()

  return ka.length === kb.length && ka.every((k, i) => k === kb[i] && Number((a ?? {})[k]) === Number((b ?? {})[k]))
}

export function isPresetAdded(template: RuleTemplate, rules: readonly AlertRule[]): boolean {
  return rules.some((r) => r.type === template.rule.type && same(r.threshold, template.rule.threshold))
}

export function RulePresets({ rules, onAdd, busy, ar }: { rules: readonly AlertRule[]; onAdd: (rule: NewAlertRule, name: string) => void; busy: boolean; ar: boolean }) {
  const sev: Record<string, string> = { critical: 'text-danger', warning: 'text-warning', info: 'text-info' }
  const sevLabel: Record<string, string> = ar ? { critical: 'حرج', warning: 'تحذير', info: 'معلومة' } : { critical: 'Critical', warning: 'Warning', info: 'Info' }

  return (
    <section data-testid="rule-presets" className="rounded-2xl border border-border bg-surface p-4">
      <h3 className="text-sm font-bold text-text-primary">{ar ? 'قواعد جاهزة' : 'Ready-made rules'}</h3>
      <p className="mb-3 text-xs text-text-muted">{ar ? 'أضف حماية جاهزة بضغطة واحدة، وعدّلها لاحقًا إن أردت.' : 'Add protection in one click and tune it later if you want.'}</p>
      <div className="space-y-4">
        {TEMPLATE_GROUPS.map((g) => (
          <div key={g.key}>
            <h4 className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-text-muted">{ar ? g.ar : g.en}</h4>
            <ul className="grid gap-2 sm:grid-cols-2">
              {RULE_TEMPLATES.filter((t) => t.group === g.key).map((t) => {
                const added = isPresetAdded(t, rules)

                return (
                  <li key={t.key} data-testid={`preset-${t.key}`} className="flex items-start justify-between gap-3 rounded-xl border border-border p-3">
                    <div className="min-w-0">
                      <p className="flex items-center gap-2 text-sm font-semibold text-text-primary">
                        {ar ? t.name.ar : t.name.en}
                        <span className={`text-[10px] font-bold ${sev[t.rule.severity ?? 'warning']}`}>{sevLabel[t.rule.severity ?? 'warning']}</span>
                      </p>
                      <p className="mt-0.5 text-xs text-text-secondary">{ar ? t.why.ar : t.why.en}</p>
                    </div>
                    <button
                      type="button"
                      data-testid={`preset-add-${t.key}`}
                      disabled={added || busy}
                      onClick={() => onAdd({ ...t.rule, name: ar ? t.name.ar : t.name.en }, t.key)}
                      className={`flex shrink-0 items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold ${added ? 'bg-surface-secondary text-text-muted' : 'bg-brand-600 text-white'} disabled:opacity-70`}
                    >
                      {added ? <Check size={13} /> : <Plus size={13} />} {added ? (ar ? 'مضافة' : 'Added') : (ar ? 'إضافة' : 'Add')}
                    </button>
                  </li>
                )
              })}
            </ul>
          </div>
        ))}
      </div>
    </section>
  )
}
