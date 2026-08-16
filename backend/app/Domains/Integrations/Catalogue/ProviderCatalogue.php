<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Catalogue;

use App\Support\AdPlatforms;
use InvalidArgumentException;

/**
 * PROVCFG-001 — the eight providers this product integrates with, each described as itself.
 *
 * Order is the product's own (`AdPlatforms::ORDER`) for the six advertising platforms, then the two
 * commerce platforms. Every entry below states what the PROVIDER requires, not what would be
 * convenient for a shared form:
 *
 * | Provider  | The difference that would break a generic model                                    |
 * |-----------|------------------------------------------------------------------------------------|
 * | Snapchat  | ad accounts hang off an ORGANISATION; a valid token with no org id lists nothing    |
 * | TikTok    | `app_id`/`secret`/`auth_code`, and HTTP 200 with `code != 0` is a refusal           |
 * | Meta      | no refresh token — a long-lived EXCHANGE; and webhooks with `X-Hub-Signature-256`   |
 * | Google    | a developer token approved on a separate track; refresh only with offline+consent   |
 * | X         | PKCE is mandatory, so `code_verifier` must survive the whole round trip             |
 * | LinkedIn  | every REST call is pinned to a monthly `LinkedIn-Version`; unpinned calls are 426   |
 * | Salla     | a store, not an ad account; webhooks signed with `x-salla-signature`                |
 * | Zid       | TWO tokens — `Authorization: Bearer` AND `X-Manager-Token` on every call            |
 *
 * ## What this file is not
 *
 * It is not a claim that any of this has been exercised. No install in this repository holds keys for
 * any of the eight. These are the published requirements the adapters were written against; the state
 * of any given install is answered by `ProviderConfigurationService`, never by the presence of a row
 * here. See `docs/AD_PLATFORM_INTEGRATIONS_AUDIT.md`.
 */
final class ProviderCatalogue
{
    /** @var array<string, ProviderDefinition>|null */
    private static ?array $definitions = null;

    /** @return array<string, ProviderDefinition> keyed by provider, in the product's order */
    public static function all(): array
    {
        return self::$definitions ??= self::build();
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $provider): bool
    {
        return isset(self::all()[AdPlatforms::canonical($provider)]);
    }

    /** @throws InvalidArgumentException when the key names no provider this product integrates with */
    public static function get(string $provider): ProviderDefinition
    {
        $key = AdPlatforms::canonical($provider);

        return self::all()[$key] ?? throw new InvalidArgumentException("Unknown integration provider '{$provider}'.");
    }

    /** @return list<ProviderDefinition> */
    public static function ofKind(ProviderKind $kind): array
    {
        return array_values(array_filter(self::all(), static fn (ProviderDefinition $d) => $d->kind === $kind));
    }

    /** Test seams: definitions are memoised because they are pure, so a test that alters config resets them. */
    public static function flush(): void
    {
        self::$definitions = null;
    }

    /** @return array<string, ProviderDefinition> */
    private static function build(): array
    {
        $definitions = [
            self::snapchat(),
            self::tiktok(),
            self::meta(),
            self::google(),
            self::x(),
            self::linkedin(),
            self::salla(),
            self::zid(),
        ];

        $keyed = [];

        foreach ($definitions as $definition) {
            $keyed[$definition->key] = $definition;
        }

        return $keyed;
    }

    private static function snapchat(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'snapchat',
            kind: ProviderKind::Advertising,
            label: 'Snapchat Marketing API',
            labelAr: 'واجهة سناب شات الإعلانية',
            fields: [
                ProviderField::plain('client_id', 'Client ID', 'معرّف التطبيق',
                    'Business Manager → Business Details → OAuth Apps',
                    'مدير الأعمال ← تفاصيل النشاط ← تطبيقات OAuth'),
                ProviderField::secret('client_secret', 'Client Secret', 'السر السرّي للتطبيق',
                    'Shown once when the OAuth app is created; it cannot be read again',
                    'يُعرض مرة واحدة عند إنشاء التطبيق ولا يمكن قراءته لاحقًا'),
            ],
            scopes: ['snapchat-marketing-api'],
            usesPkce: false,
            supportsRefresh: true,
            // 3600 seconds, read from the current authentication documentation rather than from
            // memory — the previous «around 30 minutes» was half the real figure, and an operator
            // plans refresh windows, alerting and support answers around it (SNAP-TOKEN-001).
            tokenNote: 'Access tokens expire after 3600 seconds (60 minutes) and are renewed with the refresh token.',
            tokenNoteAr: 'ينتهي رمز الوصول بعد 3600 ثانية (60 دقيقة) ويُجدَّد تلقائيًا عبر رمز التجديد.',
            webhooks: WebhookSupport::PollingOnly,
            webhookSignatureHeader: null,
            prerequisites: [
                'A Snapchat Business Manager organisation that owns the ad accounts.',
                'An OAuth app created under that organisation, with the redirect URI below registered on it exactly.',
                'The member who authorises must already have access to the ad accounts — OAuth grants our app their access, never more.',
                'Nothing about a customer belongs here. Their organisation and ad accounts are discovered from their own token after they connect.',
            ],
            prerequisitesAr: [
                'مؤسسة في مدير أعمال سناب شات تملك الحسابات الإعلانية.',
                'تطبيق OAuth مُنشأ داخل هذه المؤسسة، مع تسجيل رابط العودة أدناه عليه حرفيًا.',
                'العضو الذي يمنح الموافقة يجب أن يملك صلاحية على الحسابات الإعلانية مسبقًا — الموافقة تمنحنا صلاحيته فقط ولا تزيد عليها.',
                'لا تُدخل هنا أي بيانات تخص عميلًا. تُكتشف مؤسسته وحساباته الإعلانية من رمزه هو بعد أن يربط حسابه.',
            ],
            docsUrl: 'https://developers.snap.com/api/marketing-api/Ads-API/introduction',
            rateLimitNote: 'Throttled with HTTP 429 and a Retry-After header; the shared retry policy honours it.',
            paginationNote: 'Cursor pagination through `paging.next_link`. Organisations and their ad accounts '
                .'come from `GET /me/organizations?with_ad_accounts=true`, scoped to the authorising member.',
            // Snapchat publishes no change-notification webhook for the objects we read, and this
            // install holds no keys to discover one behind a partner programme. The 30-minute poll is
            // the honest answer; an endpoint we would refuse every call to is worse than none.
        );
    }

    private static function tiktok(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'tiktok',
            kind: ProviderKind::Advertising,
            label: 'TikTok Marketing API',
            labelAr: 'واجهة تيك توك الإعلانية',
            fields: [
                ProviderField::plain('client_id', 'App ID', 'معرّف التطبيق (App ID)',
                    'TikTok for Business → Developers → your app. TikTok calls this the App ID, not a client id',
                    'تيك توك للأعمال ← المطورون ← تطبيقك. تيك توك تسميه App ID وليس client id'),
                ProviderField::secret('client_secret', 'App Secret', 'سر التطبيق (App Secret)',
                    'Same app page, shown as Secret',
                    'الصفحة نفسها، ويظهر باسم Secret'),
            ],
            // TikTok assigns scopes to the app at creation; they are not sent on the authorise URL.
            scopes: [],
            usesPkce: false,
            supportsRefresh: false,
            tokenNote: 'The business access token does not expire and has no refresh grant. It stops working '
                .'only when the advertiser revokes it, which is a re-authorisation, not a refresh.',
            tokenNoteAr: 'رمز الوصول للأعمال لا تنتهي صلاحيته ولا يوجد له منح تجديد. يتوقف فقط عند إلغاء المعلن للتفويض، '
                .'وهذا يتطلب إعادة ربط لا تجديدًا.',
            webhooks: WebhookSupport::PollingOnly,
            webhookSignatureHeader: null,
            prerequisites: [
                'A TikTok for Business developer account and an app created in the Marketing API portal.',
                'The app reviewed and approved for the permissions it asks for — scopes are attached to the app, not to the authorise URL.',
                'The redirect URI below registered on the app exactly.',
            ],
            prerequisitesAr: [
                'حساب مطوّر في تيك توك للأعمال وتطبيق مُنشأ في بوابة Marketing API.',
                'مراجعة التطبيق واعتماده للصلاحيات المطلوبة — الصلاحيات ترتبط بالتطبيق نفسه لا برابط الموافقة.',
                'تسجيل رابط العودة أدناه على التطبيق حرفيًا.',
            ],
            docsUrl: 'https://business-api.tiktok.com/portal/docs',
            rateLimitNote: 'Per-app QPS limits. A 200 response carrying a non-zero `code` is a REFUSAL, '
                .'not a success — the adapter reads the body, never the status alone.',
            paginationNote: 'Page-number pagination: `page` / `page_size`, with `page_info.total_page`.',
        );
    }

    private static function meta(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'meta',
            kind: ProviderKind::Advertising,
            label: 'Meta Marketing API',
            labelAr: 'واجهة ميتا الإعلانية',
            fields: [
                ProviderField::plain('client_id', 'App ID', 'معرّف التطبيق (App ID)',
                    'developers.facebook.com → your app → Settings → Basic',
                    'developers.facebook.com ← تطبيقك ← الإعدادات ← أساسي'),
                ProviderField::secret('client_secret', 'App Secret', 'سر التطبيق (App Secret)',
                    'Same page, revealed once after re-entering your password',
                    'الصفحة نفسها، ويُكشف بعد إعادة إدخال كلمة المرور'),
                ProviderField::secret('webhook_verify_token', 'Webhook verify token', 'رمز التحقق للـ Webhook',
                    'A value you choose. Meta echoes it back on the one-time GET that activates the subscription',
                    'قيمة تختارها أنت. تعيدها ميتا في طلب GET لمرة واحدة يُفعّل الاشتراك', required: false),
                ProviderField::secret('webhook_secret', 'Webhook app secret', 'سر توقيع الـ Webhook',
                    'Meta signs each delivery with the APP SECRET as `X-Hub-Signature-256`. '
                        .'Leave empty to sign-verify with the app secret above',
                    'توقّع ميتا كل إشعار بسر التطبيق في ترويسة X-Hub-Signature-256. اتركه فارغًا لاستخدام سر التطبيق أعلاه',
                    required: false),
            ],
            scopes: ['ads_read', 'ads_management', 'business_management'],
            usesPkce: false,
            supportsRefresh: false,
            tokenNote: 'Meta issues no refresh token. A short-lived token is EXCHANGED for a long-lived one '
                .'(`grant_type=fb_exchange_token`) against the same endpoint; treating that as a failed '
                .'refresh would mark every healthy connection broken.',
            tokenNoteAr: 'ميتا لا تصدر رمز تجديد. يُستبدل الرمز قصير العمر برمز طويل العمر عبر '
                .'`grant_type=fb_exchange_token` على المسار نفسه؛ واعتبار ذلك فشلًا في التجديد يجعل كل ربط سليم يظهر معطلًا.',
            webhooks: WebhookSupport::Supported,
            webhookSignatureHeader: 'X-Hub-Signature-256',
            prerequisites: [
                'A Meta app of type Business, added to the Business Manager that owns the ad accounts.',
                'Advanced Access for `ads_read` (and `ads_management` if campaigns are to be changed) — granted through App Review, not by ticking a box.',
                'Business Verification completed on that Business Manager.',
                'The redirect URI below added to Valid OAuth Redirect URIs.',
            ],
            prerequisitesAr: [
                'تطبيق ميتا من نوع Business، مُضاف إلى مدير الأعمال الذي يملك الحسابات الإعلانية.',
                'صلاحية Advanced Access للنطاق `ads_read` (و`ads_management` عند الحاجة لتعديل الحملات) — تُمنح عبر مراجعة التطبيق لا بتفعيل خيار.',
                'إكمال التحقق من النشاط التجاري (Business Verification) على مدير الأعمال.',
                'إضافة رابط العودة أدناه ضمن Valid OAuth Redirect URIs.',
            ],
            docsUrl: 'https://developers.facebook.com/docs/marketing-apis',
            rateLimitNote: 'Usage is reported in the `X-Business-Use-Case-Usage` header rather than by a 429; '
                .'the adapter backs off as it approaches the ceiling instead of waiting to be refused.',
            paginationNote: 'Cursor pagination through `paging.cursors.after`.',
        );
    }

    private static function google(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'google',
            kind: ProviderKind::Advertising,
            label: 'Google Ads API',
            labelAr: 'واجهة جوجل أدز',
            fields: [
                ProviderField::plain('client_id', 'OAuth client ID', 'معرّف عميل OAuth',
                    'Google Cloud Console → APIs & Services → Credentials → OAuth 2.0 Client IDs (type: Web application)',
                    'وحدة تحكم جوجل كلاود ← واجهات البرمجة والخدمات ← بيانات الاعتماد ← معرّفات OAuth 2.0 (النوع: تطبيق ويب)'),
                ProviderField::secret('client_secret', 'OAuth client secret', 'سر عميل OAuth',
                    'Same credential entry',
                    'المدخل نفسه في بيانات الاعتماد'),
                ProviderField::secret('developer_token', 'Developer token', 'رمز المطوّر',
                    'Google Ads manager account → Tools → API Center. Approved SEPARATELY from the OAuth '
                        .'client; without it every API call is refused even though sign-in succeeds',
                    'حساب مدير جوجل أدز ← الأدوات ← مركز الـ API. يُعتمد بشكل منفصل عن عميل OAuth؛ وبدونه تُرفض كل استدعاءات الـ API رغم نجاح تسجيل الدخول'),
                /*
                 * GADS-MCC-001 — there was a fourth field here: «معرّف الحساب المدير»
                 * (`login_customer_id`). It is gone, because it was never ours to hold.
                 *
                 * Google documents `login-customer-id` as the manager account through which the caller
                 * reaches THAT client account, so it belongs to each customer's own hierarchy. It is
                 * now discovered from `customer_client` and carried per account.
                 *
                 * Removing the field matters as much as removing the header. A field on this form is
                 * an instruction: leaving it after the value stopped being used would invite an
                 * operator to paste one customer's manager id into a platform-wide setting and believe
                 * they had configured something for everybody.
                 */
            ],
            scopes: ['https://www.googleapis.com/auth/adwords'],
            usesPkce: false,
            supportsRefresh: true,
            tokenNote: 'A refresh token is issued only when the authorise URL asks for `access_type=offline` '
                .'AND `prompt=consent`, and only on the FIRST consent. Google then omits the refresh token '
                .'from every subsequent refresh response, so the stored one must be kept.',
            tokenNoteAr: 'لا يُصدر رمز التجديد إلا عندما يطلب رابط الموافقة `access_type=offline` و`prompt=consent` معًا، '
                .'وفي أول موافقة فقط. ثم تُغفل جوجل رمز التجديد في كل استجابة تجديد لاحقة، لذا يجب الاحتفاظ بالمخزَّن.',
            webhooks: WebhookSupport::PollingOnly,
            webhookSignatureHeader: null,
            prerequisites: [
                'A Google Cloud project with the Google Ads API enabled and the OAuth consent screen published.',
                'A developer token approved for at least Basic Access on a Google Ads manager account — a test-access token only reaches test accounts.',
                'The redirect URI below added to the OAuth client\'s Authorised redirect URIs.',
            ],
            prerequisitesAr: [
                'مشروع في جوجل كلاود مع تفعيل Google Ads API ونشر شاشة موافقة OAuth.',
                'رمز مطوّر معتمد بمستوى Basic Access على الأقل على حساب مدير في جوجل أدز — رمز الاختبار يصل لحسابات الاختبار فقط.',
                'إضافة رابط العودة أدناه ضمن روابط العودة المصرّح بها لعميل OAuth.',
            ],
            docsUrl: 'https://developers.google.com/google-ads/api/docs/start',
            rateLimitNote: 'Operations are capped per developer token per day; exhaustion arrives as '
                .'`RESOURCE_EXHAUSTED` rather than an HTTP 429.',
            paginationNote: '`nextPageToken` on search / searchStream.',
        );
    }

    private static function x(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'x',
            kind: ProviderKind::Advertising,
            label: 'X Ads API',
            labelAr: 'واجهة إكس الإعلانية',
            fields: [
                ProviderField::plain('client_id', 'OAuth 2.0 Client ID', 'معرّف عميل OAuth 2.0',
                    'X developer portal → your project → app → Keys and tokens',
                    'بوابة مطوّري إكس ← مشروعك ← التطبيق ← المفاتيح والرموز'),
                ProviderField::secret('client_secret', 'OAuth 2.0 Client Secret', 'سر عميل OAuth 2.0',
                    'Same page, shown once at creation',
                    'الصفحة نفسها، ويُعرض مرة واحدة عند الإنشاء'),
            ],
            scopes: ['tweet.read', 'users.read', 'offline.access'],
            // The one provider here whose authorisation is refused outright without a code challenge.
            usesPkce: true,
            supportsRefresh: true,
            tokenNote: 'PKCE is mandatory: the authorise call carries a `code_challenge` and the token call '
                .'must present the matching `code_verifier`, so the verifier has to survive the whole '
                .'round trip. `offline.access` is what makes a refresh token available at all.',
            tokenNoteAr: 'استخدام PKCE إلزامي: يحمل رابط الموافقة `code_challenge` ويجب أن يقدّم طلب الرمز '
                .'قيمة `code_verifier` المطابقة، لذا يجب أن تبقى محفوظة طوال الرحلة. و`offline.access` هو ما يتيح رمز التجديد أصلًا.',
            webhooks: WebhookSupport::PollingOnly,
            webhookSignatureHeader: null,
            prerequisites: [
                'An X developer account with Ads API access approved — a separate application from the standard developer account.',
                'An app with OAuth 2.0 enabled (type: Web App) and the callback URI below registered.',
                'The authorising user must hold access to the ads account in X Ads Manager.',
            ],
            prerequisitesAr: [
                'حساب مطوّر في إكس مع اعتماد الوصول إلى Ads API — وهو طلب منفصل عن حساب المطوّر العادي.',
                'تطبيق مع تفعيل OAuth 2.0 (نوع: تطبيق ويب) وتسجيل رابط العودة أدناه.',
                'المستخدم الذي يمنح الموافقة يجب أن يملك صلاحية على الحساب الإعلاني في مدير إعلانات إكس.',
            ],
            docsUrl: 'https://developer.x.com/en/docs/x-ads-api',
            rateLimitNote: 'Fixed windows (commonly 15 minutes) per endpoint; a 429 carries the reset time.',
            paginationNote: 'Cursor pagination through `next_cursor`.',
        );
    }

    private static function linkedin(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'linkedin',
            kind: ProviderKind::Advertising,
            label: 'LinkedIn Marketing API',
            labelAr: 'واجهة لينكدإن الإعلانية',
            fields: [
                ProviderField::plain('client_id', 'Client ID', 'معرّف العميل',
                    'LinkedIn developer app → Auth tab',
                    'تطبيق مطوّري لينكدإن ← تبويب Auth'),
                ProviderField::secret('client_secret', 'Client Secret', 'سر العميل',
                    'Same tab',
                    'التبويب نفسه'),
                ProviderField::plain('version', 'API version', 'إصدار الواجهة',
                    'A supported month in YYYYMM form, sent as the `LinkedIn-Version` header. '
                        .'An unpinned call is rejected outright',
                    'شهر مدعوم بصيغة YYYYMM يُرسل في ترويسة `LinkedIn-Version`. الاستدعاء بلا إصدار مُثبّت يُرفض مباشرة'),
            ],
            scopes: ['r_ads', 'r_ads_reporting'],
            usesPkce: false,
            supportsRefresh: true,
            tokenNote: 'Refresh tokens are available only to apps approved for them; otherwise the access '
                .'token simply expires (around 60 days) and the advertiser must authorise again.',
            tokenNoteAr: 'رموز التجديد متاحة فقط للتطبيقات المعتمدة لها؛ وإلا تنتهي صلاحية رمز الوصول (نحو 60 يومًا) '
                .'ويجب على المعلن منح الموافقة من جديد.',
            webhooks: WebhookSupport::PollingOnly,
            webhookSignatureHeader: null,
            prerequisites: [
                'A LinkedIn developer app linked to the Company Page that owns the ad accounts.',
                'Advertising API access approved through the Marketing Developer Platform — the default app has none.',
                'The redirect URL below added under Authorized redirect URLs.',
            ],
            prerequisitesAr: [
                'تطبيق مطوّر في لينكدإن مرتبط بصفحة الشركة التي تملك الحسابات الإعلانية.',
                'اعتماد الوصول إلى Advertising API عبر Marketing Developer Platform — التطبيق الافتراضي لا يملكه.',
                'إضافة رابط العودة أدناه ضمن Authorized redirect URLs.',
            ],
            docsUrl: 'https://learn.microsoft.com/en-us/linkedin/marketing/',
            rateLimitNote: 'Daily application and member throttles, refused with HTTP 429.',
            paginationNote: '`start` / `count` on classic endpoints; `pageToken` on the newer ones.',
        );
    }

    private static function salla(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'salla',
            kind: ProviderKind::Commerce,
            label: 'Salla',
            labelAr: 'سلة',
            fields: [
                ProviderField::plain('client_id', 'Client ID', 'معرّف العميل',
                    'Salla Partners → your app → OAuth keys',
                    'شركاء سلة ← تطبيقك ← مفاتيح OAuth'),
                ProviderField::secret('client_secret', 'Client Secret', 'سر العميل',
                    'Same page',
                    'الصفحة نفسها'),
                ProviderField::secret('webhook_secret', 'Webhook secret', 'سر الـ Webhook',
                    'Salla Partners → your app → Webhooks. Every delivery is signed with it',
                    'شركاء سلة ← تطبيقك ← Webhooks. كل إشعار يُوقَّع بهذا السر', required: false),
            ],
            scopes: ['offline_access'],
            usesPkce: false,
            supportsRefresh: true,
            tokenNote: 'The app must be created in OAuth 2.0 mode, not Easy Mode: Easy Mode issues a token '
                .'per store with no authorisation round trip, which cannot express "this merchant '
                .'authorised this workspace".',
            tokenNoteAr: 'يجب إنشاء التطبيق بنمط OAuth 2.0 لا بالنمط السهل (Easy Mode): النمط السهل يصدر رمزًا لكل متجر '
                .'دون رحلة موافقة، وهو ما لا يمكنه التعبير عن «هذا التاجر فوّض مساحة العمل هذه».',
            webhooks: WebhookSupport::Supported,
            webhookSignatureHeader: 'x-salla-signature',
            prerequisites: [
                'A Salla Partners account and an app created in OAuth 2.0 mode.',
                'The app\'s scopes selected to cover orders, products, customers and abandoned carts — a scope not asked for at install cannot be added later without re-authorisation.',
                'The redirect URI below registered on the app, and the webhook URL below subscribed to the events the funnel needs.',
            ],
            prerequisitesAr: [
                'حساب في شركاء سلة وتطبيق مُنشأ بنمط OAuth 2.0.',
                'اختيار صلاحيات التطبيق لتغطية الطلبات والمنتجات والعملاء والسلات المتروكة — الصلاحية غير المطلوبة عند التثبيت لا تُضاف لاحقًا إلا بإعادة تفويض.',
                'تسجيل رابط العودة أدناه على التطبيق، والاشتراك برابط الـ Webhook أدناه للأحداث التي يحتاجها الفانل.',
            ],
            docsUrl: 'https://docs.salla.dev/',
            rateLimitNote: 'Per-app throttling refused with HTTP 429.',
            paginationNote: '`pagination.currentPage` / `pagination.totalPages`.',
        );
    }

    private static function zid(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'zid',
            kind: ProviderKind::Commerce,
            label: 'Zid',
            labelAr: 'زد',
            fields: [
                ProviderField::plain('client_id', 'Client ID', 'معرّف العميل',
                    'Zid partners dashboard → your app',
                    'لوحة شركاء زد ← تطبيقك'),
                ProviderField::secret('client_secret', 'Client Secret', 'سر العميل',
                    'Same page',
                    'الصفحة نفسها'),
                /*
                 * ZID-WEBHOOK-001 — a «Webhook secret … used to sign event deliveries» stood here,
                 * and Zid signs nothing. It sends `Authorization: Basic base64(username:password)`,
                 * using the pair given when the webhook subscription is created. Asking for a signing
                 * secret Zid never issues invites an operator to invent one and believe they have
                 * secured the endpoint.
                 */
                ProviderField::plain('webhook_username', 'Webhook username', 'اسم مستخدم الـ Webhook',
                    'The username given when the webhook subscription was created; Zid sends it back as HTTP Basic authentication on every delivery',
                    'اسم المستخدم المُعطى عند إنشاء اشتراك الـ Webhook؛ ترسله زد في كل حدث ضمن مصادقة Basic', required: false),
                ProviderField::secret('webhook_password', 'Webhook password', 'كلمة مرور الـ Webhook',
                    'The matching password. Zid publishes no signature scheme — this pair is the only way it identifies itself',
                    'كلمة المرور المقابلة. لا تنشر زد أي آلية توقيع؛ هذا الزوج هو الطريقة الوحيدة التي تُعرّف بها نفسها', required: false),
            ],
            scopes: [],
            usesPkce: false,
            supportsRefresh: true,
            tokenNote: 'Zid returns TWO values from the token exchange: an `access_token` for the '
                .'`Authorization: Bearer` header AND an `authorization` manager token for the '
                .'`X-Manager-Token` header. A call carrying only the first is refused, so both are stored.',
            tokenNoteAr: 'تُعيد زد قيمتين من تبادل الرمز: `access_token` لترويسة `Authorization: Bearer` '
                .'ورمز مدير `authorization` لترويسة `X-Manager-Token`. الاستدعاء بالأولى وحدها يُرفض، لذا تُخزَّن القيمتان.',
            webhooks: WebhookSupport::RequiresConfirmation,
            // Zid identifies itself with HTTP Basic, not a signature (ZID-WEBHOOK-001).
            webhookSignatureHeader: 'Authorization',
            prerequisites: [
                'A Zid partners account and an app with the redirect URI below registered.',
                'The app approved for the order, product and customer scopes it reads.',
                'The webhook subscription created against the URL below WITH a username and password — Zid sends those back as HTTP Basic authentication, and publishes no signature scheme. Deliveries are refused until the same pair is entered above.',
            ],
            prerequisitesAr: [
                'حساب في شركاء زد وتطبيق مسجَّل عليه رابط العودة أدناه.',
                'اعتماد التطبيق للصلاحيات التي يقرأها: الطلبات والمنتجات والعملاء.',
                'إنشاء اشتراك الـ Webhook على الرابط أدناه مع اسم مستخدم وكلمة مرور — تعيدهما زد في كل حدث ضمن مصادقة Basic، ولا تنشر أي آلية توقيع. تُرفض الأحداث حتى يُدخل الزوج نفسه أعلاه.',
            ],
            docsUrl: 'https://docs.zid.sa/',
            rateLimitNote: 'Per-app throttling refused with HTTP 429.',
            paginationNote: 'Page-number pagination with a total count.',
        );
    }
}
