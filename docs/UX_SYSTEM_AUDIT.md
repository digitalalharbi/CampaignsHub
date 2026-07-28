# UX System Audit (live review of the four surfaces)

Findings driving this phase:
- **Classifications hardcoded** in React (~19 files) → not tenant-configurable, duplicated, drift risk.
- **Selects** are native, unsearchable, no multi-select management, no "add option".
- **Forms** render all fields at once (intake/onboarding long single column); Placeholders used as Labels in
  places; no stepper/draft on long forms.
- **Integrations page** is a long vertical list — doesn't use full width; Drive was a separate nav (now folded).
- **Marketing homepage** is long with repeated/again-styled cards, uneven card heights, small product preview,
  hero+CTA not fully in first viewport on 1440×900; mobile stacks many cards.
- **Density/altitude**: some pages scroll excessively; actions scattered; state not always obvious.

Plan: central taxonomy engine → unified searchable/manageable controls → adopt across modules with dependent
selects → redesign Integrations (tabs/grid/drawer, full width) → shorten/rebalance homepage → forms
(steppers/draft/validation/error-summary) → responsive + RTL/LTR + light/dark → E2E on 3 browsers. No data loss,
no placeholders, no dead buttons, permission-aware, tenant-isolated, audited.
