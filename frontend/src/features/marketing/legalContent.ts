import type { Locale } from './homeCopy'
import { AR_OPERATIONAL, EN_OPERATIONAL } from './legalContentOperational'
import { CONTACT_EMAIL, type LegalDoc, type LegalSection } from './legalTypes'

// Re-exported so existing importers keep working unchanged.
export { CONTACT_EMAIL }
export type { LegalDoc, LegalSection }

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

const AR_DISCLAIMER =
  'هذه الصفحة تصف الممارسة الفعلية للنظام ولا تُعد استشارة قانونية. راجعها مع مستشارك القانوني قبل الاعتماد عليها تجاريًا.'
const EN_DISCLAIMER =
  'This page describes how the system actually behaves and is not legal advice. Have your counsel review it before relying on it commercially.'

const AR_UPDATED = 'آخر تحديث: 12 أغسطس 2026'
const EN_UPDATED = 'Last updated: 12 August 2026'

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
        heading: 'الربط بالمنصات والمتاجر',
        bullets: [
          'عند ربط حساب إعلاني (Meta أو Google Ads أو TikTok أو Snapchat أو X أو LinkedIn) نحفظ رمز الوصول ورمز التجديد مشفَّرَين، ونستخدمهما لقراءة أداء حساباتك فقط.',
          'عند ربط متجر (سلة أو زد) نقرأ الطلبات والسلات المتروكة والمنتجات والعملاء بالقدر الذي يظهر في تقاريرك، مع الاحتفاظ بالمنطقة الزمنية للمتجر حتى يبقى يوم التاجر يومه.',
          'لا نكتب ولا ننشر ولا نغيّر أي شيء في حساباتك الإعلانية أو متجرك — الوصول للقراءة فقط.',
          'فك الربط من داخل النظام يوقف المزامنة فورًا ويُبطل الرموز المخزَّنة. ويحدث الشيء نفسه تلقائيًا عند تنفيذ طلب حذف موثَّق.',
        ],
      },
      {
        heading: 'بيانات الاشتراك والدفع',
        bullets: [
          'اشتراكك في CampaignsHub يُحصَّل بالدولار الأمريكي عبر مزوّد دفع. أرقام البطاقات لا تمر بنا ولا تُخزَّن لدينا إطلاقًا — لا رقم بطاقة ولا CVC.',
          'ما نحتفظ به هو بيانات وصفية: معرّف العملية لدى المزوّد، والمبلغ والعملة، والحالة، وتاريخها، وعند إتاحة المزوّد لذلك وسمًا للبطاقة مثل «visa ···· 4242» لتمييز وسيلة الدفع.',
          'فواتيرك للعملاء تحتفظ بعملتها الخاصة ولا تتبع عملة الاشتراك.',
        ],
      },
      {
        heading: 'روابط التقارير المشتركة',
        body: [
          'الروابط التي ترسلها لعميلك تُفتح بدون تسجيل دخول، لذلك يستطيع الاطّلاع عليها كل من يملك الرابط. ما تعرضه محدود بما سمحت به عند إنشائه، وكل رابط قابل للإلغاء من داخل النظام في أي وقت. نسجّل عمليات الفتح لأغراض التدقيق ولا نستخدمها لأي غرض آخر.',
        ],
      },
      {
        heading: 'الجلسات وملفات الارتباط',
        body: [
          'نستخدم ملف ارتباط للجلسة وملفًا آخر لحماية النماذج من التزوير (CSRF). لا نستخدم ملفات تتبّع إعلانية، والتفاصيل في صفحة ملفات الارتباط. تسجيل الخروج يُبطل الجلسة مباشرة، ولا تعود قابلة للاستخدام حتى لو وصل طلب متأخر بعدها.',
        ],
      },
      {
        heading: 'الاحتفاظ والحذف الفعلي',
        bullets: [
          'اطلب الحذف من صفحة حذف البيانات: تفتح طلبًا برقم مرجعي، ونرسل رمزًا إلى بريدك للتأكد أنك صاحب العنوان قبل تنفيذ أي حذف.',
          'الطلب غير الموثَّق لا يُنفَّذ إطلاقًا، ولا يستطيع المشغّل إتمامه.',
          'قد يتعذّر الحذف مؤقتًا لأسباب نذكرها لك صراحةً — كفاتورة مفتوحة يفرض النظام المحاسبي الاحتفاظ بها.',
        ],
      },
      {
        heading: 'حقوقك',
        bullets: [
          'الاطلاع على بياناتك وتصحيحها من داخل النظام.',
          'تصدير تقاريرك وبياناتك بصيغ PDF أو XLSX أو CSV.',
          'طلب حذف الحساب أو بيانات محددة من صفحة حذف البيانات (‎/data-deletion‎)، مع رقم مرجعي تتابع به الطلب.',
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
        heading: 'لماذا لا يوجد شريط موافقة',
        body: [
          'لأنه لا يوجد ما تُطلب الموافقة عليه. كل ما نستخدمه ضروري لتشغيل الموقع وتسجيل دخولك وحفظ لغتك ومظهرك، ولا يمكن للموقع أن يعمل بدونه — وسؤالك عن شيء لا خيار فيه ليس موافقة.',
          'لا يوجد Google Analytics ولا Pixels ولا أي أداة تتبع على هذا الموقع. إن أُضيف مستقبلًا أي ملف غير ضروري، فستُضاف معه آلية موافقة واضحة في التغيير نفسه — لا قبله ولا بعده.',
        ],
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
        heading: 'Connected platforms and stores',
        bullets: [
          'When you connect an advertising account (Meta, Google Ads, TikTok, Snapchat, X or LinkedIn) we store its access and refresh tokens encrypted, and use them only to read your own accounts’ performance.',
          'When you connect a store (Salla or Zid) we read orders, abandoned carts, products and customers to the extent your reports show them, keeping the store’s timezone so a merchant’s day stays their own day.',
          'We never write, publish or change anything in your advertising accounts or your store. The access is read-only.',
          'Disconnecting inside the product stops the sync immediately and revokes the stored tokens. The same happens automatically when a verified deletion request is carried out.',
        ],
      },
      {
        heading: 'Subscription and payment data',
        bullets: [
          'Your CampaignsHub subscription is charged in US dollars through a payment provider. Card numbers never pass through us and are never stored here — no PAN, no CVC.',
          'What we keep is metadata: the provider’s payment id, the amount and currency, the status, its dates, and — where the provider publishes one — a card label such as “visa ···· 4242” to tell payment methods apart.',
          'Invoices you issue to your own clients keep their own currency and do not follow the subscription currency.',
        ],
      },
      {
        heading: 'Shared report links',
        body: [
          'A link you send a client opens without signing in, so anyone holding it can read it. What it shows is limited to what you allowed when you created it, and every link can be revoked from inside the product at any time. Opens are recorded for audit and used for nothing else.',
        ],
      },
      {
        heading: 'Sessions and cookies',
        body: [
          'We use a session cookie and a CSRF cookie that protects forms from being submitted by another site. We use no advertising or tracking cookies; the cookies page has the detail. Signing out invalidates the session immediately, and it cannot be used again even if a request that started earlier arrives afterwards.',
        ],
      },
      {
        heading: 'Retention and actually deleting it',
        bullets: [
          'Ask from the data deletion page: it opens a request with a reference, and we send a code to your email to confirm the address is yours before anything is deleted.',
          'An unverified request is never carried out, and an operator cannot complete one.',
          'Deletion can be held up for reasons we state plainly — an open invoice accounting rules require us to keep, for instance.',
        ],
      },
      {
        heading: 'Your rights',
        bullets: [
          'View and correct your data inside the product.',
          'Export your reports and data as PDF, XLSX or CSV.',
          'Ask for your account or specific data to be deleted from the data deletion page (/data-deletion), with a reference you can follow.',
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
      {
        heading: 'Why there is no consent banner',
        body: [
          'Because there is nothing to consent to. Everything we set is required to run the site, sign you in and remember your language and theme, and the site cannot work without it — asking about something you have no choice over is not consent.',
          'There is no Google Analytics, no pixel and no tracker on this site. If a non-essential cookie is ever introduced, a clear consent mechanism arrives with it in the same change — not before it, and not after.',
        ],
      },
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

/*
 * The operational policies live in their own module (LEGAL-001) — retention, subprocessors,
 * deletion, data-subject requests, acceptable use, subscriptions, the OAuth disclosure and status.
 *
 * Split for weight rather than for meaning: they are the same kind of document as the ones above and
 * are merged into one list here, so every consumer still sees a single set and a page can be added
 * without touching a route.
 */
export const LEGAL_DOCS: Record<Locale, LegalDoc[]> = {
  ar: [...ar, ...AR_OPERATIONAL],
  en: [...en, ...EN_OPERATIONAL],
}

export function findLegalDoc(locale: Locale, slug: string): LegalDoc | undefined {
  return LEGAL_DOCS[locale].find((d) => d.slug === slug)
}
