# Form Controls Audit

Current: ~19 frontend files hold hardcoded option arrays (STATUSES, PRIORITIES, PLATFORMS, SERVICES, …) —
native `<select>`/checkboxes, no search, no management, no tenant options. Long single-column forms show all
fields at once.

Unified controls to build (src/components/forms/):
- SelectField (search, keyboard, clear, empty/loading/error, disabled options, inline +add when permitted)
- SearchableSelect · MultiSelectField (search, select/clear all, chips, max, remove, reorder, groups, dependent)
- CreatableSelect (add option → drawer → appears + selected, no refresh) · HierarchicalSelect
- AsyncSelect (server options) · TagInput · RadioCardGroup · CheckboxGroup · OptionManager (inline manage)

Rules: never dozens of options without search; never Placeholder-as-Label; add/manage gated by permission;
every create/edit → real backend + Audit; dependent selects re-fetch on parent change and drop invalid children.

Adoption targets (replace hardcoded): requests (intake + dashboard filters), clients (classification + filters),
campaigns (objective/platforms/tags/audiences), projects (status), reports (audience/type), onboarding (services),
alerts (rule type/severity/channels), integrations (category/provider).
