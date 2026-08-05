<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Review;

/**
 * REVIEW-001 — what each platform actually demands before it will approve this application.
 *
 * ## Why there is no shared checklist
 *
 * Because the eight reviews are not variations of one another, and a generic list would be wrong in
 * both directions at once — omitting what a platform requires, and demanding what it does not.
 *
 *  - **Google** approves the OAuth consent screen and the developer token on SEPARATE tracks, and
 *    requires domain ownership verified in Search Console before either. A basic-access developer
 *    token is granted quickly and then rate-limits so hard the product is unusable, so «approved» has
 *    to distinguish basic from standard.
 *  - **Meta** wants Business Verification of the legal entity before advanced permissions, and reviews
 *    each permission individually with a screencast of it in use.
 *  - **TikTok** approves the app itself, and the sandbox works with a whitelist of advertiser ids —
 *    so an app can look connected in testing and reach nothing in production.
 *  - **Snapchat** issues credentials through an Organisation, and an otherwise-valid token lists no
 *    ad accounts without the organisation id.
 *  - **LinkedIn** grants API products individually; Advertising API access is a separate application
 *    with its own review, and a company page is required to make it.
 *  - **X** gates everything behind a paid project tier, and the tier decides which endpoints exist.
 *  - **Salla** requires a partner account and an app in OAuth 2.0 mode — the Easy Mode alternative
 *    issues per-store tokens with no consent journey, which cannot express «this merchant authorised
 *    this workspace».
 *  - **Zid** requires a partner app and issues a separate manager token alongside the OAuth token;
 *    an exchange arriving without it opens no connection.
 *
 * ## `source` is the important column
 *
 * `derived` items the system can answer itself — the redirect URI it will actually send, whether a
 * secret is present, which scopes the connector requests. Those are shown as facts and cannot be
 * ticked by hand, because a checklist an operator can tick without doing anything is a checklist that
 * lies. `declared` items happen inside the provider's own console, where this application has no
 * visibility at all, so the operator records them — and the record says who and when.
 */
final class ReviewCatalogue
{
    /** A requirement is either something we can determine, or something the operator tells us. */
    public const SOURCE_DERIVED = 'derived';

    public const SOURCE_DECLARED = 'declared';

    /**
     * Requirements every provider shares, because every OAuth review asks for them.
     *
     * These are genuinely common — a reviewer at any of the eight opens the privacy policy URL and
     * checks it loads without a login. Anything beyond this list is per-provider.
     *
     * @return list<array<string,string>>
     */
    private static function universal(): array
    {
        return [
            [
                'key' => 'homepage_url', 'source' => self::SOURCE_DERIVED,
                'ar' => 'رابط الصفحة الرئيسية', 'en' => 'Homepage URL',
                'why_ar' => 'يفتحه المراجع للتأكد أن المنتج حقيقي ويشرح ما يفعله.',
                'why_en' => 'The reviewer opens it to confirm the product is real and explains itself.',
            ],
            [
                'key' => 'privacy_url', 'source' => self::SOURCE_DERIVED,
                'ar' => 'رابط سياسة الخصوصية', 'en' => 'Privacy policy URL',
                'why_ar' => 'يجب أن يفتح دون تسجيل دخول وعلى النطاق نفسه، ويذكر البيانات التي تصل إليها ولماذا.',
                'why_en' => 'Must open without a login, on the same domain, and state what data is accessed and why.',
            ],
            [
                'key' => 'terms_url', 'source' => self::SOURCE_DERIVED,
                'ar' => 'رابط الشروط والأحكام', 'en' => 'Terms of service URL',
                'why_ar' => 'تطلبه كل منصة، ويجب أن يكون عامًا ومطابقًا للنطاق.',
                'why_en' => 'Required by every platform, and must be public and on the same domain.',
            ],
            [
                'key' => 'data_deletion_url', 'source' => self::SOURCE_DERIVED,
                'ar' => 'رابط حذف البيانات', 'en' => 'Data deletion URL',
                'why_ar' => 'صفحة تشرح كيف يطلب المستخدم حذف بياناته، وتقبل الطلب فعليًا.',
                'why_en' => 'A page explaining how a user asks for deletion — and that actually accepts the request.',
            ],
            [
                'key' => 'redirect_uri', 'source' => self::SOURCE_DERIVED,
                'ar' => 'رابط العودة (HTTPS، مطابق حرفيًا)', 'en' => 'Redirect URI (HTTPS, character-exact)',
                'why_ar' => 'أي اختلاف في حرف أو شرطة مائلة يُنهي الرحلة برفض من المنصة نفسها.',
                'why_en' => 'A single character or trailing slash difference ends the flow with a refusal from the platform.',
            ],
            [
                'key' => 'app_identity', 'source' => self::SOURCE_DECLARED,
                'ar' => 'تطابق اسم التطبيق وشعاره ونطاقه', 'en' => 'App name, logo and domain consistency',
                'why_ar' => 'اسم أو نطاق مختلف عن المعلن على الصفحة سبب رفض شائع ويقرأ كانتحال.',
                'why_en' => 'A name or domain differing from the published site is a common rejection and reads as impersonation.',
            ],
            [
                'key' => 'least_privilege', 'source' => self::SOURCE_DERIVED,
                'ar' => 'أقل صلاحية ممكنة', 'en' => 'Least privilege',
                'why_ar' => 'طلب صلاحية كتابة لا يستخدمها المنتج سبب رفض، ومخاطرة بلا مقابل.',
                'why_en' => 'Requesting a write scope the product never uses is a rejection reason and a risk for nothing.',
            ],
        ];
    }

    /**
     * What each provider demands on top of the universal set.
     *
     * @return array<string, list<array<string,string>>>
     */
    private static function specific(): array
    {
        return [
            'google' => [
                self::item('domain_verification', 'declared', 'التحقق من ملكية النطاق في Search Console', 'Domain ownership verified in Search Console',
                    'جوجل ترفض شاشة الموافقة قبل إثبات ملكية النطاق المستخدم في الروابط.',
                    'Google will not approve the consent screen until the domain in the URLs is verified.'),
                self::item('oauth_consent_screen', 'declared', 'مراجعة شاشة موافقة OAuth', 'OAuth consent screen review',
                    'تُراجَع بشكل منفصل عن الرمز المطوّر، ويجب أن تنشر النطاقات المطلوبة ومبرراتها.',
                    'Reviewed separately from the developer token, and must publish the scopes requested and why.'),
                self::item('developer_token_basic', 'declared', 'رمز مطوّر Google Ads — وصول أساسي', 'Google Ads developer token — basic access',
                    'بدونه تُرفض كل استدعاءات API مهما كان OAuth صحيحًا.',
                    'Without it every API call is refused, however correct the OAuth is.'),
                self::item('developer_token_standard', 'declared', 'ترقية الرمز إلى وصول قياسي', 'Developer token upgraded to standard access',
                    'الوصول الأساسي يخنق المعدل لدرجة تجعل المنتج غير صالح للاستخدام على حساب حقيقي.',
                    'Basic access throttles so hard the product is unusable against a real account.'),
                self::item('demo_reviewer_account', 'declared', 'حساب تجريبي للمراجع', 'Demo account for the reviewer',
                    'يطلب المراجع حسابًا يدخل به ليرى الصلاحيات مستخدمة فعلًا.',
                    'The reviewer asks for an account they can sign into and see the scopes in use.'),
            ],
            'meta' => [
                self::item('business_verification', 'declared', 'التحقق من نشاط الأعمال (Business Verification)', 'Business Verification of the legal entity',
                    'مطلوب قبل منح الصلاحيات المتقدمة، ويحتاج مستندات الكيان القانوني.',
                    'Required before advanced permissions, and needs the legal entity’s documents.'),
                self::item('app_review_permissions', 'declared', 'مراجعة كل صلاحية على حدة', 'Per-permission App Review',
                    'ميتا تراجع كل صلاحية منفردة مع تسجيل شاشة يظهر استخدامها داخل المنتج.',
                    'Meta reviews each permission individually with a screencast showing it used in the product.'),
                self::item('data_deletion_callback', 'declared', 'Data Deletion Callback مسجَّل', 'Data Deletion Callback registered',
                    'ميتا تطلب رابطًا يستقبل طلب الحذف برمجيًا، لا صفحة شرح فقط.',
                    'Meta requires an endpoint that receives deletion requests programmatically, not only a page.'),
                self::item('app_mode_live', 'declared', 'التطبيق في وضع Live لا Development', 'App switched to Live mode',
                    'في وضع التطوير لا يعمل إلا مع حسابات المطورين، فيبدو ناجحًا ولا يصل إلى عميل.',
                    'In development mode it works only for developer accounts — it looks fine and reaches no customer.'),
            ],
            'tiktok' => [
                self::item('app_review', 'declared', 'مراجعة تطبيق TikTok for Business', 'TikTok for Business app review',
                    'يُراجَع التطبيق نفسه قبل الخروج من وضع الاختبار.',
                    'The app itself is reviewed before it leaves sandbox.'),
                self::item('sandbox_whitelist', 'declared', 'قائمة المعلنين في وضع الاختبار', 'Sandbox advertiser whitelist',
                    'الاختبار يعمل على معرفات معلنين مدرجة فقط، فينجح داخليًا ويفشل مع أول عميل.',
                    'Sandbox works only for whitelisted advertiser ids — it passes internally and fails on the first customer.'),
                self::item('scope_justification', 'declared', 'تبرير كل نطاق مطلوب', 'Written justification for each scope',
                    'تيك توك تطلب سببًا مكتوبًا لكل نطاق، ونطاقًا غير مبرر يُرفض.',
                    'TikTok asks for a written reason per scope; an unjustified scope is refused.'),
            ],
            'snapchat' => [
                self::item('organisation_id', 'derived', 'معرّف المؤسسة (Organization ID)', 'Organization ID',
                    'الحسابات الإعلانية معلّقة على مؤسسة؛ رمز صحيح بلا معرّف مؤسسة لا يعرض أي حساب.',
                    'Ad accounts hang off an organisation; a valid token without the id lists nothing.'),
                self::item('app_submission', 'declared', 'تقديم التطبيق في Snap Business', 'App submitted in Snap Business',
                    'الوصول للإنتاج يمر بمراجعة منفصلة عن إنشاء التطبيق.',
                    'Production access goes through a review separate from creating the app.'),
                self::item('member_access', 'declared', 'صلاحية العضو على المؤسسة', 'Member access on the organisation',
                    'حساب المطوّر يجب أن يكون عضوًا في المؤسسة وإلا فلا يرى شيئًا.',
                    'The developer account must be a member of the organisation or it sees nothing.'),
            ],
            'linkedin' => [
                self::item('company_page', 'declared', 'صفحة شركة على LinkedIn', 'A LinkedIn company page',
                    'لا يمكن تقديم طلب منتج API دون صفحة شركة مرتبطة بالتطبيق.',
                    'An API product application cannot be made without a company page attached to the app.'),
                self::item('advertising_api_product', 'declared', 'طلب منتج Advertising API', 'Advertising API product request',
                    'الوصول الإعلاني منتج منفصل بمراجعة خاصة، ولا يأتي مع التطبيق الافتراضي.',
                    'Advertising access is a separate product with its own review; it does not come with the default app.'),
                self::item('api_version_header', 'derived', 'ترويسة إصدار API', 'API version header',
                    'لينكدإن ترفض الطلب بلا ترويسة إصدار صالحة.',
                    'LinkedIn refuses a request without a valid version header.'),
            ],
            'x' => [
                self::item('project_tier', 'declared', 'مستوى المشروع المدفوع', 'Paid project tier',
                    'المستوى يحدد أي نقاط نهاية موجودة أصلًا؛ المستوى المجاني لا يشمل واجهة الإعلانات.',
                    'The tier decides which endpoints exist at all; the free tier does not include the Ads API.'),
                self::item('ads_api_access', 'declared', 'الوصول إلى X Ads API', 'X Ads API access',
                    'وصول منفصل عن مفاتيح المشروع العادية ويحتاج موافقة.',
                    'Separate from ordinary project keys and requires approval.'),
                self::item('callback_allowlist', 'declared', 'إدراج رابط العودة في قائمة السماح', 'Callback URL allow-listed',
                    'X تطابق الرابط حرفيًا وترفض أي اختلاف.',
                    'X matches the URL exactly and refuses any difference.'),
            ],
            'salla' => [
                self::item('partner_account', 'declared', 'حساب في شركاء سلة', 'Salla Partners account',
                    'التطبيقات تُنشأ من بوابة الشركاء لا من لوحة المتجر.',
                    'Apps are created in the partners portal, not in a store dashboard.'),
                self::item('oauth_mode_not_easy', 'declared', 'نمط OAuth 2.0 لا النمط السهل', 'OAuth 2.0 mode, not Easy Mode',
                    'النمط السهل يصدر رمزًا لكل متجر بلا رحلة موافقة، ولا يمكنه التعبير عن «هذا التاجر فوّض هذه المساحة».',
                    'Easy Mode issues a per-store token with no consent journey and cannot express «this merchant authorised this workspace».'),
                self::item('webhook_subscription', 'declared', 'الاشتراك بأحداث Webhook', 'Webhook events subscribed',
                    'الفانل يحتاج أحداث الطلبات؛ بلا اشتراك تصل البيانات بالمزامنة الدورية فقط.',
                    'The funnel needs order events; without a subscription data arrives only on the periodic sweep.'),
                self::item('app_approval', 'declared', 'اعتماد التطبيق للنشر', 'App approved for release',
                    'التطبيق غير المعتمد يعمل لحساب المطوّر فقط.',
                    'An unapproved app works only for the developer’s own account.'),
            ],
            'zid' => [
                self::item('partner_account', 'declared', 'حساب في شركاء زد', 'Zid Partners account',
                    'التطبيق يُنشأ من بوابة الشركاء ويُراجَع قبل النشر.',
                    'The app is created in the partners portal and reviewed before release.'),
                self::item('manager_token', 'derived', 'رمز المدير (X-Manager-Token)', 'Manager token (X-Manager-Token)',
                    'زد تصدر رمزًا إضافيًا مع رمز OAuth؛ تبادل يصل بدونه لا يفتح أي ربط.',
                    'Zid issues an extra token alongside the OAuth one; an exchange arriving without it opens no connection.'),
                self::item('cart_endpoint_absent', 'derived', 'لا توجد نقطة سلات متروكة', 'No abandoned-cart endpoint',
                    'زد لا تنشر هذه النقطة، والموصّل يرفض بدل إعادة قائمة فارغة — تُقرأ الجولة partial.',
                    'Zid publishes no such endpoint; the connector refuses rather than returning an empty list, and the run reads partial.'),
                self::item('app_approval', 'declared', 'اعتماد التطبيق', 'App approved',
                    'قبل الاعتماد لا يعمل التطبيق إلا على متجر المطوّر.',
                    'Before approval the app works only against the developer’s own store.'),
            ],
        ];
    }

    /** @return array<string,string> */
    private static function item(string $key, string $source, string $ar, string $en, string $whyAr, string $whyEn): array
    {
        return ['key' => $key, 'source' => $source, 'ar' => $ar, 'en' => $en, 'why_ar' => $whyAr, 'why_en' => $whyEn];
    }

    /**
     * The full checklist for one provider: what everybody needs, then what this one needs.
     *
     * @return list<array<string,string>>
     */
    public static function for(string $provider): array
    {
        return [...self::universal(), ...(self::specific()[$provider] ?? [])];
    }

    public static function has(string $provider): bool
    {
        return array_key_exists($provider, self::specific());
    }
}
