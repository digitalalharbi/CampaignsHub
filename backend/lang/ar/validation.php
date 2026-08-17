<?php

declare(strict_types=1);

/*
 * Arabic validation messages (I18N-001).
 *
 * The single highest-leverage file in this unit: every validated field in the application draws its
 * message from here, so translating it once answers every form in the product rather than the handful
 * somebody remembered to touch. Before it existed an Arabic form labelled «البريد الإلكتروني» failed
 * with "The email field is required."
 *
 * Numbers stay in Latin digits throughout, per the product rule — a size limit rendered in
 * Eastern-Arabic numerals cannot be compared against what the user typed into the field.
 */

return [
    'accepted' => 'يجب قبول :attribute.',
    'accepted_if' => 'يجب قبول :attribute عندما يكون :other هو :value.',
    'active_url' => ':attribute ليس رابطًا صحيحًا.',
    'after' => 'يجب أن يكون :attribute تاريخًا بعد :date.',
    'after_or_equal' => 'يجب أن يكون :attribute تاريخًا بعد أو يساوي :date.',
    'alpha' => 'يجب ألا يحتوي :attribute إلا على حروف.',
    'alpha_dash' => 'يجب ألا يحتوي :attribute إلا على حروف وأرقام وشرطات.',
    'alpha_num' => 'يجب ألا يحتوي :attribute إلا على حروف وأرقام.',
    'array' => 'يجب أن يكون :attribute مصفوفة.',
    'ascii' => 'يجب ألا يحتوي :attribute إلا على حروف ورموز أحادية البايت.',
    'before' => 'يجب أن يكون :attribute تاريخًا قبل :date.',
    'before_or_equal' => 'يجب أن يكون :attribute تاريخًا قبل أو يساوي :date.',
    'between' => [
        'array' => 'يجب أن يحتوي :attribute على عدد عناصر بين :min و :max.',
        'file' => 'يجب أن يكون حجم :attribute بين :min و :max كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute بين :min و :max.',
        'string' => 'يجب أن يكون طول :attribute بين :min و :max حرفًا.',
    ],
    'boolean' => 'يجب أن تكون قيمة :attribute صحيحة أو خاطئة.',
    'can' => 'يحتوي :attribute على قيمة غير مصرح بها.',
    'confirmed' => 'حقل تأكيد :attribute غير مطابق.',
    'contains' => 'ينقص :attribute قيمة مطلوبة.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => ':attribute ليس تاريخًا صحيحًا.',
    'date_equals' => 'يجب أن يكون :attribute تاريخًا مساويًا لـ :date.',
    'date_format' => 'لا يطابق :attribute الصيغة :format.',
    'decimal' => 'يجب أن يحتوي :attribute على :decimal خانة عشرية.',
    'declined' => 'يجب رفض :attribute.',
    'declined_if' => 'يجب رفض :attribute عندما يكون :other هو :value.',
    'different' => 'يجب أن يكون :attribute مختلفًا عن :other.',
    'digits' => 'يجب أن يتكون :attribute من :digits رقمًا.',
    'digits_between' => 'يجب أن يتكون :attribute من عدد أرقام بين :min و :max.',
    'dimensions' => 'أبعاد صورة :attribute غير صحيحة.',
    'distinct' => 'يحتوي حقل :attribute على قيمة مكررة.',
    'doesnt_end_with' => 'يجب ألا ينتهي :attribute بأحد القيم التالية: :values.',
    'doesnt_start_with' => 'يجب ألا يبدأ :attribute بأحد القيم التالية: :values.',
    'email' => 'يجب أن يكون :attribute بريدًا إلكترونيًا صحيحًا.',
    'ends_with' => 'يجب أن ينتهي :attribute بأحد القيم التالية: :values.',
    'enum' => 'القيمة المحددة في :attribute غير صحيحة.',
    'exists' => 'القيمة المحددة في :attribute غير موجودة.',
    'extensions' => 'يجب أن يكون امتداد ملف :attribute أحد التالي: :values.',
    'file' => 'يجب أن يكون :attribute ملفًا.',
    'filled' => 'حقل :attribute مطلوب.',
    'gt' => [
        'array' => 'يجب أن يحتوي :attribute على أكثر من :value عنصرًا.',
        'file' => 'يجب أن يكون حجم :attribute أكبر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أكبر من :value.',
        'string' => 'يجب أن يكون طول :attribute أكبر من :value حرفًا.',
    ],
    'gte' => [
        'array' => 'يجب أن يحتوي :attribute على :value عنصرًا أو أكثر.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أكبر.',
        'numeric' => 'يجب أن تكون قيمة :attribute :value أو أكبر.',
        'string' => 'يجب أن يكون طول :attribute :value حرفًا أو أكثر.',
    ],
    'hex_color' => 'يجب أن يكون :attribute لونًا بصيغة ست عشرية صحيحة.',
    'image' => 'يجب أن يكون :attribute صورة.',
    'in' => 'القيمة المحددة في :attribute غير صحيحة.',
    'in_array' => 'لا يوجد :attribute ضمن :other.',
    'integer' => 'يجب أن يكون :attribute عددًا صحيحًا.',
    'ip' => 'يجب أن يكون :attribute عنوان IP صحيحًا.',
    'ipv4' => 'يجب أن يكون :attribute عنوان IPv4 صحيحًا.',
    'ipv6' => 'يجب أن يكون :attribute عنوان IPv6 صحيحًا.',
    'json' => 'يجب أن يكون :attribute نصًا بصيغة JSON صحيحة.',
    'list' => 'يجب أن يكون :attribute قائمة.',
    'lowercase' => 'يجب أن يكون :attribute بحروف صغيرة.',
    'lt' => [
        'array' => 'يجب أن يحتوي :attribute على أقل من :value عنصرًا.',
        'file' => 'يجب أن يكون حجم :attribute أصغر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أصغر من :value.',
        'string' => 'يجب أن يكون طول :attribute أصغر من :value حرفًا.',
    ],
    'lte' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :value عنصرًا.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أصغر.',
        'numeric' => 'يجب أن تكون قيمة :attribute :value أو أصغر.',
        'string' => 'يجب أن يكون طول :attribute :value حرفًا أو أقل.',
    ],
    'mac_address' => 'يجب أن يكون :attribute عنوان MAC صحيحًا.',
    'max' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :max عنصرًا.',
        'file' => 'يجب ألا يزيد حجم :attribute عن :max كيلوبايت.',
        'numeric' => 'يجب ألا تزيد قيمة :attribute عن :max.',
        'string' => 'يجب ألا يزيد طول :attribute عن :max حرفًا.',
    ],
    'max_digits' => 'يجب ألا يحتوي :attribute على أكثر من :max رقمًا.',
    'mimes' => 'يجب أن يكون :attribute ملفًا من نوع: :values.',
    'mimetypes' => 'يجب أن يكون :attribute ملفًا من نوع: :values.',
    'min' => [
        'array' => 'يجب أن يحتوي :attribute على :min عنصرًا على الأقل.',
        'file' => 'يجب ألا يقل حجم :attribute عن :min كيلوبايت.',
        'numeric' => 'يجب ألا تقل قيمة :attribute عن :min.',
        'string' => 'يجب ألا يقل طول :attribute عن :min حرفًا.',
    ],
    'min_digits' => 'يجب أن يحتوي :attribute على :min رقمًا على الأقل.',
    'missing' => 'يجب ألا يكون حقل :attribute موجودًا.',
    'missing_if' => 'يجب ألا يكون حقل :attribute موجودًا عندما يكون :other هو :value.',
    'missing_unless' => 'يجب ألا يكون حقل :attribute موجودًا ما لم يكن :other هو :value.',
    'missing_with' => 'يجب ألا يكون حقل :attribute موجودًا عند وجود :values.',
    'missing_with_all' => 'يجب ألا يكون حقل :attribute موجودًا عند وجود :values.',
    'multiple_of' => 'يجب أن تكون قيمة :attribute من مضاعفات :value.',
    'not_in' => 'القيمة المحددة في :attribute غير صحيحة.',
    'not_regex' => 'صيغة :attribute غير صحيحة.',
    'numeric' => 'يجب أن يكون :attribute رقمًا.',
    'password' => [
        'letters' => 'يجب أن تحتوي :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن تحتوي :attribute على حرف كبير وحرف صغير على الأقل.',
        'numbers' => 'يجب أن تحتوي :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن تحتوي :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'ظهرت :attribute في تسريب بيانات سابق. الرجاء اختيار قيمة أخرى.',
    ],
    'present' => 'يجب أن يكون حقل :attribute موجودًا.',
    'present_if' => 'يجب أن يكون حقل :attribute موجودًا عندما يكون :other هو :value.',
    'present_unless' => 'يجب أن يكون حقل :attribute موجودًا ما لم يكن :other هو :value.',
    'present_with' => 'يجب أن يكون حقل :attribute موجودًا عند وجود :values.',
    'present_with_all' => 'يجب أن يكون حقل :attribute موجودًا عند وجود :values.',
    'prohibited' => 'حقل :attribute غير مسموح به.',
    'prohibited_if' => 'حقل :attribute غير مسموح به عندما يكون :other هو :value.',
    'prohibited_unless' => 'حقل :attribute غير مسموح به ما لم يكن :other ضمن :values.',
    'prohibits' => 'حقل :attribute يمنع وجود :other.',
    'regex' => 'صيغة :attribute غير صحيحة.',
    'required' => 'حقل :attribute مطلوب.',
    'required_array_keys' => 'يجب أن يحتوي :attribute على مدخلات لـ: :values.',
    'required_if' => 'حقل :attribute مطلوب عندما يكون :other هو :value.',
    'required_if_accepted' => 'حقل :attribute مطلوب عند قبول :other.',
    'required_if_declined' => 'حقل :attribute مطلوب عند رفض :other.',
    'required_unless' => 'حقل :attribute مطلوب ما لم يكن :other ضمن :values.',
    'required_with' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_with_all' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_without' => 'حقل :attribute مطلوب عند عدم وجود :values.',
    'required_without_all' => 'حقل :attribute مطلوب عند عدم وجود أي من :values.',
    'same' => 'يجب أن يتطابق :attribute مع :other.',
    'size' => [
        'array' => 'يجب أن يحتوي :attribute على :size عنصرًا.',
        'file' => 'يجب أن يكون حجم :attribute :size كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute :size.',
        'string' => 'يجب أن يكون طول :attribute :size حرفًا.',
    ],
    'starts_with' => 'يجب أن يبدأ :attribute بأحد القيم التالية: :values.',
    'string' => 'يجب أن يكون :attribute نصًا.',
    'timezone' => 'يجب أن يكون :attribute منطقة زمنية صحيحة.',
    'unique' => 'قيمة :attribute مستخدمة من قبل.',
    'uploaded' => 'فشل رفع :attribute.',
    'uppercase' => 'يجب أن يكون :attribute بحروف كبيرة.',
    'url' => 'يجب أن يكون :attribute رابطًا صحيحًا.',
    'ulid' => 'يجب أن يكون :attribute معرّف ULID صحيحًا.',
    'uuid' => 'يجب أن يكون :attribute معرّف UUID صحيحًا.',

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'رسالة مخصصة',
        ],
    ],

    /*
     * Field names, in Arabic.
     *
     * Without these a message reads «حقل email مطلوب» — half translated, which is more jarring than
     * not translating it at all. Only fields that actually appear on a customer-facing form are
     * listed; an internal one is better left as its own name than given an invented Arabic label.
     */
    /*
     * PHONE-001 — the message names BOTH accepted shapes on purpose.
     *
     * «Enter a valid number» tells somebody staring at a number they believe is valid
     * nothing at all. Showing the two forms this product accepts is the difference between
     * an error they can act on and one they can only retype.
     *
     * The EXAMPLES are in Latin digits, like every other number in this product — `ApiLanguageTest`
     * enforces it. A message showing «٠٥٠…» would also be teaching the customer a form the input can
     * read but no other screen will ever show back to them.
     */
    'phone_number' => 'أدخل رقم جوال صحيح، مثل 0501234567 أو ‎+966501234567.',
    'phone_taken' => 'هذا الرقم مستخدم في حساب آخر بالفعل.',

    /*
     * PROJECT-CREATE-WORKSPACE-001 — the agency is asked which client, and told so.
     *
     * The alternative the product used to take was to answer for them by picking whichever client
     * came back first, which is how one client's project ends up in another client's portal. A
     * question is the correct outcome here, so the message has to read as one.
     */
    'client_workspace_required' => 'اختر مساحة العميل التي ينتمي إليها هذا المشروع.',

    /*
     * RUNTIME-100 §10 — a selection can go stale between rendering and confirming.
     *
     * The wizard may have been open for an hour. An account can be detached, a connection revoked, or
     * a page of results can simply no longer describe what the provider returns. Saying so is more
     * useful than a bare «غير صحيح», because the action is to reload the list rather than to retype.
     */
    'selection_empty' => 'اختر حسابًا واحدًا على الأقل قبل التأكيد.',
    'selection_stale' => 'بعض الحسابات المختارة لم تعد متاحة في هذا الربط. حدّث القائمة ثم أعد الاختيار.',
    'connection_not_authorized' => 'هذا الربط لم يعد مُصرّحًا به. أعد الربط ثم حاول مرة أخرى.',

    'attributes' => [
        'address' => 'العنوان',
        'amount' => 'المبلغ',
        'billing_interval' => 'دورة الفوترة',
        'city' => 'المدينة',
        'code' => 'الرمز',
        'company' => 'المنشأة',
        'company_name' => 'اسم المنشأة',
        'confirm_password' => 'تأكيد كلمة المرور',
        'content' => 'المحتوى',
        'country' => 'الدولة',
        'currency' => 'العملة',
        'current_password' => 'كلمة المرور الحالية',
        'date' => 'التاريخ',
        'description' => 'الوصف',
        'email' => 'البريد الإلكتروني',
        'end_date' => 'تاريخ الانتهاء',
        'file' => 'الملف',
        'first_name' => 'الاسم الأول',
        'full_name' => 'الاسم الكامل',
        'last_name' => 'اسم العائلة',
        'message' => 'الرسالة',
        'mobile' => 'رقم الجوال',
        'name' => 'الاسم',
        'notes' => 'الملاحظات',
        'objective' => 'الهدف',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'phone' => 'رقم الهاتف',
        'plan' => 'الباقة',
        'plan_code' => 'الباقة',
        'portal' => 'البوابة',
        'price' => 'السعر',
        'provider' => 'مزوّد الدفع',
        'reason' => 'السبب',
        'role' => 'الدور',
        'service' => 'الخدمة',
        'start_date' => 'تاريخ البداية',
        'status' => 'الحالة',
        'subject' => 'الموضوع',
        'tax_number' => 'الرقم الضريبي',
        'tenant_name' => 'اسم المنشأة',
        'title' => 'العنوان',
        'website' => 'الموقع الإلكتروني',
    ],
];
