import type { Locale } from './homeCopy'

/**
 * Content for the public policy and company pages the footer links to.
 *
 * These are written as honest descriptions of how CampaignsHub actually behaves today — what is stored,
 * who can see it, what is not done — rather than boilerplate copied from a template. Where a capability
 * is not live yet (ad-platform connections await each platform's credentials), the text says so instead
 * of implying data is already flowing.
 *
 * They are deliberately NOT legal advice: the closing note on every policy page tells the operator to
 * have counsel review the text before relying on it commercially.
 */

export const CONTACT_EMAIL = 'info@CampaignsHub.io'

export interface LegalSection {
  heading: string
  /** Paragraphs and/or bullet lists, in reading order. */
  body?: string[]
  bullets?: string[]
}

export interface LegalDoc {
  slug: string
  title: string
  intro: string
  /** Shown under the title so a reader knows how current the text is. */
  updated: string
  sections: LegalSection[]
  /** Set on policy documents; company pages (about/contact/support/faq) leave it off. */
  disclaimer?: string
}

const AR_DISCLAIMER =
  'هذه الصفحة تصف الممارسة الفعلية للنظام ولا تُعد استشارة قانونية. راجعها مع مستشارك القانوني قبل الاعتماد عليها تجاريًا.'
const EN_DISCLAIMER =
  'This page describes how the system actually behaves and is not legal advice. Have your counsel review it before relying on it commercially.'

const AR_UPDATED = 'آخر تحديث: 30 يوليو 2026'
const EN_UPDATED = 'Last updated: 30 July 2026'

const ar: LegalDoc[] = [
  {
    slug: 'privacy',
    title: 'سياسة الخصوصية',
    updated: AR_UPDATED,
    intro: 'توضح هذه السياسة البيانات التي يجمعها CampaignsHub، وسبب جمعها، ومن يستطيع الاطلاع عليها، وكيف يمكنك حذفها.',
    disclaimer: AR_DISCLAIMER,
    sections: [
      {
        heading: 'البيانات التي نجمعها',
        bullets: [
          'بيانات الحساب: الاسم والبريد الإلكتروني ورقم الجوال ودور المستخدم داخل مساحة العمل.',
          'بيانات الطلبات: تفاصيل الخدمة المطلوبة والمرفقات والرسائل المتبادلة حول الطلب.',
          'بيانات الحملات: أرقام الأداء التي تُجلب من حسابات المنصات الإعلانية بعد ربطها بموافقتك.',
          'بيانات الفوترة: عروض الأسعار والفواتير وحالة السداد. لا نخزّن أرقام البطاقات — يتولى ذلك مزوّد الدفع.',
          'سجلات تقنية: وقت العمليات الحساسة ومن نفّذها، لأغراض التدقيق والأمان.',
        ],
      },
      {
        heading: 'ما لا نفعله',
        bullets: [
          'لا نبيع بياناتك ولا بيانات عملائك لأي طرف ثالث.',
          'لا نستخدم بيانات حملاتك لتدريب نماذج أو لمصلحة عميل آخر.',
          'لا نصل إلى حساباتك الإعلانية قبل ربطها صراحةً منك.',
        ],
      },
      {
        heading: 'العزل بين الحسابات',
        body: [
          'كل مساحة عمل معزولة على مستوى قاعدة البيانات؛ لا يمكن لمستخدم في مساحة أن يقرأ بيانات مساحة أخرى، حتى بمعرفة المعرّف. الصلاحيات داخل المساحة تحدد من يرى العملاء والفواتير والتقارير.',
        ],
      },
      {
        heading: 'مدة الاحتفاظ والحذف',
        body: [
          'نحتفظ ببيانات مساحة العمل ما دام الحساب نشطًا. عند طلب الحذف نزيل بيانات المساحة خلال مدة معقولة، مع الإبقاء على الحد الأدنى الذي تفرضه المتطلبات المحاسبية للفواتير الصادرة.',
        ],
      },
      {
        heading: 'حقوقك',
        bullets: [
          'الاطلاع على بياناتك وتصحيحها من داخل النظام.',
          'تصدير تقاريرك وبياناتك بصيغ PDF أو XLSX أو CSV.',
          'طلب حذف الحساب وبياناته عبر البريد أدناه.',
        ],
      },
      { heading: 'التواصل', body: [`لأي استفسار يخص الخصوصية أو طلبات الحذف: ${CONTACT_EMAIL}`] },
    ],
  },
  {
    slug: 'terms',
    title: 'الشروط والأحكام',
    updated: AR_UPDATED,
    intro: 'تحكم هذه الشروط استخدامك لمنصة CampaignsHub سواء كنت تدير حملاتك بنفسك أو تطلب خدمة إعلانية.',
    disclaimer: AR_DISCLAIMER,
    sections: [
      {
        heading: 'الحساب والاستخدام',
        bullets: [
          'أنت مسؤول عن صحة بيانات حسابك وعن سرية كلمة المرور.',
          'أنت مسؤول عن محتوى حملاتك والتزامها بسياسات المنصات الإعلانية والأنظمة المعمول بها.',
          'يُمنع استخدام النظام لأي نشاط مخالف أو لمحاولة الوصول إلى بيانات مساحة عمل أخرى.',
        ],
      },
      {
        heading: 'الخدمات والطلبات',
        body: [
          'عند طلب خدمة إعلانية يُصدر لك عرض سعر يوضح النطاق والمقابل المالي. لا يبدأ التنفيذ قبل اعتمادك للعرض، ويمكنك متابعة كل خطوة عبر رابط آمن أو من حسابك.',
        ],
      },
      {
        heading: 'الفوترة والضريبة',
        bullets: [
          'تُصدر الفواتير بالريال السعودي ما لم يُتفق على غير ذلك.',
          'تُحتسب ضريبة القيمة المضافة حسب المعالجة الضريبية المحددة في العرض أو الفاتورة.',
          'تُعد الفاتورة مستحقة في تاريخ الاستحقاق المذكور فيها.',
        ],
      },
      {
        heading: 'حدود المسؤولية',
        body: [
          'نبذل جهدًا معقولًا لإتاحة الخدمة وصحة الأرقام المعروضة، غير أن بيانات الأداء تأتي من المنصات الإعلانية وقد تتغير بأثر رجعي نتيجة إعادة الإسناد. لا نتحمل مسؤولية قرارات تُتخذ بناءً على أرقام لم تكتمل مزامنتها بعد.',
        ],
      },
      {
        heading: 'إنهاء الخدمة',
        body: ['يمكنك إنهاء الاشتراك في أي وقت. تبقى الفواتير المستحقة قبل الإنهاء واجبة السداد.'],
      },
      { heading: 'التواصل', body: [`لأي استفسار حول هذه الشروط: ${CONTACT_EMAIL}`] },
    ],
  },
  {
    slug: 'data-processing',
    title: 'معالجة البيانات',
    updated: AR_UPDATED,
    intro: 'يوضح هذا المستند دور كل طرف في معالجة البيانات، والفئات التي تُعالج، والأطراف الفرعية المستخدمة.',
    disclaimer: AR_DISCLAIMER,
    sections: [
      {
        heading: 'الأدوار',
        body: [
          'أنت المتحكم في بيانات عملائك وحملاتك. يعمل CampaignsHub معالجًا لهذه البيانات بالنيابة عنك وبحدود التعليمات التي تصدرها من داخل النظام.',
        ],
      },
      {
        heading: 'فئات البيانات المعالجة',
        bullets: [
          'بيانات تعريفية لمستخدمي مساحة العمل وجهات اتصال العملاء.',
          'قياسات أداء الحملات على مستوى الحملة والمجموعة الإعلانية والإعلان.',
          'مستندات مالية: عروض الأسعار والفواتير وسجل المدفوعات.',
          'مرفقات الطلبات والمحتويات الإعلانية التي ترفعها أو تُجلب من المنصة.',
        ],
      },
      {
        heading: 'الأطراف الفرعية',
        bullets: [
          'المنصات الإعلانية (ميتا، جوجل، تيك توك، سناب شات، X، لينكدإن) — لجلب بيانات الحملات بعد ربط الحساب.',
          'مزود الدفع — لمعالجة عمليات السداد؛ لا تمر بيانات البطاقة عبر أنظمتنا.',
          'مزود البريد — لإرسال التقارير والإشعارات عند تفعيله.',
        ],
      },
      {
        heading: 'نقل البيانات',
        body: [
          'تُخزَّن البيانات في بيئة الاستضافة المتفق عليها معك. أي نقل خارج نطاقها يتم فقط عند استخدام طرف فرعي أعلاه وبالقدر اللازم لأداء الخدمة.',
        ],
      },
      { heading: 'التواصل', body: [`لطلب اتفاقية معالجة بيانات موقّعة: ${CONTACT_EMAIL}`] },
    ],
  },
  {
    slug: 'cookies',
    title: 'ملفات تعريف الارتباط',
    updated: AR_UPDATED,
    intro: 'نستخدم أقل قدر ممكن من ملفات تعريف الارتباط، ولأغراض تشغيلية فقط.',
    disclaimer: AR_DISCLAIMER,
    sections: [
      {
        heading: 'ما نستخدمه',
        bullets: [
          'ملف جلسة لتسجيل الدخول والحفاظ على الجلسة آمنة.',
          'ملف حماية من تزوير الطلبات (CSRF).',
          'تفضيلات العرض: اللغة والمظهر الفاتح أو الداكن.',
        ],
      },
      {
        heading: 'ما لا نستخدمه',
        body: ['لا نستخدم ملفات تتبع إعلاني ولا نشارك سلوك تصفحك مع شبكات إعلانية.'],
      },
      {
        heading: 'التحكم',
        body: ['يمكنك حذف ملفات تعريف الارتباط من متصفحك في أي وقت؛ سيؤدي حذف ملف الجلسة إلى تسجيل الخروج فقط.'],
      },
    ],
  },
  {
    slug: 'security',
    title: 'الأمان',
    updated: AR_UPDATED,
    intro: 'كيف نحمي حسابك وبيانات عملائك، وما الذي نطلبه منك.',
    disclaimer: AR_DISCLAIMER,
    sections: [
      {
        heading: 'ما نطبّقه',
        bullets: [
          'عزل كامل بين مساحات العمل على مستوى قاعدة البيانات.',
          'صلاحيات دقيقة لكل دور؛ كل عملية حساسة تُفحص على الخادم لا في الواجهة فقط.',
          'تشفير بيانات اعتماد المنصات الإعلانية عند التخزين، ولا تُعرض أبدًا بعد حفظها.',
          'سجل تدقيق للعمليات الحساسة يوضح من نفّذ ماذا ومتى.',
          'تحقق من البريد والجوال قبل تفعيل متابعة الطلبات.',
        ],
      },
      {
        heading: 'ما نطلبه منك',
        bullets: [
          'كلمة مرور قوية وعدم مشاركتها.',
          'منح كل عضو أقل صلاحية تكفي لعمله.',
          'إبلاغنا فورًا عند الاشتباه في وصول غير مصرح به.',
        ],
      },
      {
        heading: 'الإبلاغ عن ثغرة',
        body: [`إذا اكتشفت ثغرة أمنية، راسلنا على ${CONTACT_EMAIL} قبل نشرها، وسنعمل على معالجتها والرد عليك.`],
      },
    ],
  },
  {
    slug: 'about',
    title: 'من نحن',
    updated: AR_UPDATED,
    intro: 'CampaignsHub منصة لإدارة الحملات الإعلانية المدفوعة: تجمع حملاتك وميزانياتك ونتائجك من المنصات المختلفة في مكان واحد.',
    sections: [
      {
        heading: 'لماذا بُنيت',
        body: [
          'يدير المعلن أو الوكالة حملاته على أكثر من منصة، وكل منصة تعرض أرقامها بطريقتها. الوقت يضيع في جمع الأرقام يدويًا بدل تحليلها. CampaignsHub يوحّد هذه الأرقام، ويقارن الأداء بشكل عادل حسب هدف كل حملة، ويحوّلها إلى تقارير وتنبيهات قابلة للتنفيذ.',
        ],
      },
      {
        heading: 'لمن',
        bullets: [
          'معلن يدير حملاته بنفسه ويريد لوحة واحدة واضحة.',
          'وكالة تدير حملات عدة عملاء وتحتاج فصلًا وتقارير لكل عميل.',
          'جهة تطلب خدمة إعلانية وتريد متابعة التنفيذ والعرض والفاتورة في مكان واحد.',
        ],
      },
      {
        heading: 'كيف نتعامل مع الأرقام',
        body: [
          'لا نعرض حالة «متصل» أو «تمت المزامنة» قبل حدوث عملية فعلية، ولا نخلط مؤشرات حملات ذات أهداف مختلفة في رقم واحد. إن كانت البيانات ناقصة نقول ذلك بدل ملء الفراغ بتقدير.',
        ],
      },
      { heading: 'التواصل', body: [`${CONTACT_EMAIL}`] },
    ],
  },
  {
    slug: 'contact',
    title: 'تواصل معنا',
    updated: AR_UPDATED,
    intro: 'نسعد بأسئلتك وطلباتك. اختر القناة الأنسب لك.',
    sections: [
      {
        heading: 'البريد الإلكتروني',
        body: [`للمبيعات والدعم والاستفسارات العامة: ${CONTACT_EMAIL}`],
      },
      {
        heading: 'طلب خدمة إعلانية',
        body: [
          'إذا كنت تريد عرض سعر لخدمة محددة، أرسل طلبك من صفحة «اطلب خدمة» وستصلك رسالة برابط آمن لمتابعة الطلب وعروض الأسعار والفواتير.',
        ],
      },
      {
        heading: 'عميل حالي',
        body: ['إذا كان لديك طلب قائم، تابعه من «متابعة طلباتي» باستخدام بريدك أو جوالك المسجّل.'],
      },
      {
        heading: 'الإبلاغ عن مشكلة أمنية',
        body: [`راسلنا على ${CONTACT_EMAIL} مع وصف المشكلة وخطوات إعادة إنتاجها.`],
      },
    ],
  },
  {
    slug: 'support',
    title: 'الدعم والمساعدة',
    updated: AR_UPDATED,
    intro: 'ما الذي يمكننا مساعدتك فيه، وكيف تصل إلينا بأسرع طريق.',
    sections: [
      {
        heading: 'قبل أن تراسلنا',
        bullets: [
          'تحقق من أن الحساب الإعلاني مرتبط فعلًا من صفحة تكاملات المشروع.',
          'راجع سجل المزامنة: يوضح آخر عملية ونتيجتها وسبب فشلها إن وُجد.',
          'تأكد من صلاحياتك؛ بعض الإجراءات تتطلب صلاحية إدارة.',
        ],
      },
      {
        heading: 'أوقات الاستجابة',
        body: [
          'نرد على الرسائل خلال يوم عمل. الأعطال التي توقف العمل تُعالج بأولوية أعلى.',
        ],
      },
      { heading: 'راسلنا', body: [`${CONTACT_EMAIL}`] },
    ],
  },
  {
    slug: 'faq',
    title: 'الأسئلة الشائعة',
    updated: AR_UPDATED,
    intro: 'أكثر ما يُسأل عنه قبل البدء.',
    sections: [
      {
        heading: 'هل أحتاج لربط حساباتي الإعلانية لأبدأ؟',
        body: ['لا. يمكنك إنشاء الحساب وتنظيم عملائك ومشاريعك وحملاتك أولًا، ثم ربط المنصات لاحقًا لتبدأ الأرقام في الوصول.'],
      },
      {
        heading: 'ما المنصات المدعومة؟',
        body: ['ميتا وجوجل وتيك توك وسناب شات وX ولينكدإن. حالة كل منصة معروضة بصدق في صفحة المنصات المدعومة، وبعضها ينتظر بيانات اعتماد.'],
      },
      {
        heading: 'هل الأرقام المعروضة في الصفحة الرئيسية حقيقية؟',
        body: ['لا. المعاينة في الأعلى بيانات تجريبية موسومة بوضوح، والغرض منها توضيح شكل النظام فقط.'],
      },
      {
        heading: 'كيف تُحتسب تكلفة النتيجة؟',
        body: ['تُحتسب حسب هدف الحملة: التحويلات لحملات المبيعات، والعملاء المحتملون لحملات جمع البيانات، والوصول لحملات الوعي. لا نخلط أهدافًا مختلفة في متوسط واحد.'],
      },
      {
        heading: 'هل يمكنني تصدير تقاريري؟',
        body: ['نعم، بصيغ PDF وXLSX وCSV، مع إمكانية جدولة إرسالها في موعد ثابت.'],
      },
      {
        heading: 'كيف أحذف حسابي؟',
        body: [`أرسل طلبًا من ${CONTACT_EMAIL} من البريد المسجّل، وسنحذف بيانات مساحة العمل مع الإبقاء على الحد الأدنى الذي تفرضه المتطلبات المحاسبية.`],
      },
    ],
  },
]

const en: LegalDoc[] = [
  {
    slug: 'privacy',
    title: 'Privacy policy',
    updated: EN_UPDATED,
    intro: 'What CampaignsHub collects, why, who can see it, and how you have it deleted.',
    disclaimer: EN_DISCLAIMER,
    sections: [
      {
        heading: 'What we collect',
        bullets: [
          'Account data: name, email, phone and the user’s role in the workspace.',
          'Request data: the service requested, attachments and the messages about it.',
          'Campaign data: performance figures pulled from ad accounts once you connect them.',
          'Billing data: quotes, invoices and payment status. Card numbers are handled by the payment provider, never stored here.',
          'Technical records: who performed a sensitive action and when, for audit and security.',
        ],
      },
      {
        heading: 'What we do not do',
        bullets: [
          'We do not sell your data or your clients’ data.',
          'We do not use your campaign data to train models or to benefit another client.',
          'We do not touch your ad accounts before you explicitly connect them.',
        ],
      },
      {
        heading: 'Isolation between accounts',
        body: [
          'Every workspace is isolated at the database level; a user in one workspace cannot read another’s data even if they know the identifier. Permissions inside a workspace decide who sees clients, invoices and reports.',
        ],
      },
      {
        heading: 'Retention and deletion',
        body: [
          'We keep workspace data while the account is active. On a deletion request we remove it within a reasonable period, keeping only the minimum that accounting rules require for issued invoices.',
        ],
      },
      {
        heading: 'Your rights',
        bullets: [
          'View and correct your data inside the product.',
          'Export your reports and data as PDF, XLSX or CSV.',
          'Request deletion of your account and its data by email.',
        ],
      },
      { heading: 'Contact', body: [`Privacy questions and deletion requests: ${CONTACT_EMAIL}`] },
    ],
  },
  {
    slug: 'terms',
    title: 'Terms of service',
    updated: EN_UPDATED,
    intro: 'These terms govern your use of CampaignsHub, whether you run your own campaigns or request a service.',
    disclaimer: EN_DISCLAIMER,
    sections: [
      {
        heading: 'Account and use',
        bullets: [
          'You are responsible for the accuracy of your account details and for keeping your password private.',
          'You are responsible for your campaign content and its compliance with each platform’s policies and applicable law.',
          'The system may not be used for unlawful activity or to attempt access to another workspace’s data.',
        ],
      },
      {
        heading: 'Services and requests',
        body: [
          'A service request produces a quote stating scope and price. Work starts only after you approve it, and every step is trackable from a secure link or from your account.',
        ],
      },
      {
        heading: 'Billing and tax',
        bullets: [
          'Invoices are issued in SAR unless agreed otherwise.',
          'VAT follows the tax treatment stated on the quote or invoice.',
          'An invoice is payable on the due date shown on it.',
        ],
      },
      {
        heading: 'Limits of liability',
        body: [
          'We make reasonable efforts to keep the service available and its figures correct, but performance data comes from the ad platforms and can change retroactively through re-attribution. We are not liable for decisions made on figures that have not finished syncing.',
        ],
      },
      { heading: 'Termination', body: ['You may cancel at any time. Invoices due before cancellation remain payable.'] },
      { heading: 'Contact', body: [`Questions about these terms: ${CONTACT_EMAIL}`] },
    ],
  },
  {
    slug: 'data-processing',
    title: 'Data processing',
    updated: EN_UPDATED,
    intro: 'Each party’s role, the categories of data processed, and the sub-processors involved.',
    disclaimer: EN_DISCLAIMER,
    sections: [
      {
        heading: 'Roles',
        body: [
          'You are the controller of your clients’ and campaigns’ data. CampaignsHub acts as processor on your behalf and within the instructions you give through the product.',
        ],
      },
      {
        heading: 'Categories processed',
        bullets: [
          'Identifying data for workspace users and client contacts.',
          'Campaign performance at campaign, ad-set and ad level.',
          'Financial documents: quotes, invoices and payment history.',
          'Request attachments and ad creatives you upload or that are pulled from a platform.',
        ],
      },
      {
        heading: 'Sub-processors',
        bullets: [
          'Ad platforms (Meta, Google, TikTok, Snapchat, X, LinkedIn) — to pull campaign data once an account is connected.',
          'Payment provider — to process payments; card data never passes through our systems.',
          'Email provider — to deliver reports and notifications when enabled.',
        ],
      },
      {
        heading: 'Transfers',
        body: [
          'Data is stored in the hosting environment agreed with you. Any transfer beyond it happens only through a sub-processor above and only as far as delivering the service requires.',
        ],
      },
      { heading: 'Contact', body: [`To request a signed data-processing agreement: ${CONTACT_EMAIL}`] },
    ],
  },
  {
    slug: 'cookies',
    title: 'Cookies',
    updated: EN_UPDATED,
    intro: 'We use as few cookies as possible, and only operational ones.',
    disclaimer: EN_DISCLAIMER,
    sections: [
      {
        heading: 'What we use',
        bullets: [
          'A session cookie to sign you in and keep the session secure.',
          'A CSRF-protection cookie.',
          'Display preferences: language and light or dark theme.',
        ],
      },
      { heading: 'What we do not use', body: ['No advertising trackers, and no sharing of your browsing behaviour with ad networks.'] },
      { heading: 'Control', body: ['You can clear cookies in your browser at any time; clearing the session cookie simply signs you out.'] },
    ],
  },
  {
    slug: 'security',
    title: 'Security',
    updated: EN_UPDATED,
    intro: 'How we protect your account and your clients’ data, and what we ask of you.',
    disclaimer: EN_DISCLAIMER,
    sections: [
      {
        heading: 'What we do',
        bullets: [
          'Full isolation between workspaces at the database level.',
          'Fine-grained permissions per role; every sensitive action is checked on the server, not only in the interface.',
          'Ad-platform credentials are encrypted at rest and never shown again after saving.',
          'An audit log of sensitive actions recording who did what, and when.',
          'Email and phone verification before request tracking is enabled.',
        ],
      },
      {
        heading: 'What we ask of you',
        bullets: [
          'Use a strong password and do not share it.',
          'Give each member the least permission their work needs.',
          'Tell us immediately if you suspect unauthorised access.',
        ],
      },
      {
        heading: 'Reporting a vulnerability',
        body: [`If you find a security issue, email ${CONTACT_EMAIL} before disclosing it and we will work on a fix and respond to you.`],
      },
    ],
  },
  {
    slug: 'about',
    title: 'About',
    updated: EN_UPDATED,
    intro: 'CampaignsHub is a paid-advertising management platform: your campaigns, budgets and results from every platform in one place.',
    sections: [
      {
        heading: 'Why it exists',
        body: [
          'Advertisers and agencies run campaigns on several platforms, and each platform reports in its own way. Time goes into collecting numbers instead of acting on them. CampaignsHub unifies those numbers, compares performance fairly by each campaign’s objective, and turns them into reports and alerts you can act on.',
        ],
      },
      {
        heading: 'Who it is for',
        bullets: [
          'An advertiser running their own campaigns who wants one clear dashboard.',
          'An agency running campaigns for several clients that needs separation and per-client reporting.',
          'An organisation requesting a service that wants the work, the quote and the invoice in one place.',
        ],
      },
      {
        heading: 'How we treat numbers',
        body: [
          'We never show "connected" or "synced" before a real operation happened, and we never blend campaigns with different objectives into one figure. When data is incomplete we say so rather than filling the gap with an estimate.',
        ],
      },
      { heading: 'Contact', body: [CONTACT_EMAIL] },
    ],
  },
  {
    slug: 'contact',
    title: 'Contact',
    updated: EN_UPDATED,
    intro: 'We are glad to hear from you. Pick whichever route fits.',
    sections: [
      { heading: 'Email', body: [`Sales, support and general questions: ${CONTACT_EMAIL}`] },
      {
        heading: 'Requesting a service',
        body: [
          'For a quote on a specific service, send a request from the "Request a service" page and you will receive a secure link to follow the request, its quote and its invoice.',
        ],
      },
      { heading: 'Existing client', body: ['If you already have a request, follow it from "Track my requests" using your registered email or phone.'] },
      { heading: 'Reporting a security issue', body: [`Email ${CONTACT_EMAIL} with a description and reproduction steps.`] },
    ],
  },
  {
    slug: 'support',
    title: 'Support',
    updated: EN_UPDATED,
    intro: 'What we can help with, and the fastest way to reach us.',
    sections: [
      {
        heading: 'Before you write',
        bullets: [
          'Check the ad account is actually connected on the project integrations page.',
          'Check the sync log: it shows the last run, its result, and why it failed if it did.',
          'Check your permissions; some actions require management rights.',
        ],
      },
      { heading: 'Response times', body: ['We reply within one business day. Issues that stop work are prioritised.'] },
      { heading: 'Write to us', body: [CONTACT_EMAIL] },
    ],
  },
  {
    slug: 'faq',
    title: 'FAQ',
    updated: EN_UPDATED,
    intro: 'The questions we are asked most before starting.',
    sections: [
      {
        heading: 'Do I have to connect my ad accounts to start?',
        body: ['No. You can create the account and organise clients, projects and campaigns first, then connect platforms later so the numbers start arriving.'],
      },
      {
        heading: 'Which platforms are supported?',
        body: ['Meta, Google, TikTok, Snapchat, X and LinkedIn. Each one’s status is stated honestly on the supported-platforms section; some are awaiting credentials.'],
      },
      {
        heading: 'Are the numbers on the homepage real?',
        body: ['No. The preview at the top is clearly labelled demo data, shown only to illustrate what the product looks like.'],
      },
      {
        heading: 'How is cost per result calculated?',
        body: ['By each campaign’s objective: conversions for sales campaigns, leads for lead generation, reach for awareness. We never average different objectives together.'],
      },
      { heading: 'Can I export my reports?', body: ['Yes — PDF, XLSX and CSV, with scheduled delivery if you want them sent on a fixed date.'] },
      {
        heading: 'How do I delete my account?',
        body: [`Email ${CONTACT_EMAIL} from your registered address and we will delete the workspace data, keeping only the minimum accounting rules require.`],
      },
    ],
  },
]

export const LEGAL_DOCS: Record<Locale, LegalDoc[]> = { ar, en }

export function findLegalDoc(locale: Locale, slug: string): LegalDoc | undefined {
  return LEGAL_DOCS[locale].find((d) => d.slug === slug)
}
