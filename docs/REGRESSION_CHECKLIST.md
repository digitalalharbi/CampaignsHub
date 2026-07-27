# CampaignsHub — Regression Checklist

Run after **every** phase before moving on. The mandate: focusing on a new phase must not break prior features.
Mark each `PASS` / `FAIL` / `SKIP (reason)` with the date + phase that ran it.

## A. Core user journey (Review 2, run as a real user)
- [ ] Open `/login` directly (full page, not modal)
- [ ] Visit a protected route unauthenticated → redirected to `/login`
- [ ] After login → land on originally requested page or dashboard
- [ ] Register a new tenant + owner
- [ ] Forgot-password → generic success (no enumeration)
- [ ] Navigate between modules
- [ ] Save a record → Refresh → value persists
- [ ] Logout → protected routes locked again → Login again
- [ ] Mobile (375×812): single column, no horizontal scroll, keyboard doesn't cover submit
- [ ] Permissions: viewer cannot mutate; analyst/owner boundaries hold
- [ ] Error cases: bad credentials, validation errors, network drop show clear messages

## B. Carry-over features (must stay green)
- [ ] Campaign Command Center: all 10 tabs load real data, isolation holds
- [ ] Objective-based report renders correct KPIs/charts per objective
- [ ] Arabic client PDF exports via button → Chromium (never Dompdf) → audited bytes
- [ ] Analytics/creatives show real source data or "Awaiting Credentials" (no rand/fake thumbnails)
- [ ] Alerts list scoped by entity
- [ ] Project/tenant isolation: cross-project IDs 404

## C. Cross-cutting UI
- [ ] RTL (ar) and LTR (en) both correct
- [ ] Light and Dark both correct
- [ ] Latin digits everywhere
- [ ] No console errors
- [ ] No critical network (4xx/5xx unexpected) errors

## D. Automated gates (no hidden flakiness)
```bash
cd backend && php artisan test && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G
cd ../frontend && npm run typecheck && npm run lint && npm run test && npm run build && npm run e2e
npx playwright test --workers=1 --retries=0 --repeat-each=3
```

## Run log
| Date | Phase | A | B | C | D | Notes |
|------|-------|---|---|---|---|-------|
| 2026-07-27 | Governance docs + flake diagnosis | n/a | vitest 13/13, e2e auth-forms 7/7 | RTL/forms verified | vitest+auth e2e green | G-001 logged as Watch |
| 2026-07-27 | G-005 redirect + throttle fix | login→redirect live | backend 157/157 | RTL/forms | e2e auth 24/24 @repeat-each=3 | G-007 diagnosed+fixed (login throttle) |
| 2026-07-27 | Auth visual unification | login light+dark+mobile live | metrics-mock: suite 22/22 ×8 | RTL, no overflow 320/375, no console err | vitest 22/22, auth e2e 10/10 | purple removed; G-001 mitigated |
| 2026-07-27 | Auth cross-browser acceptance | /login light+dark live | vitest 22/22 | RTL/LTR, 320/375/390, no InfluencerHub text | auth e2e 39/39 (Chromium+Firefox+WebKit) + visual 6 + kbd/console | R1.6 Completed; G-008 logged |
| 2026-07-27 | User profile/password/security | name change reflects+persists live | backend 165/165, vitest 22/22 | RTL, mobile settings nav | account e2e 4/4 + MeAccountTest 8 | G-009 logged (sessions/2FA infra) |
| 2026-07-27 | Public homepage | / live RTL light+dark, preview interactive | vitest 22/22 | RTL/LTR, mobile 375, no overflow | homepage e2e 9/9 (3 browsers) + auth/account 17/17 | app→/dashboard, no regression |
