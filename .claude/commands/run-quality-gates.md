---
description: Run backend + frontend quality gates (pint, phpstan, tests, tsc, eslint, vitest).
---

Run the project quality gates and report pass/fail with the failing output only.

```bash
bash .claude/hooks/quality-gate.sh ${ARGUMENTS:-all}
```

Do not mark any task complete if this fails. Fix the root cause, then re-run.
