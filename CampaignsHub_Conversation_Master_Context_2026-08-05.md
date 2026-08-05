# CampaignsHub — السياق التنفيذي الكامل وتعليمات الاستمرار
**نسخة محدثة حتى: 2026-08-05 — التوقيت المحلي +03:00 · مراجعة الحالة عند HEAD `72200dc`**

> **الغرض من هذا الملف:** نقل سياق مشروع CampaignsHub وقرارات هذه المحادثة بالكامل إلى مصادر المشروع في ChatGPT، بحيث تبدأ أي محادثة جديدة من نفس الفهم دون إعادة شرح المشروع أو إسقاط المتطلبات.
>
> **قاعدة الأولوية:** هذا الملف امتداد محدث للملف الأصلي `CampaignsHub_Master_Context_and_Instructions.md`. جميع التعليمات تراكمية وملزمة، وأحدث تصحيح صريح يلغي فقط الجزء المتعارض من التعليمات السابقة. لا يجوز حذف أي متطلب بالاستنتاج.
>
> **قاعدة الحالة المتغيرة:** عند التعارض بين حالة تنفيذ مكتوبة هنا وبين Git أو `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md` أو `docs/RESUME_STATE.md`، تُراجع الأدلة الأربعة معًا. Git والمصفوفة وملف الاستئناف هي المرجع التشغيلي للحالة الحالية، بينما هذا الملف هو المرجع الكامل للهدف والقرارات والمتطلبات.
>
> **الصدق:** الأرقام والـCommits وحالات الاختبارات المذكورة هنا هي آخر ما أعلنه Claude Code في هذه المحادثة، وليست تحققًا مستقلاً من ChatGPT. يجب فحصها من المستودع قبل البناء عليها.

---

# 1. تعريف المشروع والهدف الأساسي

**CampaignsHub** منصة احترافية لإدارة ومتابعة وتحليل الحملات الإعلانية المدفوعة من مكان واحد، وليست مجرد Dashboard أو نموذج تجريبي.

المنصات الإعلانية الأساسية بالترتيب الثابت في النظام كله:

1. Snapchat Ads — سناب شات
2. TikTok Ads — تيك توك
3. Meta Ads — ميتا
4. Google Ads — جوجل أدز
5. X Ads — إكس
6. LinkedIn Ads — لينكدإن

الهدف الأساسي:

```text
إدارة ومتابعة واستعراض وتحليل جميع الحملات الإعلانية المدفوعة من مكان واحد
+
ربط العملاء والمشروعات والحسابات والحملات والمحتويات والمؤشرات
+
إصدار تقارير تفاعلية قابلة للمشاركة مع العملاء دون تسجيل دخول
+
ربط بيانات المتاجر الإلكترونية وتحليلات الفانل
```

العلاقة التشغيلية المستهدفة:

```text
العميل
→ المشروع
→ المنصة
→ الحساب الإعلاني
→ الحملة
→ المجموعة الإعلانية
→ الإعلان
→ المحتوى الإعلاني
→ المؤشرات
→ التنبيه
→ المهمة
→ التقرير
→ عرض السعر
→ الفاتورة
→ المدفوعات
```

المنتج يجب أن يجيب بسرعة عن:

```text
كم تم إنفاقه؟
ما النتائج المحققة؟
أي منصة أفضل؟
أي حملة أفضل؟
أي محتوى أفضل؟
أي حملة تحتاج تدخلًا؟
هل الميزانية تسير بالشكل الصحيح؟
هل البيانات محدثة؟
ما الذي يحتاج انتباهي الآن؟
ما الإجراء التالي؟
```

---

# 2. التقنية والبنية الأساسية

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
- Rate Limiting
- Encryption
- Tenant / Workspace / Project Isolation
- Fail-Closed authorization

## Frontend

- React + Vite + TypeScript
- React Query
- Tailwind
- React Router
- RTL / LTR
- Light / Dark
- Responsive
- IBM Plex Sans Arabic
- Arabic-first مع دعم الإنجليزية الحقيقي، وليس تغيير اتجاه فقط

## البيئات المحلية

```text
Frontend: http://localhost:5173
Backend: http://127.0.0.1:8000
Status: http://localhost:5173/dev/status
```

يجب إبقاء التالي عاملًا أثناء التطوير:

- Frontend / Vite
- Backend API
- PostgreSQL
- Redis
- Queue Worker
- Scheduler

إعداد SPA production fallback ضروري للمسارات العميقة:

```nginx
try_files $uri $uri/ /index.html;
```

---

# 3. مصدر الحقيقة الدائم

الملفات الدائمة داخل المستودع:

```text
CampaignsHub_Master_Context_and_Instructions.md
docs/MASTER_EXECUTION_CONTRACT.md
docs/REQUIREMENTS_TRACEABILITY_MATRIX.md
docs/RESUME_STATE.md
docs/CAMPAIGN_MANAGEMENT_AUDIT.md
docs/MODULES_AND_CLASSIFICATIONS_AUDIT.md
Git History
```

الحالات المعتمدة:

```text
NOT_STARTED
IN_PROGRESS
PARTIAL
IMPLEMENTED_NOT_VERIFIED
VERIFIED
BLOCKED_EXTERNAL_CREDENTIALS
BLOCKED_OPERATIONAL_EVIDENCE
```

يُمنع استخدام `DONE` أو `COMPLETED` دون تنفيذ فعلي واختبار قبول مستهدف ومراجعة حية وCommit نظيف.

---

# 4. دستور التنفيذ الدائم

## قاعدة التعارض

```text
1. أحدث تصحيح صريح يلغي فقط الجزء المتعارض.
2. جميع التعليمات غير المتعارضة تبقى تراكمية وملزمة.
3. لا يُحذف متطلب بالاستنتاج.
4. أي متطلب غير منفذ يبقى مفتوحًا.
5. نجاح الاختبارات لا يثبت اكتمال المنتج.
6. الوثائق والتدقيق ليست تطويرًا.
7. Demo Data ليست Live Integration.
8. وجود زر أو Endpoint أو Adapter لا يعني أن الرحلة مكتملة.
```

## لا يُعتبر إنجازًا

- إنشاء وثيقة أو Audit أو خطة
- Component غير مستخدم
- Endpoint غير مربوط
- Demo Data فقط
- تغيير النصوص فقط
- نجاح Build أو اختبارات قديمة فقط
- Connect button أو Integration card أو Mock Adapter فقط
- Route غير مراجع حيًا
- تغيير حالة المصفوفة دون دليل

## كل وحدة مكتملة تشمل حسب طبيعتها

```text
Database
Migrations
Models
Services
API
Validation
Permissions
Portal/Workspace/Project Scope
Tenant Isolation
Frontend
Search
Filters
Classification
Details
Actions
Related Entities
Loading
Empty
Error
Responsive
RTL/LTR
Light/Dark
Tests
Live Browser Review
Commit
Working Tree Clean
```

## أسلوب العمل

- Orchestrator واحد فقط.
- لا توجد جلسات متعددة تعمل على نفس Working Tree.
- أكمل المهمة الحالية قبل بدء التالية.
- لا تعدّل المصدر أثناء تشغيل Full Gate.
- أي Gate حدثت أثناءه تعديلات يعد ملغيًا ويعاد كاملًا.
- لا ترسل تقارير مرحلية.
- عند امتلاء السياق: أكمل الوحدة، اختبر، Commit، حدّث `RESUME_STATE`، ثم `/compact`.

---

# 5. البوابات المعتمدة

النظام الحالي يعتمد أربع بوابات تشغيلية فقط:

```text
/admin
/app
/agency
/portal
```

## `/admin`

مدير المنصة / Platform Owner:

- إدارة النظام بالكامل
- المستأجرون والمساحات
- التسجيلات والاعتمادات
- الباقات والأسعار
- الاشتراكات والمدفوعات
- الصلاحيات والمنح
- التكاملات والإعدادات
- التدقيق والحالة التشغيلية

مدير المنصة ليس Tenant Membership عاديًا، ولا يُنشأ من تسجيل عام.

## `/app`

المعلن أو المتجر الذي يدير حملاته:

- المشاريع والحسابات الإعلانية
- الحملات والمجموعات والإعلانات
- المحتويات والميزانيات
- التحليلات والتقارير والتنبيهات
- المهام والملفات والفريق والتكاملات

## `/agency`

الوكالة متعددة العملاء:

```text
العميل → المشروع → الحسابات → الحملات → الفريق → التقارير → المالية
```

تشمل العملاء والمشروعات والحملات والفريق والصلاحيات والدعوات والموافقات والتقارير والمهام والملفات والمحادثات وعروض الأسعار والفواتير والمدفوعات وWhite Label.

## `/portal`

بوابة العميل:

- الطلبات وحالة التنفيذ
- عروض الأسعار والموافقات
- الفواتير والدفع
- الملفات والمحادثات والتسليمات
- الإشعارات والتقارير المشتركة
- ملخص نتائج الحملات

يمنع ظهور أدوات إدارة الوكالة أو المعلن داخلها.

---

# 6. المؤثرون وUGC — القرار النهائي الحالي

لا توجد بوابة مؤثرين تشغيلية في النسخة الحالية.

```text
influencers_ugc_enabled=false
```

القواعد:

- لا تُفعّل `/influencers` كبوابة تشغيلية.
- لا تظهر في التسجيل أو الباقات أو الصلاحيات أو Portal Switcher.
- لا تنشئ حسابات Demo تشغيلية لها.
- لا تحذف الكود أو البيانات السابقة.
- المسارات القديمة تتحول بأمان إلى الخدمات أو الطلبات.
- الخدمة تظهر في الصفحة التسويقية فقط:

```text
علاقات المؤثرين
إدارة حملات المؤثرين والمحتوى والتعاونات من مكان واحد.
قريبًا
```

- شارة واضحة `قريبًا`.
- لا زر تسجيل أو طلب أو تفعيل أو مسار تشغيل.
- الحفاظ على توازن الصفحة على Desktop/Mobile وRTL/LTR وLight/Dark.

هذه البطاقة مطلوبة في أحدث مهمة، ولم يثبت تنفيذها بعد آخر تقرير.

---

# 7. المصادقة وتسجيل الدخول

## صفحة واحدة

```text
/login
```

جميع الحسابات تدخل من الصفحة نفسها:

- مدير المنصة
- المعلن
- الوكالة
- عميل بوابة الطلبات

المستخدم لا يختار البوابة. أزيلت خيارات:

```text
إدارة الحملات
وكالة
متابعة الطلبات
```

الـBackend يحدد الوجهة من:

```text
Account
+ Portal
+ Membership
+ Role
+ Permission
+ Account State
+ Subscription State
```

ثم:

```text
/admin
/app
/agency
/portal
/switch
```

المسارات القديمة تعيد التوجيه إلى `/login`:

```text
/admin/login
/app/login
/agency/login
/portal/login
/influencers/login
```

القواعد:

- اختيار URL لا يمنح صلاحية.
- `portal: null` في طلب الدخول الموحد.
- الحساب في البوابة الخطأ يحول للبوابة الصحيحة أو يرفض بوضوح.
- لا Redirect Loop.
- Query string وredirect الآمن يحفظان عند الحاجة.
- لا يحفظ مسار غير مسموح في `returnTo` أو `lastVisitedRoute`.

## منع الصفحات المغلقة بلا مخرج

في حالات:

- PortalMismatch
- Forbidden
- NoWorkspace
- Suspended
- ExpiredSession
- Failed workspace resolution
- Old/dead link

يجب توفير:

- التوجيه للبوابة الصحيحة
- اختيار مساحة أخرى
- العودة للرئيسية
- تسجيل الدخول بحساب آخر
- تسجيل الخروج
- طلب دعوة أو إكمال إعداد الحساب

زر تسجيل الخروج ظاهر داخل البوابات وصفحات الرفض والأخطاء.

بعد الخروج تمسح:

- Sanctum session
- Cookies
- user cache
- workspace/project context
- returnTo
- lastVisitedRoute
- local storage ذات الصلة

ثم `/login`.

## شكل صفحة الدخول

- نفس الجانب التسويقي الحالي.
- صندوق احترافي مودرن ومتوازن.
- بُني حسب آخر تقرير على `AuthShell`.
- عرض معلن: `468px`.
- الحقول بارتفاع موحد معلن: `56px`.
- لا بيانات Demo في الصفحة العامة.
- Google وApple بحالة صادقة عند غياب البيانات.

## طرق الدخول

1. البريد الإلكتروني + كلمة المرور
2. رقم الجوال + رمز تحقق

`POST /auth/method` يقرر طريقة الحساب.

مسار الجوال المعلن:

```text
POST /auth/phone/start
POST /auth/phone/verify
```

رمز التحقق Single-use.

---

# 8. الجوال والتحقق

السعودية هي الافتراضية:

```text
+966
```

الصيغ المقبولة:

```text
05xxxxxxxx
9665xxxxxxxx
+9665xxxxxxxx
```

وتقبل المسافات والشرطات والأرقام العربية والفارسية و`00` بدل `+` ومفاتيح دول أخرى الصحيحة.

الرقم يطبّع Backend إلى E.164 قبل الحفظ والمقارنة ومنع التكرار وOTP والتسجيل والعملاء وجهات الاتصال والطلبات والفواتير والبوابة.

آخر تقرير أعلن:

```text
0553318866 → +966553318866
```

والتحقق الإلزامي بالجوال:

```text
requires_mobile=true
```

---

# 9. الصفحة التسويقية

## النص الأساسي

```text
CampaignsHub
إدارة الحملات الإعلانية
منصة متكاملة لإدارة الحملات الإعلانية

كل حملاتك الإعلانية المدفوعة
في مكان واحد

تابع الأداء والميزانيات والنتائج عبر المنصات، نظّم العملاء والمشاريع، وأنشئ تقارير احترافية من مساحة عمل واحدة.
```

المزايا:

```text
متابعة موحّدة لجميع المنصات
سناب شات وتيك توك وميتا وجوجل وإكس ولينكدإن في شاشة واحدة.

تحليلات تناسب هدف الحملة
مؤشرات كل هدف على حدة، دون خلط الوعي بالزيارات أو المبيعات.

تقارير احترافية قابلة للمشاركة
جهّز تقريرًا واضحًا لعميلك أو لإدارتك في دقائق.

تنبيهات ومتابعة مستمرة
تنبيه عند تغيّر الإنفاق أو الأداء قبل أن تكبر المشكلة.
```

الإجراءات العامة:

```text
تسجيل حساب
تسجيل الدخول
اطلب خدمة
متابعة طلباتي
```

يمنع استخدام مصطلحات هندسية عامة مثل Tenant وWorkspace وSaaS وOperations Console.

---

# 10. التسجيل والباقات والدفع

## التسجيل

- تسجيل متعدد الخطوات.
- خطوة بيانات الحساب تتحقق من جميع الحقول قبل الانتقال.
- شرط قوة كلمة المرور يظهر بجانب الحقل في الخطوة الأولى.
- لا يظهر خطأ كلمة مرور في خطوة الباقات.
- البيانات تبقى عند الرجوع.
- Server errors تعاد إلى الحقل والخطوة الصحيحة.
- اختيار نوع الحساب والخدمات يحدد البوابة والصلاحيات والباقات المناسبة.

لا يوجد تسجيل عام لحساب مدير المنصة، لكن مدير المنصة يدخل من `/login`.

## الباقات

الباقة المجانية ملغاة.

```text
Starter / البداية
99 SAR شهريًا
990 SAR سنويًا
```

تشمل متابعة الحملات والتقارير.

السعر السنوي والإضافات قابلة للإدارة من `/admin`.

## التفعيل

لا وصول تشغيلي كامل قبل دفع موثوق.

```text
Verified Payment Event
→ Account activation
→ Subscription
→ Workspace
→ First Project
→ Membership
→ Role
→ Plan Entitlements
→ Selected Service Entitlements
→ Correct Portal
```

فتح Checkout أو واجهة نجاح غير كافٍ.

عند غياب Moyasar/Stripe:

```text
Awaiting Credentials
```

Sandbox مسموح في Development/Test فقط، وبوسم واضح، وممنوع في Production.

## تحكم مدير المنصة

من `/admin`:

- تعديل السعر الشهري والسنوي
- تعديل ميزات وخدمات كل باقة
- منح وإزالة صلاحيات إضافية
- منح اشتراك أو وصول كامل مجانًا
- إلغاء المنحة
- تعليق الحساب وإعادة تفعيله
- Audit Log للسبب والمنفذ والتاريخ

المنح Additive وFail-Closed ومحصورة ببوابات الحساب وقابلة للإلغاء.

آخر تقرير قبل مهمة التكاملات أعلن اكتمال هذه الوحدة واختبارها.

---

# 11. العزل والصلاحيات

```text
Portal
+ Tenant
+ Workspace Membership
+ Role
+ Permission
+ Entity Scope
```

- Platform Admin ليس Tenant Membership.
- كل API يعزل المستأجر/المساحة/العميل/المشروع.
- Project-only membership يمكن أن يتجاوز اختيار العميل.
- اختيار Client يمسح Project إذا لم يعد صالحًا.
- IDs المحفوظة تعاد مصادقتها Server-side.
- لا fallback إلى `users.tenant_id`.
- لا صلاحيات ضمنية.
- لا Mass Assignment للحقول الإدارية أو حالات الحساب والدفع.
- روابط التقارير العامة لها نطاق مستقل Fail-Closed.

---

# 12. بساطة وتجربة الواجهات

- قوائم بحد أقصى مستويين.
- إجراء رئيسي واحد في كل صفحة.
- أهم المعلومات أولًا.
- الخيارات المتقدمة داخل Drawer / Dialog / Tabs.
- لا أكواد قاعدة بيانات أو مصطلحات تقنية للمستخدم.
- لا تكرار بطاقات ورسوم بلا قيمة.
- البحث والفلاتر الأساسية واضحة.
- الفلاتر الكثيرة خلف `ViewCustomiser`.
- يذكر بالكلمات ما هو مطبق.
- لا تطوى شرائح الأعداد الحية المهمة.
- كل Dashboard يجيب:
  - ماذا يحدث؟
  - ماذا يحتاج انتباهي؟
  - ما الإجراء التالي؟

المراجعة:

- Desktop / Tablet / Mobile
- 343px / 375px / 1440px / 1920px
- Arabic / English
- RTL/LTR
- Light/Dark
- Chromium / Firefox / WebKit

---

# 13. الوحدات التي أُبلغ عن تطويرها

هذه الحالة مبنية على تقارير Claude Code ويجب مطابقتها مع Git والمصفوفة.

## Dashboard / Campaigns / Analytics

أُبلغ عن:

- KPIs حسب هدف الحملة
- Mixed/All بمقاييس محايدة
- أوضاع متعددة للحملات
- Compare endpoint
- Campaign detail tabs
- Saved Views
- Budget/Freshness/Funnel APIs
- Translation وResponsive fixes

## Admin

- Platform console
- committed subscription value
- growth series
- attention list
- tenants search/filter/drawer/actions
- plans/subscriptions/registrations
- permission catalog/integrations/audit
- revenue stream separation

## Agency

- client → project context
- team/client scopes
- client spaces
- White Label
- 15 destinations
- simplification pass
- filters folded
- finance under `/agency/finance`

## Client Portal

- membership-first access
- portal account 401 fix
- request-derived spaces fix
- requests/quotes/invoices/files/conversations/reports
- Portal auth cutover قد يبقى جزئيًا حتى legacy sessions = 0 حسب المصفوفة الحالية

## Requests

- status/priority bilingual labels
- `StageStatusMap`
- dynamic intake fields
- عرض إجابات العميل للمشغل
- charts by status/type/SLA
- journey:
  ```text
  submitted
  → under_review
  → qualified
  → proposal_sent
  → awaiting_client_approval
  → payment_pending
  → paid
  → onboarding
  → in_progress
  → client_review
  → completed
  ```

## Reports

- live share reports
- scope by project/campaign/platform/date/metrics
- public link without session
- filters without reload
- password/expiry/revoke/renew/access history
- Demo badge
- KPIs/charts/ranking/funnel
- fail-closed intersection
- builder in `/app/reports` و`/agency/reports`

يجب التحقق من short URLs والقوالب وPDF/Excel ومصدر البيانات الحقيقي.

## Normalization / Revenue / Audit

- source/raw/normalized metadata
- currency/timezone/attribution provenance
- four revenue streams separated
- `combined_total=null`
- subscription/payment audit
- audit filters and UI

---

# 14. التقارير التفاعلية — هدف أساسي

> **الحالة عند `72200dc`: منفَّذ ومتحقق محليًا** (`d262764`). الرابط `/r/<22 حرفًا>` ≈131 بت، والروابط القديمة بطول 48 ما زالت تعمل. المتطلبات أدناه تبقى المرجع الكامل ولم يُحذف منها شيء.

من `/app` أو `/agency`:

1. العميل
2. المشروع
3. الحملات
4. المنصات
5. الفترة
6. المؤشرات
7. المحتويات
8. الهوية
9. إنشاء رابط مختصر
10. مشاركة الرابط

رابط العميل:

- بلا تسجيل
- Dashboard تفاعلي
- Mobile-first
- KPIs
- line/bar/donut
- funnel
- campaign/platform/creative comparison
- budget status
- last sync
- data freshness
- filters دون reload
- optional password
- expiry/revoke/renew
- access history
- templates
- PDF/Excel

العزل:

```text
Client filter may narrow only.
It must never widen the granted scope.
```

لا تستخدم «لحظي» للدلالة على أن المنصة أرسلت الآن. فرّق بين إعادة الحساب الآن وآخر مزامنة حقيقية.

---

# 15. التكاملات الإعلانية — المهمة الحالية التالية

> **الحالة عند `72200dc`: البنية منفَّذة ومتحققة محليًا** (`e92fb38` + `9848277`) — الحسابات والحملات والمجموعات والإعلانات والمحتويات والمؤشرات والخام والمطبَّع، مع Queue وScheduler وRetry وBackoff وIdempotency وToken Refresh وسجل المزامنة. **التشغيل الفعلي `BLOCKED_EXTERNAL_CREDENTIALS`** حتى إدخال اعتماد حقيقي ونجاح OAuth وجولة API ومزامنة.

آخر تقرير عند commit `4eb36fa` أعلن أن مهمة التكاملات الجديدة **لم تبدأ**.

الترتيب:

1. Snapchat Ads
2. TikTok Ads
3. Meta Ads
4. Google Ads
5. X Ads
6. LinkedIn Ads

لكل مزود:

- OAuth Start/Callback
- State validation
- PKCE عند الدعم
- Token encryption/refresh/revocation
- Scope validation
- Account discovery
- Project binding
- Campaign/Ad group/Ads/Creative discovery
- Metrics sync
- Pagination
- Incremental sync
- Rate limits
- Retry/Backoff
- Idempotency
- Sync history
- Manual sync
- Scheduler/Queue
- Webhooks أو Polling
- Disconnect
- Tenant isolation
- Tests
- Live browser review

حالات الصدق:

```text
غير مربوط
جاهز للربط
بانتظار بيانات الاعتماد
تطوير جزئي
جاهز للاختبار
قيد الاتصال
متصل فعليًا
قيد المزامنة
متزامن فعليًا
صلاحية منتهية
صلاحيات ناقصة
فشل الربط
بيانات قديمة
وضع تجريبي
```

لا تعرض Connected أو Synced أو Live إلا بعد API round trip حقيقي.

ترتيب المنصات ثابت في الصفحة التسويقية والتكاملات والمشروع والحملات والفلاتر والتقارير والرسوم والنماذج وDemo Data.

---

# 16. تكاملات سلة وزد — مهمة حالية

> **الحالة عند `72200dc`: منفَّذ ومتحقق محليًا** (`7156143`). زد لا تنشر نقطة سلات متروكة، وموصّلها **يرفض** بدل إعادة قائمة فارغة، فتُقرأ الجولة `partial` وتقول الواجهة «لا توفّرها المنصة». **التشغيل الفعلي `BLOCKED_EXTERNAL_CREDENTIALS`**.

```text
Salla
Zid
```

لكل متجر:

- OAuth أو الربط الرسمي
- Store account
- Products
- Orders
- Customers
- Abandoned carts
- Revenue
- Discounts
- Cancellations
- Refunds
- Inventory عند الدعم
- Webhooks
- Idempotency
- Last sync
- Data freshness
- Error log
- Retry

الربط التسويقي:

- UTM
- click IDs
- campaign source
- platform attribution
- order source
- events
- currency
- timezone

يمنع التكرار ويُربط المتجر بالمشروع الصحيح.

---

# 17. تحليلات الفانل والمتجر — مهمة حالية

> **الحالة عند `72200dc`: منفَّذ ومتحقق محليًا** (`e8c1518`). كل مرحلة تحمل النظام الذي أنتجها، والمرحلة التي لا يقيسها شيء تقول السبب ولا تعرض صفرًا. CAC ≠ CPA. الطلبات بلا إسناد تُعدّ وتُعرض ولا تُوزَّع على الحملات.

أنشئ داخل Analytics:

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

- Conversion Rate بين المراحل
- Drop-off
- CAC
- CPA
- AOV
- ROAS
- Revenue
- Orders
- Purchases
- Abandoned Cart Rate
- Refunds عند توفرها

المقارنات:

- المنصات
- الحملات
- المحتويات
- المنتجات
- العملاء
- الفترات

الفلاتر:

- العميل
- المشروع
- المتجر
- المنصة
- الحملة
- المنتج
- الفترة

مصدر كل رقم واضح:

```text
Ad platform data
Store data
Analytics data
Normalized metric
```

---

# 18. Unified Data Pipeline

> **الحالة عند `72200dc`: منفَّذ ومتحقق محليًا** (`3846899`). خدمة حداثة واحدة حلّت أربع استعلامات متفرقة وصارت تحسب المتاجر أيضًا؛ التنبيهات تقرأ `MetricsAggregator` بدل حساب ROAS بنفسها؛ وكتلة المتجر في اللوحة تأتي من `StoreFunnelService` نفسها التي تغذّي تبويب التحليلات.

البيانات المتزامنة تغذي:

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

- إدخال الرقم نفسه يدويًا في أكثر من وحدة
- جداول متناقضة
- مصدر منفصل لكل صفحة
- Demo مختلط مع Live
- Attribution غير موضح

حفظ:

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

---

# 19. Canonical Metrics

```text
spend
impressions
reach
frequency
clicks
link_clicks
landing_page_views
engagements
video_views
leads
add_to_cart
checkout_started
orders
purchases
revenue
installs
results
cpm
cpc
ctr
cpa
cpl
cpi
aov
roas
conversion_rate
```

لكل مقياس:

- Provider mapping
- Objective compatibility
- Aggregation rule
- Currency handling
- Timezone handling
- Attribution context
- Data freshness
- Source
- `is_demo`

لا تستخدم ROAS لجميع الأهداف.

---

# 20. بيانات Demo

مطلوبة للمعاينة وموسومة:

```text
Demo
بيانات تجريبية
```

تشمل عملاء ومشروعات ومنصات وحسابات وأهداف وحملات قوية وضعيفة وأخطاء مزامنة ومحتويات وتنبيهات ومهام وتقارير وطلبات وعروض وفواتير ومدفوعات ومتاجر ومنتجات وطلبات متجر وFunnel events.

المعادلات:

```text
CPA = Spend / Results
CPC = Spend / Clicks
CPM = Spend / Impressions × 1000
CTR = Clicks / Impressions × 100
ROAS = Revenue / Spend
AOV = Revenue / Orders
```

لا تستخدم Demo لإثبات Live Integration.

---

# 21. Taxonomy

الخيارات القابلة للإدارة تأتي من Taxonomy Engine:

- إضافة وتعديل وترجمة وترتيب
- تفعيل وتعطيل ودمج
- Parent/Child
- Default
- Tenant Scope
- Usage Count
- Audit

يمنع استخدام Arrays ثابتة للخيارات القابلة للإدارة، مع السماح بمصدر مركزي واحد للثوابت الرسمية مثل ترتيب المنصات.

---

# 22. الجاهزية الإنتاجية

> **الحالة عند `72200dc`: منفَّذ ومتحقق محليًا** (`e175e1d`). نبضات حية للمجدول والعامل، وثلاث نقاط صحة يفصلها القرار الذي تقوده، وHorizon محكوم بـ`is_platform_admin` ويراقب طابور `reports`، و`ops:backup` بمانيفست وتحقق. **تشغيل Horizon والـCron على خادم حقيقي وأول بروفة استرجاع ما زالا خارج نطاق التحقق المحلي.**

- `.env.example`
- secret management
- OAuth callback URLs
- webhook URLs
- queues/scheduler/cron
- Redis/Horizon
- token refresh/expiry/reconnect
- rate limits
- CORS/CSRF/encryption
- health checks/monitoring/logs/alerting
- backups/restore
- migrations/seed safety
- indices/N+1/query performance
- retention policies/failed jobs
- tenant isolation/share-token security
- production SPA fallback
- session domain/cookie security

Clean install مطلوب قبل التسليم.

---

# 23. سياسة التكاملات الخارجية

قد تنتظر بيانات اعتماد:

- Snapchat
- TikTok
- Meta
- Google Ads
- X
- LinkedIn
- Salla
- Zid
- GA4
- Google Drive
- CRM
- Email
- SMS
- WhatsApp
- Moyasar
- Stripe
- Apple OAuth
- Google OAuth

عند الغياب:

```text
1. نفّذ البنية القابلة للتفعيل.
2. أنشئ شاشة إعداد واضحة.
3. اختبر Adapter/state/security.
4. استخدم Demo/Sandbox معلّمًا في غير الإنتاج.
5. صنّف BLOCKED_EXTERNAL_CREDENTIALS.
6. واصل بقية المشروع.
```

## التصنيف الملزم للحالة الراهنة

**لا يُصنَّف أي مزوّد `Connected` أو `Synced` أو `Live`.** جميع التكاملات الخارجية تبقى
`BLOCKED_EXTERNAL_CREDENTIALS` حتى تُدخَل بيانات اعتماد حقيقية **وينجح OAuth وجولة API ومزامنة
فعلية**. البنية والواجهات والاختبارات مكتملة؛ الاعتماد وحده هو الناقص.

الحالة المقروءة من النظام عند `72200dc`:

| المزوّد | النوع | حالة الإعداد | التصنيف |
|---|---|---|---|
| Snapchat | إعلانات | `not_configured` | `BLOCKED_EXTERNAL_CREDENTIALS` |
| TikTok | إعلانات | `not_configured` | `BLOCKED_EXTERNAL_CREDENTIALS` |
| Meta | إعلانات | `not_configured` | `BLOCKED_EXTERNAL_CREDENTIALS` |
| Google Ads | إعلانات | `not_configured` | `BLOCKED_EXTERNAL_CREDENTIALS` |
| X | إعلانات | `not_configured` | `BLOCKED_EXTERNAL_CREDENTIALS` |
| LinkedIn | إعلانات | `awaiting_credentials` | `BLOCKED_EXTERNAL_CREDENTIALS` |
| سلة | متاجر | `not_configured` | `BLOCKED_EXTERNAL_CREDENTIALS` |
| زد | متاجر | `not_configured` | `BLOCKED_EXTERNAL_CREDENTIALS` |

**لماذا LinkedIn وحده `awaiting_credentials`:** حقوله المطلوبة `client_id` و`client_secret`
و`version`، و`version` يأتي بقيمة افتراضية (`2411`) وهي رقم إصدار API لا بيانات اعتماد — فتُقرأ
الحالة «مهيّأ جزئيًا». الحالتان صادقتان وكلتاهما غير قابلة للربط، والفرق تجميلي ولم يُعدَّل بعد
بوابة خضراء.

أي صفوف `provider_connections` أو `external_accounts` في قاعدة التطوير هي من تشغيلات E2E، **لا من
مزامنة حقيقية**، ولا واحد منها قابل للمزامنة لأن المزوّد نفسه غير مهيّأ.

---

# 24. آخر حالة معلنة

آخر تقرير موثق ومعتمد:

```text
Branch: feat/taxonomy-ux
HEAD: 72200dc
Working Tree: CLEAN
Playwright: 773/773
Browsers: Chromium + Firefox + WebKit
Failed: 0
Flaky: 0
Retries: 0
EXIT: 0
Backend: 1246 passed (6207 assertions)
Vitest: 625 passed (90 files)
tsc: clean · oxlint: 0 errors · Pint: clean · composer audit: clean
```

> **هذا القسم يلغي الحالة السابقة التي كانت تتوقف عند `4eb36fa`.** كل ما ورد هناك من «لم يبدأ»
> بخصوص التكاملات الست وسلة وزد والفانل والتقارير والجاهزية الإنتاجية **لم يعد صحيحًا**. بقية
> التعليمات والمتطلبات في هذا الملف تبقى سارية كما هي.

## المنفَّذ والمتحقَّق منه محليًا

البنية الإنتاجية للبنود التالية **منفذة ومتحققة محليًا** — كودًا واختبارات ومعاينة حية:

| البند | الحالة | الدليل |
|---|---|---|
| المزامنة الكاملة للمنصات الست: الحسابات → الحملات → المجموعات → الإعلانات → المحتويات → المؤشرات → الخام والمطبَّع، مع Queue وScheduler وRetry وBackoff وIdempotency وToken Refresh وسجل المزامنة وآخر تحديث | **منفذ ومتحقق محليًا** | `e92fb38` + `9848277` |
| الفصل الملزم: `/admin` مفاتيح النظام وOAuth Apps وWebhooks والأسرار · `/app` و`/agency` ربط المستخدم حساباته فقط | **منفذ ومتحقق محليًا** | PROVCFG-001/002 · CONNECT-001 · تحقق حي: 403 على وحدة المفاتيح، وصفر تسريب في `/app/integrations` |
| سلة ثم زد: OAuth الرسمي، المتاجر، المنتجات، الطلبات، العملاء، السلات المتروكة، الإيرادات، الإلغاءات والاستردادات، Webhooks، وربط UTM وClick IDs بالمشروعات والحملات | **منفذ ومتحقق محليًا** | `7156143` |
| «الفانل والمتجر» داخل التحليلات: الظهور → النقرات → الزيارات → مشاهدة المنتج → الإضافة للسلة → بدء الدفع → الطلبات → المشتريات → الإيرادات، مع Conversion Rate وDrop-off وCAC وCPA وAOV وROAS **ومصدر كل رقم** | **منفذ ومتحقق محليًا** | `e8c1518` |
| Unified Data Pipeline: البيانات المتزامنة تغذي Dashboard والحملات والمجموعات والإعلانات والمحتويات والتحليلات والفانل والتقارير وروابط العملاء والتنبيهات والميزانيات وحالة حداثة البيانات، **دون تكرار أو مصادر متعارضة** | **منفذ ومتحقق محليًا** | `3846899` |
| التقارير التفاعلية: رابط مختصر بلا تسجيل، فلاتر لحظية، مؤشرات ومحتويات ورسوم وقمع ومقارنات، آخر مزامنة، كلمة مرور، انتهاء، إلغاء، تجديد، سجل فتح، قوالب، PDF وExcel وعزل Fail-Closed | **منفذ ومتحقق محليًا** | `d262764` |
| بطاقة «علاقات المؤثرين — قريبًا» في الصفحة التسويقية فقط مع بقاء `influencers_ugc_enabled=false` | **منفذ ومتحقق محليًا** | `d575eeb` (سابق — تم التحقق منه ولم يُعد كتابته) |
| جاهزية الإنتاج: الأسرار، Callbacks، Webhook URLs، Redis، Horizon، Cron، Queues، Monitoring، Health Checks، النسخ الاحتياطي، تجديد الرموز، الأداء، الأمان، Clean Install ومسار الترقية | **منفذ ومتحقق محليًا** | `e175e1d` |

## ما تم التحقق منه حيًا في هذه الجلسة

- **البوابة الشاملة:** 773/773 على ثلاثة متصفحات، `retries: 0`، من شجرة نظيفة بعد إيقاف خادمي التطوير.
- **تثبيت نظيف:** قاعدة فارغة فعليًا → `migrate` → `db:seed PermissionSeeder` ← **121 جدولًا و111 صلاحية**؛ `config:cache` و`route:cache` يُبنيان (لا Closures في الإعدادات).
- **مسار الترقية:** «Nothing to migrate»، 0 معلّق؛ `horizon:terminate` موجود وإلزامي.
- **الرحلة الحية:** `/admin` يعرض Redirect URI وWebhook URL **مشتقَّين** بلا إمكانية قراءة أي سر · مالك المستأجر **403** على وحدة المفاتيح · `/app/integrations` صفر تسريب و«بانتظار بيانات الاعتماد» بلا زر ميت · اللوحة والفانل يعيدان أرقام المتجر **متطابقة حرفيًا** من خدمة واحدة · رابط `/r/<22>` فُتح **بلا أي جلسة**، الفلاتر طُبِّقت، المدى خارج السقف **قُصَّ**، الفتحات سُجِّلت، وبعد الإلغاء **404**.
- أُزيلت كل بيانات التجربة بعدها؛ جداول التجارة عادت إلى صفر صفوف.

## ما تم اكتشافه وإصلاحه ولم يكن مطلوبًا العثور عليه

- **ثغرة أمنية حقيقية:** `guzzlehttp/guzzle` كان يحمل CVE-2026-69246 (خطورة عالية — *مضيف غير قانوني يتجاوز فحوص المضيف*)، في مسار منتج يتصل بثماني واجهات خارجية. رُقِّع، و`composer audit` نظيف الآن.
- **إفشاء عبر المشاريع في الفانل:** كان يأخذ كل متاجر المستأجر، فيعرض مشروعُ عميلٍ اسمَ متجر عميل آخر. الطلبات كانت مقيَّدة بالمشروع طوال الوقت، فالأرقام صحيحة وكل ما يُقال عنها خطأ.
- **نافذة الفانل:** الطلبات طوابع زمنية ونقاط النهاية تمرّر `to` عند منتصف الليل، فاختلفت اللوحة والتحليلات على إيراد اليوم بمقدار كل طلبات اليوم.
- **Fail-Open في رابط العميل:** سقف منصات فارغ تحوّل من «لا شيء» إلى «كل شيء». **أُلغيت جولة بوابة كاملة بسببه** لأن الإصلاح نزل أثناء تشغيلها، ثم أُعيدت من شجرة نظيفة.

## التنبيهات المتبقية — مسجَّلة لا مُتجاهَلة

`npm audit` يُبقي تنبيهين، وكلاهما نفس المشكلة في **وضع RSC** لـReact Router. هذا التطبيق SPA عميل
بحت لا يشغّل ذلك المسار، ولا يوجد إصلاح للأمام (المدى `7.12.0–8.2.0`، وأحدث إصدار `7.18.2`)،
والعلاج الوحيد المعروض هو **تنزيل major** لمكتبة التوجيه عبر كل مسار في المنتج. التقييم وشروط
انتهاء صلاحيته في `docs/PRODUCTION_RUNBOOK.md`: يُعاد الفحص كل إصدار، ويسقط فورًا إذا تبنّى
التطبيق RSC.

---

# 25. الحسابات التجريبية المعروفة

Development فقط، ولا تظهر في الصفحة العامة:

```text
Platform Admin:
admin@demo-campaignshub.local
password
→ /admin

Platform Admin (بديل، تم التحقق منه حيًا في هذه الجلسة):
platform@mediabuying.local
password
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

# 26. بوابة الاختبارات النهائية

- Backend full suite
- Frontend typecheck/lint/tests/build
- Chromium/Firefox/WebKit
- Desktop 1440×900 و1920×1080
- Tablet/Mobile
- Arabic/English
- RTL/LTR
- Light/Dark
- Migration fresh + seed
- Upgrade migration path
- Clean install
- Live browser journeys
- Working tree clean

الهدف:

```text
Failed = 0
Flaky = 0
Retries = 0
Unexplained Skipped = 0
Open non-external requirements = 0
Unverified non-external requirements = 0
Working Tree = CLEAN
```

---

# 27. التقرير النهائي فقط

لا ترسل تقارير مرحلية أو رسائل حالة بلا تطوير فعلي.

التقرير النهائي يحتوي:

1. Requirement IDs وحالاتها.
2. الصفحات المطورة.
3. Backend/Frontend/E2E.
4. Preview URLs.
5. Integration readiness لكل مزود.
6. API round trips الفعلية.
7. Demo.
8. Awaiting Credentials.
9. External blockers.
10. Final commits.
11. Git status.
12. Remaining operational evidence.

إذا امتلأ السياق:

- أكمل الوحدة الحالية.
- Commit.
- حدّث `RESUME_STATE`.
- اطلب `/compact` فقط.
- استأنف من Exact Next Requirement.

---

# 28. ترتيب التنفيذ الحالي الملزم

الترتيب الأصلي، **وقد نُفِّذ بالكامل** عند `72200dc`:

```text
1.  تحقق من HEAD والشجرة .......................... ✔ 72200dc · CLEAN
2.  اقرأ المصفوفة وRESUME_STATE .................... ✔
3.  لا تعد تنفيذ LOGIN-UNIFIED-001 ................. ✔ لم يُمسّ
4.  بطاقة «علاقات المؤثرين — قريبًا» مع الفلاغ off .. ✔ d575eeb (سابق، تم التحقق)
5.  Project Integrations readiness ................ ✔ PROVCFG-001/002 · CONNECT-001
6.  OAuth/Sync للمنصات الست بالترتيب الرسمي ........ ✔ e92fb38 + 9848277
7.  Salla ......................................... ✔ 7156143
8.  Zid ........................................... ✔ 7156143
9.  Store/Funnel Analytics ........................ ✔ e8c1518
10. Unified Data Pipeline لجميع الوحدات ............ ✔ 3846899
11. Shared Reports فوق البيانات المتزامنة .......... ✔ d262764
12. Production Readiness .......................... ✔ e175e1d
13. Live journeys ................................. ✔ تحقق حي كامل
14. Full regression ............................... ✔ 773/773 · 3 متصفحات · retries 0
15. Clean install ................................. ✔ 121 جدولًا · 111 صلاحية
16. Final handover ................................ ✔ التقرير النهائي معتمد
```

لا تقرير نهائي مع بند غير خارجي `PARTIAL` أو `IMPLEMENTED_NOT_VERIFIED`. **لا يوجد بند غير خارجي
في أي من هاتين الحالتين الآن.**

## ما تبقّى — خارجي بحت

```text
1. إدخال بيانات اعتماد OAuth الحقيقية لكل مزوّد من /admin.
2. تنفيذ جولة OAuth حقيقية ثم جولة API ناجحة ثم مزامنة فعلية.
3. عندها فقط يجوز رفع التصنيف عن BLOCKED_EXTERNAL_CREDENTIALS.
4. تشغيل Horizon والـCron على الخادم، وتأكيد /api/v1/admin/operational-status = healthy.
5. أول نسخة احتياطية + بروفة استرجاع موثّقة بالتاريخ.
```

لا يجوز لأي من هذه الخمسة أن يُنفَّذ بادعاء أو ببيانات وهمية.

---

# 29. أمر بدء محادثة ChatGPT جديدة

```text
اعتبر ملف CampaignsHub_Conversation_Master_Context_2026-08-05.md مصدر السياق الكامل للمشروع، واعتبر CampaignsHub_Master_Context_and_Instructions.md مصدر الحقيقة الأساسي المتراكم.

اقرأ أيضًا:
docs/MASTER_EXECUTION_CONTRACT.md
docs/REQUIREMENTS_TRACEABILITY_MATRIX.md
docs/RESUME_STATE.md
Git History

أحدث تصحيح صريح يلغي فقط الجزء المتعارض، ولا يجوز إسقاط بقية التعليمات.

ميّز دائمًا بين:
- منفذ ومتحقق حيًا
- منفذ جزئيًا
- Demo
- Awaiting Credentials
- Blocked Operational Evidence
- غير منفذ

لا تعتبر نجاح الاختبارات أو الوثائق دليلًا منفردًا على اكتمال المنتج.

ابدأ بالحالة الحالية المستنتجة من Git والمصفوفة وRESUME_STATE، ثم ساعد في إعداد برومنت قصير وصارم لـClaude Code يحافظ على جميع المتطلبات.
```

---

# 30. أمر بدء جلسة Claude Code جديدة

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
Preview
Backend
Queue
Scheduler
Redis
/dev/status

لا تعد تنفيذ الوحدات VERIFIED، ولا تبدأ Audit أو خطة جديدة.

استأنف مباشرة من أول Requirement ID غير VERIFIED، مع الأولوية الحالية:
- بطاقة علاقات المؤثرين «قريبًا» دون تفعيل النظام الفرعي
- تكاملات المنصات الست
- Salla وZid
- تحليلات الفانل والمتجر
- التقارير التفاعلية فوق البيانات المتزامنة
- جاهزية الإنتاج

نفّذ وحدة واحدة كاملة:
Database + Backend + Frontend + Permissions + Isolation + Loading/Empty/Error + Live Review + Tests + Commit.

لا ترسل تقارير مرحلية. إذا غابت بيانات اعتماد، أكمل البنية وصنّف Awaiting Credentials وواصل. لا تدّعِ Connected أو Synced أو Live دون API round trip حقيقي.

إذا امتلأ السياق:
أكمل الوحدة الحالية، اختبرها، Commit، حدّث RESUME_STATE، ثم اطلب /compact فقط.

لا ترجع للمستخدم إلا بتقرير نهائي واحد بعد اكتمال جميع المتطلبات غير الخارجية.
```

---

# 31. نقاط لا يجوز نسيانها

- الهدف الأساسي إدارة الحملات والتقارير التفاعلية، وليس إدارة الوكالة فقط.
- السعودية هي السوق الافتراضية.
- الريال السعودي العملة العربية الافتراضية.
- رقم الجوال السعودي مرن ويخزن E.164.
- المنصات الست لها ترتيب ثابت.
- المؤثرون وUGC غير مفعّل؛ بطاقة «قريبًا» فقط.
- أربع بوابات تشغيلية فقط.
- `/login` صفحة واحدة.
- لا Demo credentials على الصفحة العامة.
- لا Free plan.
- Starter = 99 SAR شهريًا وفق آخر قرار.
- لا تفعيل قبل دفع موثوق.
- مدير المنصة يدير الباقات والمنح والصلاحيات.
- لا صفحة رفض وصول بلا مخرج.
- لا قوائم عميقة.
- لا رسوم للزينة.
- لا بيانات حية مزعومة دون اعتماد ومزامنة.
- لا تقرير نهائي مع Partial غير خارجي.
- لا تعديل Source أثناء Full Gate.
- لا أكثر من جلسة تكتب في Working Tree نفسه.

---

# 32. خاتمة تشغيلية

هذا الملف لا يلغي Git أو المصفوفة أو RESUME_STATE، بل يمنع فقدان الهدف والقرارات والتصحيحات عند فتح محادثة جديدة.

```text
تحقق من الواقع
→ أكمل أول متطلب مفتوح
→ راجع حيًا
→ اختبر
→ Commit نظيف
→ حدّث المصفوفة وRESUME_STATE
→ انتقل تلقائيًا
→ لا تدّعِ ما لم يحدث
```
