# Builder EM — email-client compatibility (EMAIL-CLIENT-COMPAT-001)

Branch `feature/email-client-compat` on `feature/project-lifecycle` @ `0692fbfa7`. Not pushed, no PR. Status `IMPLEMENTED_NOT_VERIFIED`:
the constraint lint and the engine simulations pass here; **real Gmail / Outlook / Apple Mail rendering is not verified** (no mailbox,
no sends allowed).

## What the Owner saw (Gmail Android, dark mode, non-Google account) and the constructs that caused it

The Gmail app on a non-Google (IMAP) account drops `<style>` whole, honours only part of inline CSS, and force-inverts colours per
element in dark mode. Confirmed in the code at `0692fbfa7`:

| Defect | Offending construct |
|---|---|
| (1) narrow / ragged blocks | layout in `<style>`: `.ch-pad {padding:16px}`, `.ch-outer`, `.ch-kpi {display:block; width:100%}` / `.ch-stack` under 620px — a browser on a phone got 16px padding and stacked KPI cells, Gmail got 24px and side-by-side cells: two different layouts. Legend/health rows shrink-wrapped (`<table>` without `width`). |
| (2) decision cards break | `_issues`: a third 84px column with `<a style="display:block; padding:6px 2px">` (label wraps «افتح / التنبيهات») squeezing the text column to ~110px («تيك / توك»); status ring = `<td>` with `border-radius:50%` + border but no fixed box (`line-height:1`, no width attribute on its table) → stretched oval; leading band corners `10px 0 0 10px` in RTL too. |
| (3) chips/tiles of varying size | `_chip`: `display:inline-block; min-width:…` (min-width dropped); heatmap: a `display:block` span stretched inside a padded cell; the four count tiles had no `width`. |
| (4) dark mode fights the design | ~60% of cells and text elements declared no background/colour (inherited only), so Gmail's inversion re-coloured them by its own guess; `color:inherit` links. Unitless `line-height:1.x` everywhere (2,078 hits). |
| (5) rhythm differs | margins on divs/tables (Outlook drops them on tables; Gmail drops the negative `margin:-8px` under the portfolio title); `min-height:44px` on the CTA. |
| also | the 30-project × 6-platform portfolio digest was **177 KB** — over Gmail's ~102 KB clip («[Message clipped]»). Two messages overflowed at 360px (`alert-report-failed.en` 22px, `report-ready.en` 8px). |

## What changed — technique only

1. `EmailSafeHtml` (backend/app/Domains/Notifications/Support): `mail.layout` renders `mail.shell` and passes the HTML through one
   deterministic, idempotent pass on EVERY render path (send, test send, gallery, preview, tests): `<style>` rules outside `@media`
   inlined (rules parsed by `tijsverkoyen/css-to-inline-styles`, already a Laravel mail dependency); vertical margins → fixed-height
   spacer tables with browser margin-collapsing (so the rhythm is kept); `bgcolor` + inline `background-color` on every `<td>`, a
   rounded card's corners handed to the cells that fill it; explicit `color` on every text element and text cell (`inherit` resolved);
   `role="presentation"`, `cellpadding/cellspacing/border="0"`, `dir` on every table, `align` on text cells; px line-heights;
   indentation whitespace collapsed (~25 KB less on the big digests).
2. Shell `<style>` is progressive only: the layout rules are gone; it keeps the dark brand band for `prefers-color-scheme` /
   `[data-ogsc]`/`[data-ogsb]`. Outer cell padding 24px 8px (the 8px is invisible above 616px). `color-scheme` metas unchanged.
3. Templates: decision card = 2 columns (fixed 76px figure column + text) with a bullet-proof nowrap button under the text on the end
   side (`partials/card-button`), WHERE pieces nowrap + `keep-all`; ring and glyph marks are fixed-size table cells (radius as
   enhancement); heatmap chips are their own cells with `cellspacing="2"` (also −30 KB); chip `width` instead of `min-width`; budget
   glyphs fixed cells; steps a flowing line of whole chips; legend/health rows full width; CTA nowrap + VML round-rect for Outlook;
   daily-digest CTA and consent button bullet-proof cells; scheduled report two figures per row; raw link without `max-width`.
   Wording, colours, order and internal/external semantics (builder Q2) untouched.

## Before → after at 390px (chromium, all 100 gallery messages, screenshots in the scratchpad `shots/`)

- Every message: content column 16px narrower on a phone (24px padding everywhere, as Gmail already showed it); text reflows.
- Decision cards: the button moved from a side column to under the text (end side); titles and headlines no longer wrap word by word.
- Daily/weekly/monthly «ملخص» and scheduled report: KPI cells side by side (2 per row in the scheduled report) instead of stacked —
  65–142px shorter. Project/portfolio digests 77–188px taller (wider padding, button rows).
- Main CTA no longer stretches to full width under 480px (that was a `<style>` rule); centred, as on desktop.
- No horizontal overflow at 360/390/600 (before: two messages overflowed at 360).
- Sizes: every message < 100 KB except the 30-project stress fixtures (`digest-projects-*`): 177 KB → ~150 KB, **still over Gmail's
  clip**. A real 30-project digest will be clipped; fixing that needs a content decision (e.g. healthy heatmap rows collapsed), not a
  technique change — Owner decision.

`InternalMailsUnchangedTest` golden hashes regenerated deliberately (88 messages): the markup changed by design, nothing else.

## Tests

- `tests/Feature/EmailClientCompatTest.php` — every gallery message + 7 external templates + confirmation × ar/en (120): no
  flex/grid/gap/position/float/var()/calc()/rem/vw/vh/min-width/min-height/background shorthand/negative margin/unitless line-height/
  `color:inherit`; max-width only on the 600px wrapper, the logo, the hidden preheader; every class also has an inline style; every
  table presentation/border 0/cellpadding 0/dir; every td bgcolor + inline background; every text node explicit colour; images
  width/height/alt/display:block; buttons nowrap; decision cards two columns with the 76px column; every top-level block
  `width="100%"`; size budget; colour-scheme metas; the pass idempotent; the array-transport send has a text part and the same safe HTML.
- `frontend/e2e-mail/email-compat.spec.ts` (`playwright.mail.config.ts`, not in the app suite): chromium/webkit/firefox × 360/390/600 ×
  normal / `<style>` stripped / colour-inverted: no overflow, equal block edges ±1px, no wrapped button or title, stripped vs normal
  pixel diff ≤ 0.5% at 390px. Run: `php artisan notifications:preview --compat --out=DIR` then `MAIL_PREVIEW_DIR=DIR npx playwright test -c playwright.mail.config.ts`.
- Results here: Playwright chromium 120/120 and webkit 120/120 green (all 120 messages × 3 widths × 3 modes); **firefox did not
  launch on this machine** (firefox-1532 vs Playwright 1.61, every test failed at 0ms) — not verified. Negative control: the same spec
  on the pre-change renders fails (overflow at 360px, ragged legend edge, stripped ≠ normal for the scheduled report).
  Backend targeted run (mail/digest/email/spend/alert/notification/report + NotificationsV2 + MailDesignSystem, 2,102 tests): green
  except two clock-dependent tests unrelated to markup — `AlertEventEmailTest::quiet hours hold the bundle` (passes alone; the run
  crossed midnight) and `NovSpendLimitAlertsTest::the run out date…` (`Carbon::today()`-based, fails on 2026-11-16 regardless of mail).

## Not verifiable here

Gmail app (Google and non-Google accounts, dark mode), Gmail web, Outlook Windows (Word engine, VML), Outlook.com/new Outlook, Apple
Mail / iOS Mail, Samsung Mail. The inversion filter is a stand-in for Gmail's algorithm, not Gmail. Gmail's forced dark mode cannot be
switched off by a sender; the fix makes it invert one consistent, explicit palette.

## Manual checklist for the Owner (test-send «أرسل نسخة لي» to his own address, per template)

1. Send the portfolio digest, a project digest, the alert bundle, the spend summary (internal + external) and the confirmation.
2. Gmail Android, non-Google account, dark mode: blocks share one left/right edge; decision-card titles («تيك توك») on one line;
   buttons one line; ring marks round/square, never ovals; heatmap chips one size; no «[Message clipped]» (except a 30-project digest).
3. Same in light mode, then Gmail web, iOS Mail (dark + light), Outlook desktop (rounded green CTA = VML), Outlook mobile.
4. Report any difference with a screenshot and the template name.

## EM2 — port onto the deployed release (branch `feature/email-client-compat-v2` on `origin/main` @ `a01dc3b01`, #1071)

Builder EM2, 2026-11-16. Not pushed, no PR (the lead opens it).

1. **Port.** The six commits above were cherry-picked in order onto `a01dc3b01` with **no conflicts** (the squash of #1071 changed no
   mail file between `0692fbfa7` and `a01dc3b01`). `InternalMailsUnchangedTest` passes on the merged tree with the hashes from
   `7763efd45` unchanged, so no regeneration was needed.
2. **Clock-dependent tests.** Main's RIYADH-DAY-001 fix already made `NovSpendLimitAlertsTest::the run out date…` read the Riyadh
   «today»; on top of it both tests now freeze time before seeding: the run-out test at 2026-11-16 12:00 Riyadh, `AlertEventEmailTest::quiet
   hours hold the bundle` at Riyadh midday of the current day (its 00:00–23:59 window used to miss a run at 23:59).
3. **`php artisan mail:send-gallery {to} [--keys=*] [--locale=ar,en] [--send]`** — how the Owner puts the gallery in his own external
   mailbox. Same path as the admin «test send»: `MailGallery` fixtures (`compatMessages`: gallery + 7 externals + confirmation; no tenant,
   no product-data read), `TestSendMark` (subject `[اختبار]`/`[TEST]`, the visible «رسالة اختبار، وليست إشعارًا حقيقيًا» strip, no
   unsubscribe), `TransactionalMailer::send()` with `TEST_KIND` → one `mail_deliveries` row per message (`kind=test`, `tenant_id` null,
   `template=gallery.<key>`). Dry run unless `--send` (`--dry-run` wins); exactly one valid address; ≤ 40 messages; address and From masked;
   refusals printed by class (`MailFailure`: e.g. `sender_rejected`), never the provider's text; the command never sets a From — it reports
   `sender: from_is_mailbox | envelope_aligned_by_code | no_smtp_mailbox` (AlignEnvelopeSender). Default set (26 = 13 × ar/en):
   external-finance/-project-manager/-admin-owner/-client/-achievement/-continuity-planning/-month-end, digest-portfolio, digest-project,
   alerts-bundle, spend-summary-weekly, spend-summary-external, external-confirmation. Never scheduled (tested). The existing marker text
   was kept (not changed to «تقرير اختبار»): changing it would change the admin test send too. `MailTestSendTest`'s «only these files can
   send a test» allowlist now names the command, with the reason.
   **Owner run (on the server):** `php artisan mail:send-gallery you@gmail.com` (dry run, sizes) → `… --send`. `sent` = the provider
   accepted it, not delivery. If the run reports `sender_rejected`, set `MAIL_FROM_ADDRESS` to the authenticated mailbox (M-notes).
4. **PWA manifest MIME.** `infrastructure/nginx/frontend.conf` (the file `frontend.production.Dockerfile` copies) gains an exact
   `location = /manifest.webmanifest` with `types { application/manifest+json webmanifest; }`, no-cache and the security headers;
   `ProductionEdgeRoutingTest` pins it. **Not verified:** `nginx -t` (no nginx/docker on this machine) and the live header after deploy
   (`curl -sI https://campaignshub.io/manifest.webmanifest`).

Verification here: Playwright `playwright.mail.config.ts` chromium + webkit 240/240 on a clean preview dir (firefox not run). Backend
targeted runs via `ptest.sh` (no --parallel) listed in the EM2 report. Real Gmail/Outlook/Apple Mail rendering still unverified — that is
what the command is for.
