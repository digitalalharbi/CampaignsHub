<!--
Answer every section. "None" is an answer and a useful one; a blank line is not.
A section left empty is read as "not considered", because that is usually what it means.
-->

## Requirement / issue

Closes #

Requirement id from `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`, if this change has one:

## Root cause

<!-- The mechanism, not the symptom. If this is a fix, say what was actually wrong and how you
     proved it — a failing test that passes afterwards is the proof. If it is new work, say what
     was missing and why it belongs here. -->

## Scope

<!-- What changed, in files and in behaviour. And what deliberately did NOT change, where somebody
     might reasonably expect it to have. -->

## Tests

<!-- Which tests were added or changed, and the actual output. For a fix: state that it failed
     first, and how it failed. Paste the counts. -->

```
backend  :
frontend :
gate     :
```

- [ ] Fail-first demonstrated (for a defect)
- [ ] `php artisan test` green · `vendor/bin/pint --test` clean
- [ ] `npm run typecheck` · `npm run lint` · `npm test` · `npm run build` all clean
- [ ] Three-browser gate run, exit code captured on its own line (when the change is reachable from a browser)

## Security

<!-- New endpoint, new permission, new input, new file upload, anything touching auth or sessions?
     Say how it fails closed. If none of this applies, say so. -->

## Tenant isolation

<!-- Does anything here read or write rows across workspaces? Which test proves one workspace
     cannot see another's data through this path? -->

## Database migration

- [ ] No migration
- [ ] Migration included — reversible, and exercised up → down → up
- [ ] Migration **rewrites existing rows** (say which, and what a rollback does to them)

## External integrations

<!-- Does this change how a provider is called, mapped or reported? Does any status move?
     Remember: nothing becomes LIVE_VERIFIED without real external evidence. -->

## Deployment notes

<!-- Anything the deploy must do beyond the usual: a queue restart, a cache clear, an env variable,
     an order the steps must happen in. "Nothing beyond DEPLOYMENT_CHECKLIST.md" is a fine answer. -->

## Rollback

<!-- How to undo this if it goes wrong in production, and what is lost if you do. -->

## Evidence

<!-- Command output, screenshots, a webhook delivery id — the thing itself, not a claim about it. -->

---

- [ ] `docs/RESUME_STATE.md` and the traceability matrix updated, if a requirement's status moved
- [ ] No secret added, in any file, in any commit of this branch
- [ ] No `LIVE_VERIFIED` claimed without real external evidence
