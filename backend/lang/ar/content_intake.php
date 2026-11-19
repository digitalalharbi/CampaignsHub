<?php

declare(strict_types=1);

return [
    'fields' => ['platform' => 'المنصة', 'objective' => 'الهدف'],
    'why' => [
        'drive' => 'الملف في Drive ولم تُقرأ محتوياته',
        'unread' => 'تعذّرت قراءة ترويسة الملف',
        'video' => 'لا يُفحص إطار الفيديو',
        'too_large' => 'الصورة كبيرة جدًا للفحص الخفيف',
        'flat' => 'لا يوجد تفاصيل قابلة للقياس في وسط الصورة',
        'no_url' => 'لم يُحدد رابط الوجهة',
    ],
    'utm_missing' => 'ينقصه :missing',
    'checks' => [
        'target' => [
            'pass' => 'المنصة والهدف محددان.',
            'fail' => 'ناقص: :missing_text. قواعد المنصة والمقارنة تحتاج الاثنين.',
        ],
        'assets' => [
            'pass' => 'المحتوى معه ملفه.',
            'fail' => 'لم يُرفق ملف :kind ولا نص ولا رابط.',
        ],
        'spec' => ['not_measured' => 'لا توجد مواصفات منشورة مسجلة للمنصة «:platform»، فلم يُفحص الحجم ولا النسبة ولا الطول.'],
        'format' => [
            'pass' => 'نوع الملف :measured مقبول.',
            'fail' => 'نوع الملف :measured غير مقبول (المقبول: :required).',
            'not_measured' => 'نوع الملف لم يُقس: :why_text.',
        ],
        'file_size' => [
            'pass' => 'حجم الملف :measured م.ب ضمن :required م.ب.',
            'fail' => 'حجم الملف :measured م.ب أعلى من الحد :required م.ب.',
            'not_measured' => 'حجم الملف لم يُقس.',
        ],
        'aspect_ratio' => [
            'pass' => 'نسبة العرض للارتفاع :ratio (:measured) مقبولة.',
            'fail' => 'نسبة العرض للارتفاع :ratio (:measured) ليست من: :required.',
            'not_measured' => 'النسبة لم تُقس: :why_text.',
        ],
        'resolution' => [
            'pass' => 'أقصر ضلع :measured بكسل يحقق الحد الأدنى :required بكسل.',
            'fail' => 'أقصر ضلع :measured بكسل أقل من الحد الأدنى :required بكسل.',
            'not_measured' => 'الدقة لم تُقس: :why_text.',
        ],
        'duration' => [
            'pass' => 'المدة :measured ثانية ضمن :required ثانية.',
            'fail' => 'المدة :measured ثانية خارج :required ثانية.',
            'not_measured' => 'المدة لم تُقس: :why_text.',
        ],
        'safe_zone' => [
            'pass' => 'التفاصيل في الشريط العلوي والسفلي (:top / :bottom) لا تتجاوز :factor ضعف الوسط (:centre).',
            'warn' => 'تقدير تقريبي: التفاصيل في الشريط العلوي (:top) أو السفلي (:bottom) تتجاوز :factor ضعف الوسط (:centre). راجع النص والشعار مقابل واجهة المنصة.',
            'not_measured' => 'المنطقة الآمنة لم تُقس: :why_text.',
        ],
        'copy_length' => [
            'pass' => 'أطوال النصوص ضمن حدود المنصة.',
            'warn' => 'نص أطول من حد المنصة: :fields.',
        ],
        'utm' => [
            'pass' => 'الرابط فيه utm_source وutm_medium وutm_campaign.',
            'warn' => 'الرابط: :detail.',
        ],
        'naming' => [
            'pass' => 'الاسم يوافق صيغة التسمية في مساحة العمل.',
            'warn' => 'الاسم لا يوافق صيغة التسمية :pattern.',
            'not_measured' => 'لا توجد صيغة تسمية محددة في مساحة العمل.',
        ],
        'duplicate' => [
            'pass' => 'لا يوجد ملف مطابق قيد المراجعة أو معتمد أو منشور.',
            'warn' => 'يوجد ملف مطابق في مشروع آخر: «:name» (:status).',
            'fail' => 'يوجد ملف مطابق في هذا المشروع: «:name» (:status).',
        ],
    ],
    'recommendations' => [
        'history_insufficient' => 'يوجد :peers محتوى مقاس فقط لهذا الهدف والمنصة، ونحتاج :needed على الأقل للمقارنة.',
        'format_stronger' => 'في آخر :days يوم، متوسط :metric لمحتوى :format لهذا الهدف والمنصة :same مقابل :other للأشكال الأخرى (:n_same / :n_other محتوى).',
        'format_weaker' => 'في آخر :days يوم، متوسط :metric لمحتوى :format لهذا الهدف والمنصة :same مقابل :other للأشكال الأخرى (:n_same / :n_other محتوى)، وهذا أضعف.',
    ],
    'notify' => [
        'submitted' => 'محتوى جديد بانتظار المراجعة',
        'decided' => 'قرار على محتواك',
    ],
];
