# Builder Q2 — external messages in the approved templates' external mode (NOTIF-V2-EXTERNAL-001)

Branch `feature/notifications-v2-q2` (on builder Q's branch). Not pushed, no PR. Status `IMPLEMENTED_NOT_VERIFIED` — targeted tests green
(NotificationsV2 + the mail guard suites, 266 tests); full suite, CI, real mail clients and production not run here. Matrix and ledger not edited.

## DECISIONS (newest instruction wins; earlier ones are superseded, in this order)

1. **2026-11-15 — «حافظ على كل قوالب الإشعارات والتنبيهات المعتادة … شيل التوجيه إلى المنصة، أي ملخص فقط» (the governing one).**
   No new look. Every approved template (digests, spend summary, alerts…) stays byte-for-byte as it was; the ONLY difference outside the platform
   is an **external rendering mode**: the same template for the role/type with every route back removed.
   - Finance (periodic) and budget events (`continuity.planning`, `budget.renewed`, `digest.month_start`) → the weekly **spend summary** (`SpendSummaryMail`,
     `external: true`; its numbers are `ProjectSpendSummary`: aggregator + SpendLimitGovernor + FxEngine, i.e. the Budgets screen's own read).
   - Every other role/type (project manager, admin/owner, client, achievement, month-end, target/record/launch) → the **project summary**
     (`OperationalDigestMail`, scope `project`, `external: true`; figures from `DailyDigest::buildRange` for ONE project id, read by the workspace's first active member).
   - The mode is carried by the templates, not by a parallel design: `standalone` shell (no link on the header, no preferences link, no policy-link row, no home link),
     `mail.digest.project` guards (`@unless($external)`) on the decision list («يحتاج قرارك الآن» + its buttons), the connection/linking block, lead follow-up, the content
     link and the CTA button; `ExternalMode::digestModel()` empties issues/linking/follow-up/links and maps «مشكلة»/«تجاوزت…» and the red tone to the calm amber
     «يحتاج متابعة» (spec §7); `SpendSummaryMail` maps «تجاوزت الحد» to «يحتاج متابعة» and draws the card in amber when external.
   - Added beside the template, only when set (so internal output is unchanged): `scopeNote` (the layout's existing header-tag line) = «[المساحة] · مشروع: [المشروع]»,
     the one-line privacy notice (≤ 8 words), the short financial-reference disclaimer as the footer note, the click-counted footprint link on the © line, `hideFootprint`
     (`branding.remove_platform_footprint`), the shell's own test strip («تقرير اختبار») on `TEST-…` sends and «نسخة للاطلاع» on CC/BCC copies.
   - Footer = the usual one: © line with the footprint link (UTM through `/n/f/…`), the «أُرسلت هذه الرسالة من كامبينز هب…» line, **one** signed one-click
     «إلغاء الاشتراك» link (`/n/u/…`, also the `List-Unsubscribe` header), the financial-reference notice, the reference number as a tiny id. No «لماذا وصلتك»,
     no long disclaimer, no «تم إنشاء هذا التقرير…», no «إعدادات الإشعارات».
   - **Default strings (supersede the long texts of spec §8/§12/§13; still editable per workspace, empty falls back, never removable):**
     | key | AR | EN |
     |---|---|---|
     | `disclaimer_default` | المرجع المالي: فواتير المنصات والمحاسبة المالية. | Financial reference: platform invoices and accounting records. |
     | `privacy_short` | مخصصة لك بصفتك :role — بيانات :project فقط | For you as :role — :project data only |
     | `scope_note` | :workspace · :term: :project | :workspace · :term: :project |
     | `test_banner` / `copy_band` | تقرير اختبار / نسخة للاطلاع | Test report / Copy for your information |
     | `title_updating` | ملخص :term — قيد التحديث | :term summary — updating |
2. **Superseded, kept out of the branch:** the visual redesign tried earlier today (new header bar, KPI language, chart images in the mail, dark palette, icon PNGs, new
   shared blocks, new footer drafts). Reverted in full; its diff is not committed. The resvg chart pipeline (`ChartSvg`, `ResvgCharts`) stays as code and is tested, but no
   message embeds images any more: the approved templates draw with table cells.
3. The «ABBREVIATIONS» table (K/M, ر.س, $) of the redesign is not needed: the approved templates keep their own presenter (`DigestPresenter`) formats.

## What did NOT change (guarantee, tested)

`InternalMailsUnchangedTest` — md5 of every deterministic gallery message × ar/en against `fixtures/mail-gallery-golden.json` (88 messages; the daily-digest family embeds
dates and cannot be hashed). Passing means the layout/footer/`project`/`spend-summary` edits for the external mode add zero bytes to any internal render. The golden
file must be regenerated knowingly when a template is deliberately redesigned.

## Tests

`tests/Feature/NotificationsV2/External/`: `ExternalPreviewsTest::test_an_external_message_has_no_route_back_to_the_platform` (7 templates × ar/en: exactly one `href`
(the unsubscribe), no `class="ch-btn`, no `/app/`, `/agency/`, `/login`, none of «افتح…»/«يحتاج قرارك»/«إعدادات الإشعارات», the financial-reference notice, the workspace identity,
the privacy line, no emoji, no alarm word); `ExternalMessageTest` (identity, privacy line, footer, two links only — unsubscribe + footprint —, no image/attachment on the project
summary, English text part); `ExternalChecksAndReviewTest` (forbidden word and emoji now meet the rendered text); `ExternalPeriodsAndScreenTest` (spend equals the aggregator's total).
The pre-send checks (privacy hold, review mode, rate/number/screen/wording) are unchanged and still run on Q's `DigestDataSource` facts.

## Previews (overwritten, same directory)

`http://localhost:8765/<template>.<ar|en>.<light|dark>.html` for finance, project_manager, admin_owner, client, achievement, continuity_planning, month_end, plus
`inbox.ar.html` / `inbox.en.html`. They are the approved templates in external mode on FIXTURE figures (`MailGallery::externalDemo`); light and dark are the same file (the approved
templates have no dark variant beyond the header band, which is dark by design). Checked at 360 and 600 px in chromium and webkit: no horizontal overflow, RTL correct;
firefox attempted (see the final report).

## Deferred, and why

- Internal notification/alert **centre strings, push payloads and card components** (short titles, per-kind icons, tiny visuals) were NOT changed: the owner's final instruction
  is to keep every usual notification/alert exactly as approved.
- The shared **header across the client live-link / print-PDF** surfaces (React) and the «one header block» test: moot after the decision above (the approved banner header is
  untouched and shared by every mail already); not built.
- **Display currency per recipient** (spec §12) is not applied by the approved spend summary/project summary (each shows its project's / limit's currency through
  `DigestPresenter`); `ProjectSpendSummary` already withholds an unconverted amount. Q's `FxEngine` path still drives the pre-send currency check. A per-recipient currency
  would need the approved presenters to take one — a change to approved templates.
- The existing external spend-summary closing sentence («وصلتك هذه الرسالة لمتابعة ميزانية … وتجديدها مع العميل») is part of the approved design and was left as is; it reads as
  an invitation to act (spec §7) — the owner should confirm it stays.
- Real-client proof (Gmail, Outlook, Apple Mail) needs a real mailbox; nothing was sent.
