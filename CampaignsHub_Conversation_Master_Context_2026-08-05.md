# CampaignsHub — الملف الموحد للسياق والقرارات والحالة التنفيذية
**النسخة المعتمدة:** 2026-08-05  
**الغرض:** استخدام الملف نفسه داخل مستودع المشروع، ومصادر مشروع ChatGPT، وأي جلسة Claude Code جديدة.

> هذا الملف هو المرجع الموحد للسياق الكامل والقرارات والتصحيحات والحالة التنفيذية المعلنة للمشروع.
>
> يبقى الملف `CampaignsHub_Master_Context_and_Instructions.md` مرجع الحقيقة الأساسي للهدف والمتطلبات الأصلية. هذا الملف يكمله ويحدّث الحالة التنفيذية.
>
> عند التعارض في حالة التنفيذ، تكون الأولوية التشغيلية:
>
> `Git → docs/REQUIREMENTS_TRACEABILITY_MATRIX.md → docs/RESUME_STATE.md → هذا الملف`
>
> أحدث تصحيح صريح يلغي فقط الجزء المتعارض من التعليمات السابقة، وتبقى بقية المتطلبات تراكمية وملزمة.

---

# 1. الحالة الحالية المعتمدة

```text
Project: CampaignsHub
Branch: feat/taxonomy-ux

Current HEAD: df962e1
Code verified at: 72200dc
Documentation-only commit: df962e1
Working Tree: CLEAN

Backend: 1246 passed
Vitest: 625 passed — 90 files
Playwright: 773/773
Browsers: Chromium + Firefox + WebKit
Failed: 0
Flaky: 0
Retries: 0
Full Gate Exit: 0
Full Gate Duration: 28.7m
```

## تفسير الـCommits

- `72200dc`: آخر Commit وظيفي تم عليه Full Gate والتثبيت النظيف ومسار الترقية والرحلة الحية.
- `df962e1`: Commit وثائق فقط فوق `72200dc`.
- لا يجوز الادعاء بأن الاختبارات جرت على `df962e1`؛ الاختبارات جرت على الكود عند `72200dc`.
- لا حاجة لإعادة الاختبارات بسبب `df962e1` لأنه لم يغيّر أي ملف وظيفي.

---

# 2. الهدف الأساسي للنظام

CampaignsHub منصة SaaS لإدارة ومتابعة وتحليل جميع الحملات الإعلانية المدفوعة من مكان واحد، وربط المتاجر، وإنشاء تقارير تفاعلية قابلة للمشاركة مع العملاء.

الهدف الأساسي الملزم:

```text
إدارة ومتابعة وتحليل الحملات الإعلانية المدفوعة
+
ربط العملاء والمشروعات والحسابات الإعلانية
+
مزامنة الحملات والمجموعات والإعلانات والمحتويات والمؤشرات
+
ربط سلة وزد وبيانات التجارة الإلكترونية
+
تحليل الفانل والمتجر
+
إنشاء روابط تقارير تفاعلية للعملاء دون تسجيل دخول
```

المنصات بالترتيب الثابت في كامل النظام:

1. Snapchat Ads — سناب شات
2. TikTok Ads — تيك توك
3. Meta Ads — ميتا
4. Google Ads — جوجل أدز
5. X Ads — إكس
6. LinkedIn Ads — لينكدإن

---

# 3. البنية التقنية

## Backend

- Laravel 12 API Only
- Clean Architecture / DDD
- Sanctum
- PostgreSQL
- Redis
- Queues
- Scheduler
- Horizon
- API Resources
- ULID/UUID حسب النماذج
- Rate Limiting
- Encryption
- Tenant / Workspace / Project Isolation
- Fail-Closed authorization

## Frontend

- React
- Vite
- TypeScript
- React Query
- React Router
- Tailwind
- RTL / LTR
- Light / Dark
- Responsive
- Arabic-first
- English support
- IBM Plex Sans Arabic

## البيئة المحلية

```text
Frontend: http://localhost:5173
Backend: http://127.0.0.1:8000
Status: http://localhost:5173/dev/status
```

---

# 4. مصادر الحقيقة الدائمة

يجب قراءة المصادر التالية بالترتيب:

```text
CampaignsHub_Master_Context_and_Instructions.md
CampaignsHub_Conversation_Master_Context_2026-08-05.md
docs/MASTER_EXECUTION_CONTRACT.md
docs/REQUIREMENTS_TRACEABILITY_MATRIX.md
docs/RESUME_STATE.md
Git History
```

الحالات المعتمدة فقط:

```text
NOT_STARTED
IN_PROGRESS
PARTIAL
IMPLEMENTED_NOT_VERIFIED
VERIFIED
BLOCKED_EXTERNAL_CREDENTIALS
BLOCKED_OPERATIONAL_EVIDENCE
```

لا تعتبر الوثائق أو التدقيق أو نجاح الاختبارات وحدها دليلًا على اكتمال التطوير.

---

# 5. البوابات التشغيلية

يوجد أربع بوابات تشغيلية فقط:

```text
/admin
/app
/agency
/portal
```

## `/admin`

مدير المنصة:

- إعداد مزودي API وOAuth وWebhooks
- إدارة مفاتيح النظام والأسرار
- إدارة المستأجرين والمساحات
- إدارة التسجيلات
- إدارة الباقات والأسعار
- إدارة الاشتراكات والمدفوعات
- إدارة المنح والصلاحيات
- إدارة التكاملات
- إدارة الإعدادات
- Audit Log
- الحالة التشغيلية

مدير المنصة ليس Tenant Membership عاديًا.

## `/app`

المعلن أو المتجر:

- المشاريع
- الحسابات الإعلانية
- الحملات
- المجموعات
- الإعلانات
- المحتويات
- الميزانيات
- التحليلات
- الفانل والمتجر
- التقارير
- الروابط التفاعلية
- التنبيهات
- المهام
- الملفات
- الفريق
- التكاملات الخاصة بحساباته

## `/agency`

الوكالة متعددة العملاء:

```text
العميل → المشروع → الحسابات → الحملات → الفريق → التقارير → المالية
```

تشمل العملاء والمشاريع والحملات والحسابات الإعلانية والفريق والصلاحيات والموافقات والتقارير وروابط العملاء والمهام والملفات والمحادثات وعروض الأسعار والفواتير والمدفوعات وWhite Label.

## `/portal`

بوابة العميل:

- متابعة الطلبات
- عروض الأسعار
- الموافقات
- الفواتير
- الدفع
- الملفات
- المحادثات
- التسليمات
- الإشعارات
- التقارير المشتركة
- ملخص نتائج الحملات

---

# 6. تسجيل الدخول والمصادقة

## صفحة دخول واحدة

```text
/login
```

جميع الحسابات تدخل من الصفحة نفسها:

- مدير المنصة
- المعلن
- الوكالة
- عميل بوابة الطلبات

لا يختار المستخدم نوع البوابة.

الـBackend يحدد الوجهة تلقائيًا من:

```text
Account
+ Portal
+ Membership
+ Role
+ Permission
+ Account State
+ Subscription State
```

الوجهات:

```text
/admin
/app
/agency
/portal
/switch
```

المسارات القديمة تحول إلى `/login`:

```text
/admin/login
/app/login
/agency/login
/portal/login
/influencers/login
```

## طرق الدخول

1. البريد الإلكتروني + كلمة المرور
2. رقم الجوال + رمز تحقق

المسارات المعلنة:

```text
POST /auth/method
POST /auth/phone/start
POST /auth/phone/verify
```

رمز التحقق Single-use.

## قواعد الوصول

- URL لا يمنح صلاحية.
- لا Redirect Loop.
- لا صفحة رفض بلا مخرج.
- زر تسجيل الخروج متاح.
- لا حفظ لمسار غير مسموح.
- بعد الخروج تمسح الجلسة والكوكيز وسياق المساحة والمشروع والمسارات المحفوظة.

---

# 7. أرقام الجوال

السعودية هي الدولة الافتراضية:

```text
+966
```

الصيغ المقبولة:

```text
05xxxxxxxx
9665xxxxxxxx
+9665xxxxxxxx
```

وتقبل المسافات والشرطات والأرقام العربية والفارسية و`00` بدل `+` ومفاتيح دول أخرى صحيحة.

كل الأرقام تطبع في Backend إلى E.164 قبل الحفظ والمقارنة ومنع التكرار وOTP والتسجيل والعملاء وجهات الاتصال والطلبات والفواتير والبوابة.

مثال تحقق معلن:

```text
0553318866 → +966553318866
```

---

# 8. التسجيل والباقات والدفع

## التسجيل

- متعدد الخطوات.
- التحقق من قوة كلمة المرور في الخطوة الأولى.
- يمنع الانتقال قبل صحة الحقول.
- الأخطاء تظهر بجانب الحقل الصحيح.
- البيانات تبقى عند الرجوع.
- نوع الحساب والخدمات يحددان البوابة والصلاحيات.

لا يوجد تسجيل عام لحساب مدير المنصة.

## الباقة الأساسية

```text
Starter / البداية
99 SAR شهريًا
990 SAR سنويًا
```

تشمل متابعة الحملات والتقارير.

لا توجد باقة مجانية.

## التفعيل

لا تفعيل قبل دفع موثوق.

```text
Verified Payment Event
→ Account activation
→ Subscription
→ Workspace
→ First Project
→ Membership
→ Role
→ Plan Entitlements
→ Service Entitlements
→ Correct Portal
```

فتح Checkout أو واجهة نجاح لا يكفي.

## تحكم مدير المنصة

من `/admin`:

- تعديل السعر الشهري والسنوي
- تعديل ميزات الباقات
- منح وإزالة صلاحيات إضافية
- منح اشتراك مجاني
- منح وصول كامل مجانًا
- إلغاء المنحة
- تعليق الحساب
- إعادة التفعيل
- Audit Log للسبب والمنفذ والتاريخ

المنح Additive وFail-Closed.

---

# 9. المؤثرون وUGC

النظام الفرعي غير مفعّل:

```text
FEATURE_INFLUENCERS_UGC=false
influencers_ugc_enabled=false
```

لا توجد بوابة مؤثرين تشغيلية حاليًا.

تظهر في الصفحة التسويقية فقط:

```text
علاقات المؤثرين
إدارة حملات المؤثرين والمحتوى والتعاونات من مكان واحد.
قريبًا
```

القواعد:

- بطاقة تسويقية جامدة فقط.
- لا تسجيل.
- لا طلب.
- لا تفعيل.
- لا صلاحيات.
- لا Portal Switcher.
- لا حذف للكود أو البيانات القديمة.

الحالة المعلنة: منفذة ومتحققة عند `d575eeb`.

---

# 10. الفصل الجوهري للتكاملات

## طبقة مدير المنصة `/admin`

مخصصة لإعداد مزود التكامل على مستوى النظام:

- API
- OAuth App
- MCP عند توفره رسميًا
- Client ID
- Client Secret
- App ID
- Developer Token
- Redirect URIs
- Webhook URLs
- Webhook Secrets
- Scopes
- Sandbox / Production
- اختبار الإعداد
- تدوير الأسرار
- تعطيل المزود
- Audit

الأسرار:

- مشفرة.
- لا تعاد للواجهة بعد الحفظ.
- لا تظهر للمستخدم.
- لا تسجل في Logs.

## طبقة المستخدم `/app` و`/agency`

مخصصة لربط المستخدم حساباته الخاصة:

```text
System Provider Configuration
→ User OAuth Consent
→ External Account
→ Client
→ Project
```

المستخدم يستطيع بدء OAuth والموافقة الرسمية واكتشاف الحسابات واختيارها وربطها بالعميل والمشروع واختيار الحملات وفصل الحساب وإعادة المصادقة ورؤية آخر مزامنة والأخطاء والصلاحيات الناقصة.

المستخدم لا يستطيع رؤية أو إدخال Client Secret أو Webhook Secret.

---

# 11. التكاملات الإعلانية

البنية الإنتاجية منفذة للمزودات الست:

1. Snapchat
2. TikTok
3. Meta
4. Google Ads
5. X
6. LinkedIn

تشمل البنية:

- OAuth start/callback
- state validation
- PKCE عند الدعم
- token encryption
- token refresh
- token revocation
- scope validation
- account discovery
- project binding
- campaigns
- ad sets
- ads
- creatives
- metrics sync
- raw payloads
- normalized data
- pagination
- incremental sync
- retries
- backoff
- idempotency
- scheduler
- queues
- sync history
- manual sync
- webhooks
- signature verification
- disconnect
- tenant isolation

الـCommits الرئيسية:

```text
e92fb38
9848277
```

---

# 12. سلة وزد

البنية الإنتاجية منفذة لكل من:

```text
Salla
Zid
```

تشمل:

- OAuth
- المتاجر
- المنتجات
- الطلبات
- العملاء
- الإيرادات
- الخصومات
- الإلغاءات
- الاستردادات
- السلات المتروكة
- Webhooks
- Idempotency
- Last sync
- Data freshness
- Error log
- Retry
- UTM
- Click IDs
- Attribution
- Currency
- Timezone

Commit رئيسي:

```text
7156143
```

---

# 13. الفانل والمتجر

تصنيف داخل Analytics باسم:

```text
الفانل والمتجر
```

المراحل:

```text
الظهور
→ النقرات
→ الزيارات
→ مشاهدة المنتج
→ الإضافة للسلة
→ بدء الدفع
→ الطلبات
→ المشتريات
→ الإيرادات
```

المؤشرات:

- Conversion Rate
- Drop-off
- CAC
- CPA
- AOV
- ROAS
- Revenue
- Orders
- Purchases
- Abandoned Cart Rate
- Refunds

المصادر واضحة:

```text
Ad platform data
Store data
Analytics data
Normalized metric
```

Commit رئيسي:

```text
e8c1518
```

---

# 14. Unified Data Pipeline

مصدر بيانات موحد يغذي:

```text
Dashboard
Campaigns
Campaign Details
Ad Sets
Ads
Creatives
Analytics
Funnel
Store Analytics
Reports
Shared Reports
Alerts
Budget Tracking
Data Freshness
Sync Status
```

يمنع:

- إدخال الرقم نفسه في أكثر من مكان.
- مصادر متضاربة.
- خلط Demo وLive.
- خلط attribution.
- الاستعلام خارج المشروع.

يحفظ:

- raw payload
- normalized rows
- provider
- account
- project
- campaign
- date
- currency
- timezone
- attribution window
- is_demo
- synced_at
- source_updated_at

Commit رئيسي:

```text
3846899
```

---

# 15. التقارير التفاعلية وروابط العملاء

النظام يدعم:

- اختيار العميل
- المشروع
- الحملات
- المنصات
- الفترة
- المؤشرات
- المحتويات
- الهوية
- إنشاء رابط مختصر
- فتح الرابط بلا تسجيل
- فلاتر دون Reload
- KPI cards
- line/bar/donut
- funnel
- مقارنة الحملات والمنصات والمحتويات
- budget status
- last sync
- data freshness
- كلمة مرور
- انتهاء
- إلغاء
- تجديد
- access history
- PDF
- Excel
- Fail-Closed ceiling

المسار المختصر:

```text
/r/<token>
```

قاعدة العزل:

```text
Client filters may narrow only.
They must never widen the granted scope.
```

Commits رئيسية:

```text
d262764
672b11b
```

---

# 16. جاهزية الإنتاج

منفذ ومتحقق محليًا:

- Horizon
- Queue workers
- Scheduler
- Cron
- Health checks
- Operational readiness endpoint
- Scheduler heartbeat
- Worker heartbeat
- Backup command
- Backup verification
- Corruption detection
- Runbook
- OAuth callback URL derivation
- Webhook URL derivation
- Secret masking
- Production Horizon gate
- SPA fallback
- Session domain
- Clean install
- Upgrade path
- config:cache
- route:cache
- horizon:terminate

Commit رئيسي:

```text
e175e1d
```

---

# 17. التثبيت النظيف ومسار الترقية

## Clean Install

```text
Empty database
→ migrate
→ PermissionSeeder
→ 121 tables
→ 111 permissions
```

تحقق أيضًا:

```text
config:cache
route:cache
```

## Upgrade

```text
migrate
→ Nothing to migrate
→ 0 pending
```

`horizon:terminate` إلزامي بعد النشر.

---

# 18. حالة التكاملات الخارجية الحالية

## الحقيقة التشغيلية

**لا يوجد أي مزود Connected أو Synced أو Live.**

لم تدخل بيانات اعتماد فعلية، ولم تنفذ OAuth حقيقية أو جولة API أو مزامنة حقيقية.

| المزود | النوع | حالة النظام | التصنيف النهائي |
|---|---|---|---|
| Snapchat | إعلانات | not_configured | BLOCKED_EXTERNAL_CREDENTIALS |
| TikTok | إعلانات | not_configured | BLOCKED_EXTERNAL_CREDENTIALS |
| Meta | إعلانات | not_configured | BLOCKED_EXTERNAL_CREDENTIALS |
| Google Ads | إعلانات | not_configured | BLOCKED_EXTERNAL_CREDENTIALS |
| X | إعلانات | not_configured | BLOCKED_EXTERNAL_CREDENTIALS |
| LinkedIn | إعلانات | awaiting_credentials | BLOCKED_EXTERNAL_CREDENTIALS |
| Salla | متاجر | not_configured | BLOCKED_EXTERNAL_CREDENTIALS |
| Zid | متاجر | not_configured | BLOCKED_EXTERNAL_CREDENTIALS |

LinkedIn مختلف لأن `version=2411` قيمة API افتراضية وليست اعتمادًا.

أي صفوف اتصال موجودة في قاعدة التطوير هي Demo/E2E وليست اتصالًا حيًا.

---

# 19. نتائج التحقق النهائي

```text
Backend: 1246 passed
Vitest: 625 passed
Playwright: 773/773
Chromium: PASS
Firefox: PASS
WebKit: PASS
Failed: 0
Flaky: 0
Retries: 0
Exit: 0
Duration: 28.7m
```

التثبيت النظيف ومسار الترقية والرحلة الحية تحققت.

---

# 20. الأمان والإصلاحات المهمة

أُصلحت:

- Fail-Open في سقف منصات رابط التقرير العام.
- تسريب بيانات المتاجر بين المشاريع في الفانل.
- اختلاف نافذة التاريخ بسبب منتصف الليل.
- تناقض حداثة المتجر عند حذف سجل التشغيل.
- خطأ `OnboardingGate` fail-open.
- Webhook signature verification.
- Webhook idempotency.
- Secret leakage prevention.
- CVE-2026-69246 في `guzzlehttp/guzzle`.

تبقى تنبيهات npm مرتبطة بوضع React Router RSC، بينما التطبيق SPA ولا يستخدم RSC. التقييم موثق في `PRODUCTION_RUNBOOK.md`.

---

# 21. قواعد الصدق

يمنع وصف أي مزود بأنه:

```text
Connected
Synced
Live
```

إلا بعد:

1. إدخال الاعتماد الحقيقي.
2. نجاح OAuth.
3. اكتشاف حساب خارجي حقيقي.
4. نجاح API round trip.
5. نجاح أول مزامنة.
6. ظهور البيانات الحية.
7. اختبار Token Refresh.
8. اختبار Webhooks.
9. اختبار Reconnect.

حتى ذلك الوقت:

```text
BLOCKED_EXTERNAL_CREDENTIALS
Awaiting Credentials
Ready for Configuration
```

---

# 22. ما تبقى

المتبقي خارجي وتشغيلي فقط:

1. إنشاء واعتماد تطبيقات OAuth لدى كل مزود.
2. إدخال Client IDs وSecrets وDeveloper Tokens.
3. ضبط Redirect URIs وWebhook URLs في بوابات المزودين.
4. تنفيذ OAuth حقيقي لحساب اختباري.
5. تنفيذ API round trip حقيقي.
6. تنفيذ أول مزامنة حية.
7. التحقق من البيانات في Dashboard والفانل والتقرير.
8. اختبار Refresh وReconnect وWebhooks.
9. تغيير الحالة إلى Connected/Synced فقط بعد الأدلة.

لا توجد متطلبات برمجية غير خارجية معلنة مفتوحة حسب التقرير النهائي الحالي.

---

# 23. الحسابات التجريبية

Development فقط، ولا تظهر على الصفحة العامة:

```text
Platform Admin:
admin@demo-campaignshub.local
password
→ /admin

Alternative Platform Admin:
platform@mediabuying.local
→ /admin

Advertiser:
owner@demo-company.local
password
→ /app

Agency:
owner@demo-agency.local
password
→ /agency

Client Portal:
client@demo-portal.local
OTP
→ /portal
```

تحقق من Seeders قبل الاستخدام.

---

# 24. قواعد التنفيذ الدائمة

- لا تعِد تنفيذ أي وحدة VERIFIED إلا عند اكتشاف خلل فعلي.
- لا تعتبر Demo اتصالًا حيًا.
- لا تعتبر Adapter أو Button أو Endpoint دليل اكتمال.
- لا تعدّل المصدر أثناء Full Gate.
- لا أكثر من جلسة تكتب في Working Tree نفسه.
- لا تقرير نهائي مع بند غير خارجي PARTIAL.
- لا بيانات حية مزعومة دون اعتماد وجولة API.
- لا صفحة رفض وصول بلا مخرج.
- لا قوائم أعمق من مستويين.
- لا رسوم للزينة.
- كل Dashboard يجيب:
  - ماذا يحدث؟
  - ماذا يحتاج انتباهي؟
  - ما الخطوة التالية؟

---

# 25. تعليمات مشروع ChatGPT الجاهزة

استخدم النص التالي داخل تعليمات المشروع:

```text
اعتبر الملف التالي مصدر الحقيقة الأساسي والملزم للمشروع:

CampaignsHub_Master_Context_and_Instructions.md

واعتبر الملف التالي مصدر السياق الكامل والمحدث للمحادثات والقرارات والتصحيحات وحالة التطوير:

CampaignsHub_Conversation_Master_Context_2026-08-05.md

عند بدء أي محادثة جديدة داخل هذا المشروع:

1. اقرأ الملفين واعتمد تعليماتهما تراكمياً قبل الرد.
2. أحدث تصحيح صريح يلغي فقط الجزء المتعارض من التعليمات السابقة، وتبقى بقية التعليمات ملزمة.
3. لا تعتمد على الذاكرة العامة أو التخمين عند وجود إجابة في مصادر المشروع.
4. عند اختلاف حالة التنفيذ، اعتمد Git وREQUIREMENTS_TRACEABILITY_MATRIX.md وRESUME_STATE.md لتحديد الحالة الحالية.
5. ميّز بوضوح بين:
   - منفذ ومتحقق حياً.
   - منفذ جزئياً.
   - IMPLEMENTED_NOT_VERIFIED.
   - Demo.
   - Awaiting Credentials.
   - BLOCKED_EXTERNAL_CREDENTIALS.
   - BLOCKED_OPERATIONAL_EVIDENCE.
   - غير منفذ.
6. لا تعتبر الوثائق أو التدقيق أو نجاح الاختبارات وحدها دليلاً على اكتمال التطوير.
7. حافظ على هدف النظام الأساسي: إدارة ومتابعة وتحليل الحملات المدفوعة، وربط المتاجر، وإصدار تقارير تفاعلية للعملاء.
8. لا تعِد تنفيذ الوحدات VERIFIED إلا عند اكتشاف خلل فعلي.
9. عند إعداد برومنت لـClaude Code أو مراجعة رده، تحقق من جميع المتطلبات السابقة وليس آخر طلب فقط.
10. اكتب البرومنت قصيراً وصارماً وقابلاً للتنفيذ.
```

> عند رفع الملف إلى مصادر ChatGPT قد يضيف النظام رقمًا مثل `(1)` أو `(2)`. عدّل الاسم في تعليمات المشروع ليطابق الاسم الظاهر حرفيًا.

---

# 26. أمر بدء Claude Code جديد

```text
اقرأ بالكامل وبالترتيب:

CampaignsHub_Master_Context_and_Instructions.md
CampaignsHub_Conversation_Master_Context_2026-08-05.md
docs/MASTER_EXECUTION_CONTRACT.md
docs/REQUIREMENTS_TRACEABILITY_MATRIX.md
docs/RESUME_STATE.md

ثم تحقق من:
Git branch
HEAD
Working Tree

الحالة المتوقعة:
Current HEAD: df962e1 أو أحدث
Code verified at: 72200dc
Working Tree: CLEAN

لا تعد تنفيذ أي وحدة VERIFIED.
لا تشغّل الاختبارات دون سبب.
لا تعدّل الكود لمجرد أن التكاملات غير مرتبطة؛ حالتها BLOCKED_EXTERNAL_CREDENTIALS.

المرحلة التالية تشغيلية فقط:
إدخال بيانات الاعتماد، اعتماد OAuth Apps، ثم تنفيذ أول OAuth وAPI round trip ومزامنة حقيقية لكل مزود.

لا تدّعِ Connected أو Synced أو Live دون دليل حقيقي.
```

---

# 27. قاعدة الاستمرار

```text
اقرأ Git والمصفوفة وRESUME_STATE
→ تحقق من الواقع
→ لا تعد تنفيذ VERIFIED
→ أكمل فقط المتطلبات المفتوحة الحقيقية
→ راجع حيًا
→ اختبر عند الحاجة
→ Commit نظيف
→ حدّث الوثائق
→ لا تدّعِ ما لم يحدث
```
