# Arabic wording changes — WORDING-001 (2026-11-13)

Source: the style guide in `docs/CampaignsHub_MASTER_FINAL_COMPLETE_2026-11-11.md` (Appendix A task 4). Applied to the
Arabic interface strings in `frontend/src`. The guard `frontend/src/lib/noForbiddenTerms.test.ts` keeps the retired words out.

| Before | After | Where |
|---|---|---|
| المصروف | الإنفاق | campaign, analytics, reports, budget, agency dashboard, marketing copy |
| المستهلك (a percentage of the budget) | الإنفاق | consumption bar |
| الاستهلاك (a percentage of the budget) | نسبة الإنفاق | budget tables, spend limits, findings, client attention |
| الاستهلاك / المستهلك (subscription usage) | الاستخدام / المستخدم | subscriptions, usage card, admin usage |
| متبقّي | المتبقي | subscriptions |
| العتبة | الحد | findings |
| الإيقاع | سرعة الصرف | spend limits, budget explanation |
| الحدود المضبوطة | حدود الإنفاق | spend limits |
| تقترب من الحد / تجاوزت الحد | قرّبت تخلص / تجاوزت الميزانية | spend limits |
| غير معروفة (a status) | بدون بيانات | campaign status, reports, spend limits |
| العملاء المحتملون الأصليون | المهتمين | lead results |
| إطلاق الحملات | حملة جديدة | rail, launch page, campaign form |
| سلامة التتبع | حالة التتبع | forms health panel |
| يحتاج انتباه / ما يحتاج انتباهك | تنبيهات | dashboards, reports, requests |
| يحتاج انتباهًا (a state) | تنبيه | notifications, provider settings |
| أين الضعف | مراحل الرحلة | diagnostic panel |
| ما الذي تغيّر — ومن حرّكه | أسباب التغيّر | change diagnosis |
| الأيام التي خرجت عن سلوك الفترة | أيام غير معتادة | change diagnosis |
| يغذّي هذه الصفحة | المنصات المربوطة | analytics context strip |
| الملخص (rail) | ملخص المشاريع | rail |
| الروابط (rail) | روابط التتبع | rail |
| المجموعات (rail, tab) | المجموعات الإعلانية | rail, analytics tab, ad-group workspace |
| التسليم / التسليم الدوري | الجدولة / الجدولة الدورية | agency rail, client delivery tab |
| الاستبدالات | تبديل الحسابات | usage card |
| فترة التهدئة | المدة بين التنبيهات | automation rules |
| محفظة العملاء | العملاء | clients subtitle, agency dashboard chart |

Not changed here (other batches own the files; listed in the PR): the alert rules page and taxonomy
(`فترة التهدئة`, `انقطاع القياس`, `سلامة التتبع`, `إنفاق بلا نتائج`, `مرحلة إطلاق أفضل متاحة`), registrations, payments, the
digest and notification mails, `SubscriptionNotificationTemplates` (`الاستهلاك`), and the English side.
