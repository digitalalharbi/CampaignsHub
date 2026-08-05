import { CONTACT_EMAIL, type LegalDoc } from './legalTypes'

/**
 * LEGAL-001 — the operational policies: retention, subprocessors, deletion, data-subject requests,
 * acceptable use, subscriptions and refunds, the OAuth disclosure, and the status page.
 *
 * ## Why these are written from the code rather than from a template
 *
 * Every statement here is checkable against something in this repository. The retention windows are
 * the ones the scheduler actually enforces (`integrations:prune-raw`, `requests:prune-uploads`). The
 * subprocessor list is the set of hosts this application actually calls. The deletion policy
 * describes the review journey the API actually implements, including the cases where it refuses.
 * The OAuth disclosure names the scopes the connectors actually request and what each one is for.
 *
 * A policy that claims a certification we do not hold, an encryption standard we do not implement or
 * a retention period nothing enforces is worse than no policy: it is a written commitment the product
 * will fail the first time anyone checks. Where something is not done, these pages say it is not done.
 *
 * ## What is deliberately absent
 *
 * No ISO/SOC/PCI claim, no "bank-grade" anything, no named data-centre certifications, and no
 * sub-processor we do not actually use. The operator's own legal identity is NOT written here either
 * — it comes from `/api/v1/legal`, and where it is unset the page says so instead of inventing a
 * company.
 */

const AR_DISCLAIMER =
  'تصف هذه الصفحة الممارسة الفعلية للنظام ولا تُعد استشارة قانونية. راجعها مع مستشارك القانوني قبل الاعتماد عليها تجاريًا.'
const EN_DISCLAIMER =
  'This page describes how the system actually behaves and is not legal advice. Have your counsel review it before relying on it commercially.'

const AR_UPDATED = 'آخر تحديث: 7 أغسطس 2026'
const EN_UPDATED = 'Last updated: 7 August 2026'

export const AR_OPERATIONAL: LegalDoc[] = [
  {
    slug: 'retention',
    title: 'سياسة الاحتفاظ بالبيانات',
    updated: AR_UPDATED,
    disclaimer: AR_DISCLAIMER,
    intro: 'كم تبقى كل فئة من البيانات، ومتى تُحذف، ولماذا تبقى بعض السجلات بعد إغلاق الحساب.',
    sections: [
      {
        heading: 'المُدد المطبَّقة فعليًا',
        bullets: [
          'الاستجابات الخام من المنصات الإعلانية والمتاجر: تُقلَّم يوميًا عبر مهمة مجدولة، وتبقى للنافذة اللازمة لتسوية أي خلاف على رقم.',
          'ملفات الطلبات غير المكتملة: تُحذف كل ساعة مع جلسات الرفع المنتهية، لأن ملفًا رُفع ولم يُرسَل ليس بيانات أحد.',
          'المؤشرات اليومية المطبَّعة: تبقى ما بقي المشروع، لأنها أساس كل تقرير تاريخي.',
          'سجلات التدقيق: تبقى بعد حذف ما تصفه، لأن سجلًا يُحذف مع الحدث الذي يوثّقه ليس سجلًا.',
          'روابط التقارير المشتركة: تتوقف عند الانتهاء أو الإلغاء فورًا، ويبقى سجل الفتح.',
        ],
      },
      {
        heading: 'بعد إغلاق الحساب',
        body: [
          'تُحذف بيانات مساحة العمل بعد اكتمال مراجعة الحذف. ما يبقى هو الحد الأدنى الذي تفرضه المحاسبة والأنظمة: الفواتير وسجلات السداد وسجل التدقيق المرتبط بها.',
          'لا يُحتفظ بحسابات إعلانية مرتبطة ولا برموز وصول: تُبطَل عند الإغلاق.',
        ],
      },
      {
        heading: 'ما لا نحتفظ به أصلًا',
        bullets: [
          'أرقام بطاقات الدفع — تُحفظ لدى مزوّد الدفع وحده، ويحتفظ النظام بمرجع فقط.',
          'كلمات المرور بصيغتها الأصلية.',
          'رموز روابط التقارير بصيغتها الأصلية — يُخزَّن تجزئتها فقط.',
        ],
      },
      { heading: 'طلب الحذف مبكرًا', body: [`يمكنك طلب الحذف في أي وقت من صفحة طلبات البيانات، أو بمراسلة ${CONTACT_EMAIL}.`] },
    ],
  },
  {
    slug: 'subprocessors',
    title: 'مزودو المعالجة والخدمات الخارجية',
    updated: AR_UPDATED,
    disclaimer: AR_DISCLAIMER,
    intro: 'الجهات الخارجية التي قد تصل إليها بياناتك، وما الذي يُرسل إلى كل منها.',
    sections: [
      {
        heading: 'منصات الإعلانات والمتاجر',
        body: [
          'لا يُرسل النظام بياناتك إلى هذه المنصات: هو يقرأ منها. عند ربط حسابك يُطلب من المنصة تقارير حسابك الإعلاني أو متجرك، ويُخزَّن ما يصل.',
        ],
        bullets: [
          'Meta · Google Ads · TikTok · Snapchat · X · LinkedIn — قراءة الحملات والمجموعات والإعلانات والمحتويات والمؤشرات.',
          'سلة · زد — قراءة المنتجات والطلبات والعملاء والسلات المتروكة.',
        ],
      },
      {
        heading: 'البنية التحتية',
        bullets: [
          'خادم التطبيق وقاعدة البيانات: يستضيفهما مشغّل المنصة، وتُحدَّد الجهة في صفحة الأمان.',
          'مزوّد الدفع: يُعالج عملية الدفع ويحتفظ بوسيلة الدفع. لا تمر بيانات البطاقة عبر هذا النظام.',
        ],
      },
      {
        heading: 'ما ليس مفعّلًا اليوم',
        body: [
          'قنوات البريد والرسائل النصية وواتساب غير مربوطة بمزوّد فعلي في هذه النسخة، وتُسجَّل كل محاولة إرسال بحالة «بانتظار بيانات اعتماد المزوّد» بدل الادعاء بأنها أُرسلت.',
          'لا يوجد مزوّد تحليلات خارجي ولا أدوات تتبع إعلانية على الصفحات العامة.',
        ],
      },
      { heading: 'التغييرات', body: ['تُحدَّث هذه القائمة قبل إضافة أي مزوّد جديد يعالج بيانات العملاء.'] },
    ],
  },
  {
    slug: 'account-deletion',
    title: 'حذف الحساب والبيانات',
    updated: AR_UPDATED,
    disclaimer: AR_DISCLAIMER,
    intro: 'كيف تطلب حذف حسابك أو بيانات مساحة عملك، وماذا يحدث بعد الطلب، ومتى يُرفض الحذف الفوري.',
    sections: [
      {
        heading: 'كيف تطلب',
        body: [
          'من صفحة طلبات البيانات داخل الحساب، أو بمراسلة البريد المعلن على صفحة الخصوصية من العنوان المسجَّل نفسه.',
          'يُسجَّل الطلب ويُراجعه مشغّل المنصة، وتُدوَّن كل خطوة في سجل التدقيق.',
        ],
      },
      {
        heading: 'ما يُحذف',
        bullets: [
          'بيانات مساحة العمل: العملاء والمشروعات والحملات والمحتويات والتقارير والملفات.',
          'ربط الحسابات الإعلانية والمتاجر، ورموز الوصول المرتبطة بها — تُبطَل ولا تُنقل.',
          'الحسابات والعضويات والأدوار داخل مساحة العمل.',
        ],
      },
      {
        heading: 'ما لا يُحذف فورًا، ولماذا',
        body: [
          'الحساب الذي عليه التزامات مالية أو فواتير أو بيانات يفرض النظام حفظها لا يُحذف بضغطة واحدة. يُحوَّل الطلب إلى مراجعة تُبيّن سبب المنع بوضوح، وما الذي يجب تسويته أولًا.',
          'هذا ليس تعطيلًا للحق في الحذف؛ هو الفارق بين حذف البيانات التشغيلية وإتلاف سجل محاسبي مطلوب قانونًا.',
        ],
        bullets: [
          'فواتير أو مدفوعات قائمة.',
          'اشتراك فعّال لم يُلغَ.',
          'بيانات مشمولة بطلب قانوني أو نزاع قائم.',
        ],
      },
      {
        heading: 'التعليق ليس حذفًا',
        body: ['تعليق الحساب لا يحذف أي بيانات. الحساب المعلَّق يفقد الوصول ويحتفظ بمحتواه حتى يُسوّى سبب التعليق أو يُطلب الحذف صراحةً.'],
      },
      { heading: 'المدة', body: ['نردّ على الطلب خلال يوم عمل، ويكتمل التنفيذ بعد اكتمال المراجعة وتسوية أي التزام قائم.'] },
    ],
  },
  {
    slug: 'data-requests',
    title: 'طلب تصدير البيانات أو تصحيحها أو حذفها',
    updated: AR_UPDATED,
    disclaimer: AR_DISCLAIMER,
    intro: 'حقوقك على بياناتك داخل CampaignsHub، وكيف تمارسها عمليًا.',
    sections: [
      {
        heading: 'ما يمكنك طلبه',
        bullets: [
          'نسخة من بياناتك: تصدير ما هو محفوظ عنك وعن مساحة عملك.',
          'تصحيح بيانات غير دقيقة.',
          'حذف بيانات محددة أو الحساب كاملًا.',
          'إيقاف معالجة معيّنة، حيثما كان ذلك ممكنًا دون تعطيل الخدمة نفسها.',
        ],
      },
      {
        heading: 'كيف نتحقق من هويتك',
        body: [
          'يُقبل الطلب من البريد أو الجوال المسجَّل على الحساب. لا نُدمج حسابين ولا نمنح وصولًا لمجرد تطابق بريد إلكتروني دون تحقق آمن.',
        ],
      },
      {
        heading: 'ما يمكنك فعله بنفسك فورًا',
        bullets: [
          'تصدير أي تقرير بصيغة PDF أو Excel أو CSV من صفحة التقارير.',
          'إلغاء أي رابط تقرير مشترك فورًا من الصفحة نفسها.',
          'فصل أي حساب إعلاني أو متجر مرتبط من صفحة التكاملات.',
          'تعديل بيانات حسابك من إعدادات الحساب.',
        ],
      },
      { heading: 'التقديم', body: [`من صفحة طلبات البيانات داخل الحساب، أو بمراسلة ${CONTACT_EMAIL}. يُسجَّل كل طلب ويُدوَّن في سجل التدقيق.`] },
    ],
  },
  {
    slug: 'acceptable-use',
    title: 'سياسة الاستخدام المقبول',
    updated: AR_UPDATED,
    disclaimer: AR_DISCLAIMER,
    intro: 'ما هو مسموح وما هو ممنوع عند استخدام CampaignsHub.',
    sections: [
      {
        heading: 'ممنوع',
        bullets: [
          'ربط حساب إعلاني أو متجر لا تملك صلاحية إدارته.',
          'استخدام النظام للوصول إلى بيانات مساحة عمل أخرى أو محاولة تجاوز العزل بين الحسابات.',
          'مشاركة رابط تقرير يحتوي بيانات عميل مع من لا يحق له الاطلاع عليها.',
          'محاولة استخراج بيانات المنصات بما يخالف شروط تلك المنصات نفسها.',
          'الاستخدام الآلي المكثّف الذي يضر بالخدمة لبقية المستخدمين، أو تجاوز حدود المعدل عمدًا.',
          'رفع محتوى غير قانوني أو ضار عبر نماذج الطلبات والملفات.',
        ],
      },
      {
        heading: 'مسؤوليتك عن الحسابات المرتبطة',
        body: [
          'الربط يتم بموافقتك عبر رحلة OAuth رسمية لدى المنصة. أنت مسؤول عن أن تكون مخوّلًا بالوصول إلى الحساب الذي تربطه، وعن التزام استخدامك بشروط المنصة المعنية.',
        ],
      },
      {
        heading: 'ما نفعله عند المخالفة',
        body: [
          'قد يُعلَّق الوصول إلى الميزة المخالفة أو الحساب. التعليق لا يحذف بيانات، ويُبلَّغ صاحب الحساب بالسبب.',
        ],
      },
      { heading: 'الإبلاغ عن إساءة استخدام', body: [`راسلنا على ${CONTACT_EMAIL} مع وصف واضح وما يثبت الواقعة.`] },
    ],
  },
  {
    slug: 'subscriptions-refunds',
    title: 'سياسة الاشتراكات والإلغاء والاسترداد',
    updated: AR_UPDATED,
    disclaimer: AR_DISCLAIMER,
    intro: 'كيف تعمل الاشتراكات والتجديد والإلغاء والاسترداد داخل CampaignsHub.',
    sections: [
      {
        heading: 'الاشتراك والتجديد',
        bullets: [
          'يبدأ الاشتراك عند تأكيد الدفع، لا عند إنشاء الحساب.',
          'التجديد دوري حسب الباقة، ويُعالَج عبر مزوّد الدفع باستخدام وسيلة الدفع المحفوظة لديه.',
          'تُمنع محاولات الخصم المكرّرة عن الفترة نفسها عبر مفاتيح تفرَّد على مستوى العملية.',
        ],
      },
      {
        heading: 'الإلغاء',
        body: [
          'يمكنك الإلغاء في أي وقت. يبقى الوصول حتى نهاية الفترة المدفوعة، ولا يُجدَّد بعدها.',
          'الإلغاء لا يحذف بياناتك. إن أردت الحذف فهو طلب منفصل عبر صفحة حذف الحساب والبيانات.',
        ],
      },
      {
        heading: 'عدم السداد',
        body: [
          'الفترة غير المسددة تنتقل إلى حالة «متأخر السداد» ثم إلى مهلة سماح، وبعدها يُعلَّق الحساب. التعليق يوقف الوصول ولا يحذف أي بيانات.',
        ],
      },
      {
        heading: 'الاسترداد',
        body: [
          'يُدرَس طلب الاسترداد حالةً بحالة خلال المدة التي يحددها المشغّل في شروط الباقة، ويُنفَّذ عبر مزوّد الدفع نفسه ووسيلة الدفع نفسها.',
          'المبالغ المقابلة لفترة استُخدمت فيها الخدمة فعليًا لا تُسترد تلقائيًا.',
        ],
      },
      { heading: 'الفواتير', body: ['كل عملية سداد لها فاتورة يمكن تنزيلها من صفحة الفوترة داخل الحساب.'] },
    ],
  },
  {
    slug: 'oauth-disclosure',
    title: 'الإفصاح عن استخدام OAuth وبيانات المنصات',
    updated: AR_UPDATED,
    disclaimer: AR_DISCLAIMER,
    intro: 'ما الذي يطلبه CampaignsHub من كل منصة عند الربط، ولماذا، وكيف يُستخدم ويُخزَّن ويُحذف.',
    sections: [
      {
        heading: 'كيف يتم الربط',
        body: [
          'عبر رحلة OAuth الرسمية للمنصة نفسها. لا يُطلب منك اسم مستخدم أو كلمة مرور لأي منصة، ولا يستطيع النظام الوصول إلى أي حساب لم تُصرّح به بنفسك.',
          'كل رحلة ربط تحمل حالة (state) صالحة لمرة واحدة، وتُرفض أي استجابة لا تطابقها.',
        ],
      },
      {
        heading: 'ما نطلبه ولماذا',
        bullets: [
          'قراءة الحملات والمجموعات والإعلانات والمحتويات: لعرض بنية حسابك كما هي لدى المنصة.',
          'قراءة تقارير الأداء: لعرض الإنفاق والظهور والنقرات والنتائج في اللوحة والتقارير.',
          'قراءة بيانات المتجر (سلة وزد): المنتجات والطلبات والعملاء والسلات المتروكة، لبناء فانل المتجر وربط الطلبات بالحملات.',
          'صلاحية العمل دون اتصال (offline_access): لتحديث الأرقام دوريًا دون مطالبتك بإعادة الربط كل مرة.',
        ],
      },
      {
        heading: 'مبدأ أقل صلاحية',
        body: [
          'لا يُطلب سوى نطاق القراءة اللازم لما يعرضه المنتج فعلًا. لا يطلب النظام صلاحية إنشاء حملات أو تعديلها أو إيقافها، ولا صلاحية النشر نيابةً عنك.',
        ],
      },
      {
        heading: 'ما لا نفعله ببيانات المنصات',
        bullets: [
          'لا تُباع ولا تُشارك مع طرف ثالث.',
          'لا تُستخدم لتدريب نماذج.',
          'لا تُستخدم لمصلحة عميل آخر — العزل بين مساحات العمل مفروض على مستوى قاعدة البيانات.',
          'لا تُستخدم لأي غرض إعلاني خاص بنا.',
        ],
      },
      {
        heading: 'التخزين والحذف',
        bullets: [
          'رموز الوصول والتحديث مُعمّاة في قاعدة البيانات، ولا تُعرض في أي واجهة.',
          'يمكنك فصل أي حساب في أي وقت من صفحة التكاملات؛ يُبطَل الرمز فورًا ويتوقف الجلب.',
          'عند حذف الحساب تُبطَل كل الرموز وتُحذف بيانات المنصات المرتبطة بمساحة العمل.',
        ],
      },
      { heading: 'حذف البيانات بطلب من المنصة', body: [`عند ورود طلب حذف من منصة أو من مستخدم عبرها، يُنفَّذ الحذف ويُدوَّن في سجل التدقيق. للتواصل: ${CONTACT_EMAIL}.`] },
    ],
  },
  {
    slug: 'system-status',
    title: 'حالة النظام والدعم الأمني',
    updated: AR_UPDATED,
    intro: 'كيف تعرف أن الخدمة تعمل، وكيف تُبلّغ عن ثغرة أمنية.',
    sections: [
      {
        heading: 'حالة الخدمة',
        body: [
          'يراقب مشغّل المنصة صحة الخدمة عبر فحوص آلية تشمل قابلية تقديم الطلبات، وحياة المجدول، وحياة عامل المهام الذي تعتمد عليه التقارير والمزامنة.',
          'عند وجود عطل يؤثر على المزامنة أو التقارير، تظهر حالة البيانات داخل المنتج نفسه: كل مصدر يذكر آخر تحديث فعلي له، والمصدر الذي توقّف يُعرض كذلك بدل إظهار أرقام قديمة على أنها محدّثة.',
        ],
      },
      {
        heading: 'الإبلاغ عن ثغرة',
        body: [
          'إن وجدت ثغرة أمنية، راسلنا قبل الإفصاح العلني ومعك وصف وخطوات إعادة الإنتاج والأثر المحتمل.',
          `عنوان الإبلاغ معلن على صفحة الأمان، وإن لم يُحدَّد المشغّل عنوانًا خاصًا فالمراسلة على ${CONTACT_EMAIL}.`,
        ],
      },
      {
        heading: 'ما نلتزم به',
        bullets: [
          'الإقرار باستلام البلاغ خلال يوم عمل.',
          'إبلاغك بنتيجة التقييم وخطة المعالجة.',
          'عدم اتخاذ أي إجراء ضدك عند بلاغ حسن النية لا يضر بالمستخدمين ولا ببياناتهم.',
        ],
      },
    ],
  },
]

export const EN_OPERATIONAL: LegalDoc[] = [
  {
    slug: 'retention',
    title: 'Data retention policy',
    updated: EN_UPDATED,
    disclaimer: EN_DISCLAIMER,
    intro: 'How long each kind of data is kept, when it is deleted, and why some records outlive an account.',
    sections: [
      {
        heading: 'The periods actually enforced',
        bullets: [
          'Raw responses from ad platforms and stores: pruned daily by a scheduled task, kept long enough to settle a dispute about a figure.',
          'Files on unfinished requests: deleted hourly with expired upload sessions — a file uploaded and never submitted is nobody’s data.',
          'Normalised daily metrics: kept for the life of the project, because every historical report rests on them.',
          'Audit records: kept after the thing they describe is deleted, because a log deleted alongside its event is not a log.',
          'Shared report links: stop answering the moment they expire or are revoked; the access history remains.',
        ],
      },
      {
        heading: 'After an account closes',
        body: [
          'Workspace data is deleted once the deletion review completes. What remains is the minimum accounting and regulation require: invoices, payment records and the audit entries attached to them.',
          'No connected ad accounts or stores are retained, and no access tokens: they are revoked at closure.',
        ],
      },
      {
        heading: 'What is never stored',
        bullets: [
          'Card numbers — held only by the payment provider; this system keeps a reference.',
          'Passwords in readable form.',
          'Report-link tokens in readable form — only their hash is stored.',
        ],
      },
      { heading: 'Asking for earlier deletion', body: [`Request it any time from the data-requests page, or write to ${CONTACT_EMAIL}.`] },
    ],
  },
  {
    slug: 'subprocessors',
    title: 'Subprocessors and external services',
    updated: EN_UPDATED,
    disclaimer: EN_DISCLAIMER,
    intro: 'The third parties your data may reach, and what is sent to each.',
    sections: [
      {
        heading: 'Ad platforms and stores',
        body: ['This system does not send your data to these platforms — it reads from them. When you connect an account, it asks the platform for that account’s reporting and stores what arrives.'],
        bullets: [
          'Meta · Google Ads · TikTok · Snapchat · X · LinkedIn — reading campaigns, ad sets, ads, creatives and metrics.',
          'Salla · Zid — reading products, orders, customers and abandoned carts.',
        ],
      },
      {
        heading: 'Infrastructure',
        bullets: [
          'Application server and database: hosted by the platform operator; the provider is named on the security page.',
          'Payment provider: processes payments and holds the payment method. Card details never pass through this system.',
        ],
      },
      {
        heading: 'What is not enabled today',
        body: [
          'Email, SMS and WhatsApp channels are not bound to a real provider in this release. Every send is recorded as «awaiting provider credentials» rather than claimed as sent.',
          'There is no third-party analytics provider and no advertising tracker on the public pages.',
        ],
      },
      { heading: 'Changes', body: ['This list is updated before any new subprocessor handling customer data is introduced.'] },
    ],
  },
  {
    slug: 'account-deletion',
    title: 'Account and data deletion',
    updated: EN_UPDATED,
    disclaimer: EN_DISCLAIMER,
    intro: 'How to ask for your account or workspace data to be deleted, what happens next, and when immediate deletion is refused.',
    sections: [
      {
        heading: 'How to ask',
        body: [
          'From the data-requests page inside your account, or by writing from your registered address to the email published on the privacy page.',
          'The request is recorded, reviewed by the platform operator, and every step is written to the audit log.',
        ],
      },
      {
        heading: 'What is deleted',
        bullets: [
          'Workspace data: clients, projects, campaigns, creatives, reports and files.',
          'Connections to ad accounts and stores, and their access tokens — revoked, never transferred.',
          'Accounts, memberships and roles inside the workspace.',
        ],
      },
      {
        heading: 'What is not deleted immediately, and why',
        body: [
          'An account carrying financial obligations, invoices or records regulation requires us to keep is not deleted with one click. The request moves into a review that states plainly what is blocking it and what has to be settled first.',
          'This is not a refusal of the right to deletion. It is the difference between deleting operational data and destroying an accounting record the law requires.',
        ],
        bullets: ['Outstanding invoices or payments.', 'An active subscription that has not been cancelled.', 'Data under a legal request or live dispute.'],
      },
      {
        heading: 'Suspension is not deletion',
        body: ['Suspending an account deletes nothing. A suspended account loses access and keeps its content until the cause is settled or deletion is explicitly requested.'],
      },
      { heading: 'Timing', body: ['We respond within one business day; completion follows the review and the settlement of any outstanding obligation.'] },
    ],
  },
  {
    slug: 'data-requests',
    title: 'Export, correct or delete your data',
    updated: EN_UPDATED,
    disclaimer: EN_DISCLAIMER,
    intro: 'Your rights over your data in CampaignsHub, and how to exercise them in practice.',
    sections: [
      {
        heading: 'What you can ask for',
        bullets: [
          'A copy of your data: an export of what is held about you and your workspace.',
          'Correction of inaccurate data.',
          'Deletion of specific data, or of the whole account.',
          'To stop a particular processing, where that is possible without disabling the service itself.',
        ],
      },
      {
        heading: 'How we verify it is you',
        body: ['Requests are accepted from the email or mobile registered on the account. We never merge two accounts or grant access on a matching email address alone without a secure check.'],
      },
      {
        heading: 'What you can do yourself, right now',
        bullets: [
          'Export any report as PDF, Excel or CSV from the reports page.',
          'Revoke any shared report link immediately from the same page.',
          'Disconnect any linked ad account or store from the integrations page.',
          'Edit your own details in account settings.',
        ],
      },
      { heading: 'Submitting', body: [`From the data-requests page inside your account, or by writing to ${CONTACT_EMAIL}. Every request is recorded and written to the audit log.`] },
    ],
  },
  {
    slug: 'acceptable-use',
    title: 'Acceptable use policy',
    updated: EN_UPDATED,
    disclaimer: EN_DISCLAIMER,
    intro: 'What is permitted and what is not when using CampaignsHub.',
    sections: [
      {
        heading: 'Not permitted',
        bullets: [
          'Connecting an ad account or store you are not authorised to manage.',
          'Using the system to reach another workspace’s data, or attempting to bypass the isolation between accounts.',
          'Sharing a report link containing a client’s data with someone not entitled to see it.',
          'Extracting platform data in ways those platforms’ own terms prohibit.',
          'Heavy automated use that degrades the service for others, or deliberately exceeding rate limits.',
          'Uploading unlawful or harmful content through request forms and file uploads.',
        ],
      },
      {
        heading: 'Your responsibility for connected accounts',
        body: ['Connections are made with your consent through each platform’s official OAuth flow. You are responsible for being authorised to access the account you connect, and for your use complying with that platform’s terms.'],
      },
      { heading: 'What we do about a breach', body: ['Access to the offending feature or the account may be suspended. Suspension deletes no data, and the account holder is told the reason.'] },
      { heading: 'Reporting abuse', body: [`Write to ${CONTACT_EMAIL} with a clear description and evidence.`] },
    ],
  },
  {
    slug: 'subscriptions-refunds',
    title: 'Subscriptions, cancellation and refunds',
    updated: EN_UPDATED,
    disclaimer: EN_DISCLAIMER,
    intro: 'How subscriptions, renewal, cancellation and refunds work in CampaignsHub.',
    sections: [
      {
        heading: 'Subscribing and renewing',
        bullets: [
          'A subscription starts when payment is confirmed, not when the account is created.',
          'Renewal is periodic per plan, processed by the payment provider using the method held on their side.',
          'Duplicate charges for the same period are prevented by idempotency keys at the operation level.',
        ],
      },
      {
        heading: 'Cancelling',
        body: [
          'You can cancel at any time. Access continues to the end of the paid period and does not renew after it.',
          'Cancelling deletes nothing. Deletion is a separate request — see the account-deletion page.',
        ],
      },
      {
        heading: 'Non-payment',
        body: ['An unpaid period moves to past due, then to a grace window, and then the account is suspended. Suspension stops access and deletes no data.'],
      },
      {
        heading: 'Refunds',
        body: [
          'Refund requests are considered case by case within the window the operator states in the plan terms, and are issued through the same payment provider and method.',
          'Amounts covering a period in which the service was actually used are not refunded automatically.',
        ],
      },
      { heading: 'Invoices', body: ['Every payment has an invoice, downloadable from the billing page inside your account.'] },
    ],
  },
  {
    slug: 'oauth-disclosure',
    title: 'OAuth and platform data disclosure',
    updated: EN_UPDATED,
    disclaimer: EN_DISCLAIMER,
    intro: 'What CampaignsHub asks each platform for when you connect, why, and how it is used, stored and deleted.',
    sections: [
      {
        heading: 'How connecting works',
        body: [
          'Through each platform’s own official OAuth flow. You are never asked for a platform username or password, and the system cannot reach any account you did not authorise yourself.',
          'Every connection carries a single-use state value, and any response that does not match it is refused.',
        ],
      },
      {
        heading: 'What we request, and why',
        bullets: [
          'Read campaigns, ad sets, ads and creatives: to show your account’s structure as the platform actually has it.',
          'Read performance reporting: to show spend, impressions, clicks and results in the dashboard and reports.',
          'Read store data (Salla and Zid): products, orders, customers and abandoned carts, to build the store funnel and attribute orders to campaigns.',
          'Offline access: to refresh figures periodically without asking you to reconnect each time.',
        ],
      },
      {
        heading: 'Least privilege',
        body: ['Only the read scopes the product actually uses are requested. The system does not ask for permission to create, edit or stop campaigns, and does not ask to post on your behalf.'],
      },
      {
        heading: 'What we never do with platform data',
        bullets: [
          'It is not sold or shared with third parties.',
          'It is not used to train models.',
          'It is not used for another customer’s benefit — isolation between workspaces is enforced at the database level.',
          'It is not used for any advertising of our own.',
        ],
      },
      {
        heading: 'Storage and deletion',
        bullets: [
          'Access and refresh tokens are encrypted at rest and never displayed in any interface.',
          'You can disconnect any account at any time from the integrations page; the token is revoked immediately and fetching stops.',
          'On account deletion every token is revoked and the platform data held for that workspace is deleted.',
        ],
      },
      { heading: 'Platform-initiated deletion', body: [`When a deletion request arrives from a platform or from a user through one, it is carried out and written to the audit log. Contact: ${CONTACT_EMAIL}.`] },
    ],
  },
  {
    slug: 'system-status',
    title: 'System status and security support',
    updated: EN_UPDATED,
    intro: 'How to tell the service is working, and how to report a security issue.',
    sections: [
      {
        heading: 'Service health',
        body: [
          'The operator monitors service health with automated checks covering whether requests can be served, whether the scheduler is alive, and whether the queue worker that reports and syncing depend on is alive.',
          'When something breaks that affects syncing or reports, the data state is visible inside the product itself: every source states when it was last actually updated, and a source that has stopped is shown as stopped rather than letting stale figures read as current.',
        ],
      },
      {
        heading: 'Reporting a vulnerability',
        body: [
          'If you find a security issue, write to us before disclosing it publicly, with a description, reproduction steps and the likely impact.',
          `The reporting address is published on the security page; if the operator has not set a dedicated address, write to ${CONTACT_EMAIL}.`,
        ],
      },
      {
        heading: 'What we commit to',
        bullets: [
          'Acknowledging the report within one business day.',
          'Telling you the outcome of the assessment and the remediation plan.',
          'Taking no action against you for a good-faith report that does not harm users or their data.',
        ],
      },
    ],
  },
]
