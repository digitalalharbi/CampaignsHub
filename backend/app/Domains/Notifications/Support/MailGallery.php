<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

use App\Domains\Alerts\Services\AlertEventMailer;
use App\Domains\Notifications\Mail\AlertBundleMail;
use App\Domains\Notifications\Mail\CredentialMail;
use App\Domains\Notifications\Mail\DailyDigestMail;
use App\Domains\Notifications\Mail\InvitationMail;
use App\Domains\Notifications\Mail\OperationalDigestMail;
use App\Domains\Notifications\Mail\OperationalMail;
use App\Domains\Notifications\Mail\ScheduledReportMail;
use App\Domains\Notifications\Mail\SecurityAlertMail;
use App\Support\Frontend;
use Illuminate\Mail\Mailable;

/**
 * Every email this product can send, as a fixture — MAIL-008, moved here by MAIL-014.
 *
 * ## Why it moved out of the console command
 *
 * `notifications:preview` writes these to files; the operator console renders them in a page. Two
 * callers, and the obvious way to build the second is to copy the fixtures — after which the gallery
 * an operator opens and the files a developer renders slowly stop being the same product. One
 * definition, two readers.
 *
 * ## The fixtures are shaped like real data, and say so
 *
 * The digest below is a hand-built block rather than a database read, because a preview must render
 * the same way on an empty machine. Where the shape matters — a metric nobody reported, a funnel
 * stage that is absent rather than zero — the fixture carries the awkward case rather than the tidy
 * one, since the tidy one is not what needs reviewing.
 */
final class MailGallery
{
    /** Opens nothing: it has no valid signature. Only so a preview shows the footer as an optional message carries it. */
    private const SAMPLE_UNSUBSCRIBE = 'https://api.campaignshub.io/mail/unsubscribe/sample.0000000000';

    /** @return list<string> the keys, in the order the gallery should show them */
    public static function keys(): array
    {
        return array_keys(self::messages('ar'));
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::messages('ar'));
    }

    /** Rendered HTML for one message, or null when nothing goes by that name. */
    public static function render(string $key, string $locale): ?string
    {
        $messages = self::messages($locale);

        return isset($messages[$key]) ? $messages[$key]->render() : null;
    }

    /**
     * A template as a test message, or the plain mail check when no key is given. Null when nothing goes
     * by that name. Fixtures only: there is no other source of data in this class, and
     * `MailTestSendTest` proves that none of these asks the database for anything.
     */
    public static function testMessage(?string $key, string $locale): ?Mailable
    {
        $mail = $key === null ? self::plainCheck($locale) : (self::messages($locale)[$key] ?? null);

        return $mail === null ? null : TestSendMark::apply($mail, $locale);
    }

    private static function plainCheck(string $locale): OperationalMail
    {
        $ar = $locale === 'ar';

        return new OperationalMail(
            kind: 'message', severity: 'info',
            title: $ar ? 'فحص إرسال البريد' : 'Mail sending check',
            detail: $ar ? 'طلب مشرف المنصة فحص إرسال البريد.' : 'A platform admin asked to check outgoing mail.',
            context: $ar ? 'كامبينز هب' : 'CampaignsHub',
            lang: $locale,
            // «You follow this project» would be false here, and this message is about the mail itself.
            why: $ar ? 'لا تحتاج إلى أي إجراء.' : 'No action needed.',
        );
    }

    /** @return array<string, Mailable> */
    public static function messages(string $locale): array
    {
        $ar = $locale === 'ar';

        return self::accountMessages($locale) + [
            'digest-daily' => new DailyDigestMail(self::digest(), $locale, $ar ? 'محمد' : 'Mohammed', 'daily'),
            'digest-weekly' => new DailyDigestMail(self::digest(weekly: true), $locale, $ar ? 'محمد' : 'Mohammed', 'weekly'),
            'digest-monthly' => new DailyDigestMail(self::digest(weekly: true), $locale, $ar ? 'محمد' : 'Mohammed', 'monthly'),
            // EMAIL-IDENTITY-001 — a white-label digest: the agency is named, the platform still says who sent it.
            'digest-agency-branded' => new DailyDigestMail(self::digest(), $locale, $ar ? 'محمد' : 'Mohammed', 'daily', $ar ? 'وكالة المدى' : 'Almada Agency'),
            // MEMBER-VISIBILITY-001 — the agency member whose membership withholds the money: no spend, cost or spend chart, the agency's own intro and button.
            'digest-restricted-daily' => new DailyDigestMail(self::digest(), $locale, $ar ? 'ليان' : 'Layan', 'daily', null, 'restricted'),
            // EMAIL-DECISION-REPORT-001 — the CLIENT weekly: no spend anywhere, outcomes first, the portal behind the button.
            'digest-client-weekly' => new DailyDigestMail(self::digest(weekly: true), $locale, $ar ? 'سارة' : 'Sarah', 'weekly', null, 'client'),
            // EMAIL-PERF-DASHBOARDS-001 — the single-project variant: the project leads, no roll-up, no ranking of one.
            'digest-single-project' => new DailyDigestMail(array_replace(self::digest(), [
                'projects' => array_slice(self::digest()['projects'], 0, 1),
                'totals' => array_replace(self::digest()['totals'], ['projects' => 1]),
            ]), $locale, $ar ? 'محمد' : 'Mohammed', 'daily'),

            // EMAIL-DIGESTS-OPERATIONAL-001 — the operational digests: one project with a block per platform, and the portfolio of all of them.
            'digest-project' => new OperationalDigestMail(OperationalDigestModel::project(self::operationalDigest()['projects'][0], $locale, 7), $locale, $ar ? 'محمد' : 'Mohammed', 'weekly', 'project'),
            'digest-project-leads' => new OperationalDigestMail(OperationalDigestModel::project(self::operationalDigest()['projects'][1], $locale, 7), $locale, $ar ? 'محمد' : 'Mohammed', 'daily', 'project'),
            'digest-portfolio' => new OperationalDigestMail(OperationalDigestModel::portfolio(self::operationalDigest(), $locale, 'weekly'), $locale, $ar ? 'محمد' : 'Mohammed', 'weekly', 'portfolio'),
            // DIGESTS-CADENCE-BUDGETS-001 — the three rhythms of each: a project's monthly report, and «ملخص المشاريع» daily and monthly over thirty projects.
            'digest-project-monthly' => new OperationalDigestMail(OperationalDigestModel::project(DigestDemo::payload(2, 4, monthly: true)['projects'][1], $locale, 31, 'monthly'), $locale, $ar ? 'محمد' : 'Mohammed', 'monthly', 'project', AlertEventText::ltr('2026-10-01').' – '.AlertEventText::ltr('2026-10-31')),
            'digest-projects-daily' => new OperationalDigestMail(OperationalDigestModel::portfolio(DigestDemo::payload(30, 6), $locale, 'daily'), $locale, $ar ? 'محمد' : 'Mohammed', 'daily', 'portfolio', AlertEventText::ltr('2026-11-03').' – '.AlertEventText::ltr('2026-11-09')),
            'digest-projects-weekly' => new OperationalDigestMail(OperationalDigestModel::portfolio(DigestDemo::payload(30, 6, weekly: true), $locale, 'weekly'), $locale, $ar ? 'محمد' : 'Mohammed', 'weekly', 'portfolio', AlertEventText::ltr('2026-11-01').' – '.AlertEventText::ltr('2026-11-07')),
            'digest-projects-monthly' => new OperationalDigestMail(OperationalDigestModel::portfolio(DigestDemo::payload(30, 6, monthly: true), $locale, 'monthly'), $locale, $ar ? 'محمد' : 'Mohammed', 'monthly', 'portfolio', AlertEventText::ltr('2026-10-01').' – '.AlertEventText::ltr('2026-10-31')),
            // Every alert family, one card each, built by the real `AlertMailItem::build()` from an alert outbox item.
            ...self::alertTypes($locale),

            /*
             * The alerts as they are actually sent — MAIL-013.
             *
             * There were four separate `alert-*` previews here, one per finding, which is how the
             * product used to send them: four emails inside the same second. Rendering four separate
             * previews of a message that no longer exists would make this gallery a picture of last
             * month's product, and MAIL-014 puts it in front of an operator.
             */
            'alerts-bundle' => new AlertBundleMail(
                items: [
                    [
                        'severity' => 'critical',
                        'context' => $ar ? 'متجر تجريبي · ميتا · المبيعات' : 'Demo store · Meta · Sales',
                        'title' => $ar ? 'ميزانية ميتا قاربت النفاد' : 'The Meta budget is nearly used up',
                        'detail' => $ar ? 'استهلكت الحملة نسبة كبيرة من ميزانيتها قبل منتصف الفترة.' : 'The campaign has used a large share of its budget before the middle of the period.',
                        'numbers' => $ar ? 'الإنفاق 8,000.00 / 10,000.00 (80%)' : 'Spent 8,000.00 / 10,000.00 (80%)',
                        'rule' => 'budget_risk',
                        'ctx' => ['spend' => 8000.0, 'budget' => 10000.0, 'ratio' => 1.67, 'basis_class' => 'measured'],
                        // The figures as the pace rule writes them, read by the same reader the real mail uses.
                        'figures' => AlertFigures::from(['basis_class' => 'measured', 'spend' => 8000.0, 'budget' => 10000.0, 'ratio' => 1.67, 'budget_currency' => 'USD'], $ar),
                        'action' => AlertEventMailer::action('budget_risk', $ar),
                        'url' => Frontend::origin().'/app/campaigns/demo-project/demo-campaign',
                        'button' => $ar ? 'افتح الحملة داخل النظام' : 'Open the campaign',
                        'secondary' => ($ar ? 'الحملة: ' : 'Campaign: ').'Summer sale — retargeting 2026',
                    ],
                    [
                        'severity' => 'warning',
                        'context' => $ar ? 'متجر تجريبي · سناب شات · المبيعات' : 'Demo store · Snapchat · Sales',
                        'title' => $ar ? 'تكلفة النتيجة ارتفعت على سناب شات' : 'Cost per result rose on Snapchat',
                        'detail' => $ar ? 'الحملة تدفع أكثر مقابل كل نتيجة مقارنة بالفترة السابقة.' : 'The campaign pays more for each result than in the previous period.',
                        'numbers' => $ar ? 'قبل 61.00 ← الآن 88.72 (آخر 7 أيام) · حد التنبيه 25%' : 'Before 61.00 → Now 88.72 (last 7 days) · alert at 25%',
                        'action' => $ar ? 'راجع الجمهور ← ثم المحتوى ← ابدأ بالأقل أداءً.' : 'Review content and audience, starting with the weakest.',
                        'rule' => 'cpa_increase',
                        'ctx' => ['cpa_previous' => 61.0, 'cpa_current' => 88.72, 'threshold_pct' => 25],
                        'url' => Frontend::origin().'/app/campaigns/demo-project/demo-campaign-2',
                        'button' => $ar ? 'افتح الحملة داخل النظام' : 'Open the campaign',
                        'secondary' => ($ar ? 'الحملة: ' : 'Campaign: ').'Bundle carousel',
                    ],
                    [
                        'severity' => 'critical',
                        'context' => $ar ? 'عيادات النخبة' : 'Elite Clinics',
                        'title' => $ar ? 'توقفت مزامنة بيانات تيك توك' : 'TikTok data sync has stopped',
                        'detail' => $ar ? 'آخر محاولة فشلت، فأرقام التقارير قد تكون ناقصة.' : 'The last attempt failed, so report figures may be incomplete.',
                        'numbers' => $ar ? 'آخر أرقام بتاريخ '.AlertEventText::ltr('2026-08-04') : 'Last figures dated 2026-08-04',
                        'rule' => 'sync_failure',
                        'action' => AlertEventMailer::action('sync_failed', $ar),
                        'url' => Frontend::origin().'/app/projects/demo-project/integrations',
                        'button' => $ar ? 'افتح صفحة الربط' : 'Open the connections page',
                    ],
                ],
                lang: $locale, recipientName: $ar ? 'محمد' : 'Mohammed',
            ),

            // The single-finding case, which reads differently: the subject is the finding itself
            // rather than a count, and one card must not look like a list with an item missing.
            'alerts-one' => new AlertBundleMail(
                items: [[
                    'severity' => 'warning',
                    'context' => $ar ? 'متجر تجريبي · ميتا · المبيعات' : 'Demo store · Meta · Sales',
                    'title' => $ar ? 'العائد على الإنفاق تراجع على ميتا' : 'Return on ad spend fell on Meta',
                    'detail' => $ar ? 'كل ريال إعلان يعيد قيمة أقل من الفترة السابقة.' : 'Each unit of ad spend returns less than in the previous period.',
                    'numbers' => $ar ? 'قبل 4.56× ← الآن 3.10× (آخر 7 أيام) · حد التنبيه 25%' : 'Before 4.56× → Now 3.10× (last 7 days) · alert at 25%',
                    'action' => $ar ? 'افتح الأقل أداءً ← جدّده أو أوقفه.' : 'Review the weakest ads and decide to pause or refresh them.',
                    'rule' => 'roas_drop',
                    'ctx' => ['roas_previous' => 4.56, 'roas_current' => 3.10, 'threshold_pct' => 25],
                    'url' => Frontend::origin().'/app/campaigns/demo-project/demo-campaign',
                    'button' => $ar ? 'افتح الحملة داخل النظام' : 'Open the campaign',
                    'secondary' => ($ar ? 'الحملة: ' : 'Campaign: ').'White Friday — prospecting',
                ]],
                lang: $locale, recipientName: $ar ? 'محمد' : 'Mohammed',
            ),

            // DELIVERY-REMINDERS-001 — a reviewer's nudge, sent by `MemberNotices`.
            'delivery-reminder' => new OperationalMail(
                kind: 'delivery_review_due', severity: 'warning',
                title: $ar ? 'تقرير بانتظار مراجعتك' : 'A report is waiting for your review',
                detail: $ar ? 'مسودة تقرير متجر تجريبي للفترة 2026-07 جاهزة للمراجعة والاعتماد.' : 'The draft report for Demo store (2026-07) is ready for your review and approval.',
                context: $ar ? 'متجر تجريبي' : 'Demo store',
                lang: $locale, recipientName: $ar ? 'محمد' : 'Mohammed',
                path: '/agency/clients', action: $ar ? 'فتح التسليم الدوري' : 'Open the periodic delivery',
                why: $ar ? 'وصلتك لأنك مراجع هذا التسليم.' : 'Sent because you review this delivery.',
            ),

            'report-ready' => new OperationalMail(
                kind: 'report_ready', severity: 'positive',
                title: $ar ? 'تقرير يوليو جاهز' : 'The July report is ready',
                detail: $ar
                    ? 'تقرير الأداء الشهري جاهز للفتح أو المشاركة.'
                    : 'The monthly performance report is ready to open or share.',
                context: $ar ? 'متجر تجريبي' : 'Demo store',
                lang: $locale, recipientName: $ar ? 'محمد' : 'Mohammed',
                path: '/app/reports', action: $ar ? 'فتح التقرير' : 'Open the report',
            ),

            'billing' => new OperationalMail(
                kind: 'billing', severity: 'info',
                title: $ar ? 'فاتورة اشتراكك الجديدة' : 'Your new subscription invoice',
                detail: $ar
                    ? 'صدرت فاتورة اشتراك شهر أغسطس.'
                    : 'The August subscription invoice was issued.',
                context: $ar ? 'الاشتراك' : 'Subscription',
                lang: $locale, recipientName: $ar ? 'محمد' : 'Mohammed',
                path: '/app/billing', action: $ar ? 'عرض الفاتورة' : 'View the invoice',
            ),

            'approval' => new OperationalMail(
                kind: 'approval', severity: 'info',
                title: $ar ? 'طلب ينتظر موافقتك' : 'A request is waiting for your approval',
                detail: $ar
                    ? 'طلب إعلاني جديد ينتظر مراجعتك قبل التنفيذ.'
                    : 'A new advertising request needs your review before work starts.',
                context: $ar ? 'الطلبات' : 'Requests',
                lang: $locale, recipientName: $ar ? 'محمد' : 'Mohammed',
                path: '/app/requests', action: $ar ? 'مراجعة الطلب' : 'Review the request',
            ),

            'message' => new OperationalMail(
                kind: 'message', severity: 'info',
                title: $ar ? 'رسالة جديدة من العميل' : 'A new message from your client',
                detail: $ar
                    ? 'رسالة جديدة في محادثة المشروع تنتظر ردك.'
                    : 'A new project message is waiting for your reply.',
                context: $ar ? 'متجر تجريبي' : 'Demo store',
                lang: $locale, recipientName: $ar ? 'محمد' : 'Mohammed',
                path: '/app/conversations', action: $ar ? 'فتح المحادثة' : 'Open the conversation',
            ),

            // EMAIL-GAPS — a scheduled report as a decision report: results and content names, no spend, no campaign.
            'report-scheduled' => (new ScheduledReportMail(
                reportTitle: $ar ? 'تقرير الأداء الأسبوعي' : 'Weekly performance report',
                context: $ar ? 'متجر تجريبي' : 'Demo store',
                period: '2026-10-25 → 2026-10-31',
                cards: [
                    ['label' => $ar ? 'النتائج' : 'Results', 'value' => '263', 'unit' => null, 'up' => '+12%'],
                    ['label' => $ar ? 'الإيرادات' : 'Revenue', 'value' => '96,500', 'unit' => 'SAR', 'up' => '+5%'],
                    ['label' => $ar ? 'الوصول' : 'Reach', 'value' => '240,000', 'unit' => null, 'up' => null],
                    ['label' => $ar ? 'النقرات' : 'Clicks', 'value' => '15,619', 'unit' => null, 'up' => '+8.5%'],
                ],
                bestContent: $ar ? 'كاروسيل الباقة' : 'Bundle Carousel',
                improveContent: $ar ? 'عرض ثابت' : 'Static Offer',
                url: MailLinks::to('/app/reports'),
                lang: $locale, recipientName: $ar ? 'محمد' : 'Mohammed',
            ))->offerUnsubscribe(self::SAMPLE_UNSUBSCRIBE),

            // EMAIL-GAPS — the same message when no platform reported anything: no empty cells, only the way to the report.
            'report-scheduled-sparse' => new ScheduledReportMail(
                reportTitle: $ar ? 'تقرير الأداء الأسبوعي' : 'Weekly performance report',
                context: $ar ? 'متجر تجريبي' : 'Demo store',
                period: '2026-10-25 → 2026-10-31',
                cards: [], bestContent: null, improveContent: null,
                url: MailLinks::to('/app/reports'),
                lang: $locale, recipientName: $ar ? 'محمد' : 'Mohammed',
            ),

            // EMAIL-GAPS — a client request, to the team who can reach it.
            'request-to-team' => (new OperationalMail(
                kind: 'client_request', severity: 'info',
                title: $ar ? 'طلب جديد من عميل' : 'A new request from a client',
                detail: $ar
                    ? 'وصل الطلب REQ-2026-0142 من بوابة العملاء، وينتظر مراجعة فريقك.'
                    : 'Request REQ-2026-0142 arrived from the client portal and is waiting for your team.',
                context: $ar ? 'متجر تجريبي' : 'Demo store',
                lang: $locale, recipientName: $ar ? 'محمد' : 'Mohammed',
                path: '/agency/requests/01J9Z0PY8QF3T4V6', action: $ar ? 'مراجعة الطلب' : 'Review the request',
                why: $ar ? 'وصلتك لأن هذا الطلب ضمن نطاق عملك.' : 'Sent because this request is within your access.',
            ))->offerUnsubscribe(self::SAMPLE_UNSUBSCRIBE),

            // EMAIL-GAPS — a client request, back to the person who filed it.
            'request-to-client' => (new OperationalMail(
                kind: 'client_request', severity: 'warning',
                title: $ar ? 'طلبك يحتاج معلومات منك' : 'Your request needs information from you',
                detail: $ar ? 'يحتاج الفريق معلومات إضافية لإكمال طلبك.' : 'The team needs more information to complete your request.',
                context: 'REQ-2026-0142',
                lang: $locale, recipientName: $ar ? 'سارة' : 'Sarah',
                path: '/client/requests/REQ-2026-0142', action: $ar ? 'فتح الطلب' : 'Open the request',
                why: $ar ? 'وصلتك لأنك صاحب هذا الطلب.' : 'Sent because you filed this request.',
            ))->offerUnsubscribe(self::SAMPLE_UNSUBSCRIBE),

            // EMAIL-TRANSACTIONAL-SET-001 — the two messages a member is owed about their own access.
            'member-welcome' => new OperationalMail(
                kind: 'member_welcome', severity: 'positive',
                title: $ar ? 'أهلًا بك في وكالة العرض' : 'Welcome to Demo Agency',
                detail: $ar
                    ? 'انضممت بدور «محلل». تابع الحملات والتقارير من لوحة التحكم.'
                    : 'You joined as «Analyst». Follow campaigns and reports from the dashboard.',
                context: $ar ? 'وكالة العرض' : 'Demo Agency',
                lang: $locale, recipientName: $ar ? 'محمد' : 'Mohammed',
                path: '/app/dashboard', action: $ar ? 'فتح لوحة التحكم' : 'Open the dashboard',
                why: $ar ? 'وصلتك لأنك عضو في مساحة العمل هذه.' : 'Sent because you are a member of this workspace.',
            ),

            'role-changed' => new OperationalMail(
                kind: 'role_changed', severity: 'warning',
                title: $ar ? 'تغيّر دورك في مساحة العمل' : 'Your role in the workspace changed',
                detail: $ar
                    ? 'تغيّر دورك في وكالة العرض بواسطة سارة: من «محلل» إلى «مدير». إن لم تتوقعه، تواصل مع مسؤول الفريق.'
                    : 'Your role in Demo Agency was changed by Sara: from «Analyst» to «Manager». Not expected? Please contact a workspace administrator.',
                context: $ar ? 'وكالة العرض' : 'Demo Agency',
                lang: $locale, recipientName: $ar ? 'محمد' : 'Mohammed',
                path: '/app/dashboard', action: $ar ? 'فتح كامبينز هب' : 'Open CampaignsHub',
                why: $ar ? 'وصلتك لأنك عضو في مساحة العمل هذه.' : 'Sent because you are a member of this workspace.',
            ),
        ];
    }

    /**
     * The messages about somebody's ACCOUNT — MAIL-009.
     *
     * Kept in their own method because they are the ones nobody looks at until they are wrong. A
     * digest is read every morning and a broken one is reported by lunchtime; a password reset is read
     * once, by somebody already locked out, who has no way to tell anybody that the button did not
     * render. These are the templates that most need a person to open them on purpose.
     *
     * The example URLs carry realistic token lengths, because a 64-character token is what actually
     * breaks a 600px table — a preview with `?token=abc` proves the layout for a case that never
     * happens.
     *
     * @return array<string, Mailable>
     */
    private static function accountMessages(string $locale): array
    {
        $ar = $locale === 'ar';
        $app = MailLinks::app();
        $token = str_repeat('k7Qm3xZa', 8);

        return [
            'account-password-reset' => new CredentialMail(
                purpose: CredentialMail::PASSWORD_RESET, lang: $locale,
                url: "{$app}/reset-password?token={$token}&email=mohammed%40example.com",
                expiresInMinutes: 60,
            ),

            'account-email-verification' => new CredentialMail(
                purpose: CredentialMail::EMAIL_VERIFICATION, lang: $locale,
                url: "{$app}/signup/status?request=01J9Z0PY8QF3T4V6&token={$token}",
                expiresInMinutes: 1440,
            ),

            // The code path, which the two above do not exercise: a different block, a different
            // warning, and the one place `letter-spacing` and the tabular face have to hold.
            'account-sign-in-code' => new CredentialMail(
                purpose: CredentialMail::SIGN_IN_CODE, lang: $locale,
                code: '482913', expiresInMinutes: 15,
            ),

            'account-member-setup' => new CredentialMail(
                purpose: CredentialMail::MEMBER_SETUP, lang: $locale,
                url: "{$app}/reset-password?token={$token}&email=sara%40example.com",
                expiresInMinutes: 4320,
                workspace: $ar ? 'وكالة المدى' : 'Almada Agency',
            ),

            'account-oauth-link' => new CredentialMail(
                purpose: CredentialMail::OAUTH_LINK, lang: $locale,
                url: "{$app}/login?oauth=link&confirm={$token}",
                expiresInMinutes: 10,
                provider: 'Google',
            ),

            'account-invitation' => new InvitationMail(
                workspace: $ar ? 'وكالة المدى' : 'Almada Agency',
                roleSlug: 'manager',
                acceptUrl: "{$app}/invite/accept?token={$token}",
                lang: $locale,
                invitedBy: $ar ? 'محمد الحربي' : 'Mohammed Alharbi',
            ),

            // `admin/tenants/{tenant}/owner-invitation` — the platform operator inviting a workspace's first owner.
            // Sent with the slug the provisioner really passes, so the preview shows what the owner receives.
            'account-owner-invitation' => new InvitationMail(
                workspace: $ar ? 'وكالة المدى' : 'Almada Agency',
                roleSlug: 'tenant-owner',
                acceptUrl: "{$app}/invite/accept?token={$token}",
                lang: $locale,
            ),

            /*
             * An invitation to a role the map does not carry.
             *
             * Rendered on purpose: the name falls back to the slug and the DESCRIPTION disappears
             * entirely. A reviewer needs to see that the card does not collapse or leave a dangling
             * label when the product declines to describe somebody's access.
             */
            'account-invitation-unknown-role' => new InvitationMail(
                workspace: $ar ? 'وكالة المدى' : 'Almada Agency',
                roleSlug: 'campaign_ops',
                acceptUrl: "{$app}/invite/accept?token={$token}",
                lang: $locale,
            ),

            'account-security-sign-in' => new SecurityAlertMail(
                event: SecurityAlertMail::NEW_SIGN_IN, lang: $locale,
                recipientName: $ar ? 'محمد' : 'Mohammed',
                at: '2026-08-15 09:42',
                device: 'Chrome · macOS',
                location: $ar ? 'الرياض، السعودية' : 'Riyadh, Saudi Arabia',
                ip: '176.44.12.90',
            ),

            /*
             * The same message with everything unknown but the time.
             *
             * A facts table where three of four rows are absent is the case that reveals whether
             * omission was implemented or whether the rows quietly became «غير معروف».
             */
            'account-password-changed' => new SecurityAlertMail(
                event: SecurityAlertMail::PASSWORD_CHANGED, lang: $locale,
                recipientName: $ar ? 'محمد' : 'Mohammed', at: '2026-08-07 09:14',
            ),

            'account-security-sparse' => new SecurityAlertMail(
                event: SecurityAlertMail::PASSWORD_CHANGED, lang: $locale,
                recipientName: $ar ? 'محمد' : 'Mohammed',
                at: '2026-08-15 09:42',
            ),
        ];
    }

    /**
     * A day's figures, with the awkward cases in them on purpose.
     *
     * `reach` is reported and `video_views` is not, so a reviewer can see both a real number and the
     * «لم ترسله المنصة» state side by side — which is the pair most likely to regress, and the one
     * that cannot be judged from a tidy fixture.
     *
     * @return array<string,mixed>
     */
    public static function digest(bool $weekly = false): array
    {
        return [
            'sendable' => true,
            /*
             * `date` is the window's START, which is what `DailyDigest::build()` returns — so the weekly
             * preview spans a WEEK. It read «2026-08-06 → 2026-08-06»: a seven-day digest whose header
             * named one day, on the surface a human reviews these emails on.
             */
            'date' => $weekly ? '2026-07-31' : '2026-08-06',
            'to_date' => '2026-08-06',
            'days' => $weekly ? 7 : 1,
            'previous_date' => $weekly ? '2026-07-24' : '2026-08-05',
            /*
             * The account roll-up as `DailyDigest::rollUp()` actually returns it — with `previous` and
             * `change`, so the KPI cards carry their movement pills in the preview too. Without them the
             * one surface a human REVIEWS these emails on showed a flat account header that the real
             * email never sends, and a reviewer cannot notice a defect in something they are not shown.
             */
            'totals' => [
                /*
                 * DIGEST-PORTFOLIO-SCOPE-001 — the preview is a PORTFOLIO, because the product is.
                 *
                 * This fixture carried one project and a header reading «المشاريع 1», and it is the
                 * surface the owner reviews these emails on. `DigestScope` has always resolved every
                 * authorised project and `buildRange()` has always composed a block for each — but a
                 * preview that shows one teaches its reviewer that the product sends one, and that is
                 * what it taught.
                 *
                 * Three projects, deliberately unalike: a sales store, a lead-generation client and a
                 * brand campaign. The third is why the roll-up below carries no blended cost per
                 * result — an awareness project has no orders to divide the portfolio's spend by, and
                 * a preview showing one would be previewing an arithmetic accident.
                 */
                'spend' => 41833.18, 'conversions' => 401, 'revenue' => 96500.0, 'projects' => 3,
                // EMAIL-DECISION-REPORT-001 — the portfolio's daily shape and its spend by platform, for the charts.
                'series' => [
                    ['date' => '2026-07-31', 'spend' => 5210.0], ['date' => '2026-08-01', 'spend' => 6480.5], ['date' => '2026-08-02', 'spend' => 5930.0],
                    ['date' => '2026-08-03', 'spend' => 7102.2], ['date' => '2026-08-04', 'spend' => 5620.0], ['date' => '2026-08-05', 'spend' => 6390.48], ['date' => '2026-08-06', 'spend' => 5100.0],
                ],
                'platforms' => [['provider' => 'meta', 'spend' => 18240.0], ['provider' => 'snapchat', 'spend' => 12100.5], ['provider' => 'google', 'spend' => 7492.68], ['provider' => 'tiktok', 'spend' => 4000.0]],
                'previous' => ['spend' => 36600.0, 'conversions' => 448, 'revenue' => 92000.0],
                'change' => ['spend' => 0.143, 'conversions' => -0.1049, 'revenue' => 0.0489],
            ],
            'projects' => [[
                'project_id' => '00000000-0000-0000-0000-000000000001',
                'project_name' => 'متجر تجريبي — Demo',
                'objective' => 'sales',
                'metric_set' => ['spend', 'conversions', 'cpa', 'revenue'],
                'totals' => [
                    'spend' => 23333.18, 'conversions' => 263, 'cpa' => 88.72, 'revenue' => 96500.0,
                    'impressions' => 695281, 'clicks' => 15619, 'ctr' => 0.0225, 'reach' => 240000, 'video_views' => 0,
                ],
                'previous' => ['spend' => 21000.0, 'conversions' => 300, 'cpa' => 70.0, 'revenue' => 92000.0],
                'reported' => [
                    'spend' => true, 'conversions' => true, 'revenue' => true, 'impressions' => true,
                    'clicks' => true, 'reach' => true, 'video_views' => false,
                ],
                'change' => ['spend' => 0.11, 'conversions' => -0.12, 'cpa' => 0.27, 'revenue' => 0.05],
                /*
                 * KEYED by path, exactly as `DailyDigest::byPath()` returns it.
                 *
                 * Written as a list first, which made the template read `0` and `1` as path names —
                 * and every row rendered «الوعي», so the conversion path's 20,668 SAR appeared under
                 * the awareness label. A fixture that does not match the shape it stands in for
                 * tests the renderer against a world that does not exist.
                 */
                'paths' => [
                    'awareness' => ['spend' => 2003.5, 'campaigns' => 2, 'conversions' => 0, 'revenue' => 0, 'cost_per_result' => null, 'roas' => null],
                    'conversion' => ['spend' => 20668.58, 'campaigns' => 5, 'conversions' => 238, 'revenue' => 96500.0, 'cost_per_result' => 86.84, 'roas' => 4.67],
                ],
                'best_platform' => ['label' => 'snapchat', 'spend' => 3124.0, 'cpa' => 48.81],
                'worst_platform' => ['label' => 'google', 'spend' => 9119.63, 'cpa' => 140.3],
                'best_campaign' => null,
                'worst_campaign' => null,
                'budget' => [['campaign' => 'ميتا', 'provider' => 'meta', 'pace' => 1.67, 'spent' => 8000.0, 'budget' => 10000.0, 'direction' => 'ahead']],
                'funnel' => [
                    ['stage' => 'impressions', 'label' => 'Impressions', 'count' => 695281, 'step_rate' => null],
                    ['stage' => 'clicks', 'label' => 'Clicks', 'count' => 15619, 'step_rate' => 0.0225],
                    ['stage' => 'landing_page_views', 'label' => 'Landing Page View', 'count' => 13439, 'step_rate' => 0.86],
                    // Never sent by any connected platform — absent, not zero.
                    ['stage' => 'add_to_cart', 'label' => 'Add to Cart', 'count' => null, 'step_rate' => null],
                    ['stage' => 'purchases', 'label' => 'Purchase', 'count' => 263, 'step_rate' => 0.02],
                ],
                'creatives' => [
                    'best' => ['name' => 'Bundle Carousel', 'provider' => 'snapchat', 'reason' => 'أعلى عائد على الإنفاق (4.20×)'],
                    'declining' => [['name' => 'Static Offer', 'provider' => 'meta', 'reason' => null]],
                    'fatigued' => [['name' => 'Launch Video', 'provider' => 'tiktok', 'reason' => null]],
                ],
                'observations' => [
                    [
                        'id' => 'a', 'kind' => 'budget_pace', 'severity' => 'critical', 'reveals' => ['spend'],
                        'title' => 'الإنفاق على ميتا يستهلك الميزانية أسرع من الخطة',
                        'detail' => 'صُرف 8,000.00 SAR من أصل 10,000.00 SAR، أي 167% من الإنفاق المتوقع حتى الآن.',
                        'scope' => ['type' => 'platform', 'name' => 'ميتا'],
                    ],
                    [
                        'id' => 'b', 'kind' => 'rising_cost', 'severity' => 'warning', 'reveals' => ['spend'],
                        'title' => 'ارتفاع تكلفة النتيجة',
                        'detail' => 'ارتفعت تكلفة النتيجة 27% إلى 88.72 SAR، ربما بسبب منافسة أعلى أو محتوى تعب.',
                        'scope' => ['type' => 'period', 'name' => null],
                    ],
                ],
                'freshness' => ['state' => 'fresh', 'last_sync_at' => '2026-08-07T05:00:00Z', 'missing_days' => 0, 'sync_failed' => false, 'failing' => []],
            ], [
                /*
                 * A LEAD-GENERATION project, whose result is a lead and whose cost is a cost per lead.
                 *
                 * Second on purpose: the block above is a sales project, and a reviewer looking at two
                 * different objectives in one email is looking at the thing this product is for. A
                 * preview of three sales projects would prove nothing the first one did not.
                 */
                'project_id' => '00000000-0000-0000-0000-000000000002',
                'project_name' => 'عيادات النخبة — Leads',
                'objective' => 'leads',
                'metric_set' => ['spend', 'conversions', 'cpa'],
                'totals' => [
                    'spend' => 12500.0, 'conversions' => 138, 'cpa' => 90.58, 'revenue' => 0.0,
                    'impressions' => 402113, 'clicks' => 9044, 'ctr' => 0.0225, 'reach' => 160000, 'video_views' => 0,
                ],
                'previous' => ['spend' => 11600.0, 'conversions' => 148, 'cpa' => 78.38, 'revenue' => 0.0],
                // No revenue, and the email must say «لم تُرسله المنصة» rather than «0».
                'reported' => [
                    'spend' => true, 'conversions' => true, 'revenue' => false, 'impressions' => true,
                    'clicks' => true, 'reach' => true, 'video_views' => false,
                ],
                'change' => ['spend' => 0.078, 'conversions' => -0.068, 'cpa' => 0.156, 'revenue' => null],
                'paths' => [
                    'traffic' => ['spend' => 3100.0, 'campaigns' => 2, 'conversions' => 0, 'revenue' => 0, 'cost_per_result' => null, 'roas' => null],
                    'conversion' => ['spend' => 9400.0, 'campaigns' => 3, 'conversions' => 138, 'revenue' => 0, 'cost_per_result' => 68.12, 'roas' => null],
                ],
                'best_platform' => ['label' => 'meta', 'spend' => 6100.0, 'cpa' => 71.76],
                'worst_platform' => ['label' => 'tiktok', 'spend' => 2400.0, 'cpa' => 240.0],
                'best_campaign' => null,
                'worst_campaign' => null,
                'budget' => [['campaign' => 'تيك توك', 'provider' => 'tiktok', 'pace' => 0.44, 'spent' => 2400.0, 'budget' => 9000.0, 'direction' => 'behind']],
                'funnel' => [],
                'creatives' => null,
                'observations' => [
                    [
                        'id' => 'c', 'kind' => 'budget_pace', 'severity' => 'info', 'reveals' => ['spend'],
                        'title' => 'الإنفاق على تيك توك يستهلك الميزانية أبطأ من الخطة',
                        'detail' => 'صُرف 2,400.00 SAR من أصل 9,000.00 SAR، أي 44% من الإنفاق المتوقع حتى الآن.',
                        'scope' => ['type' => 'platform', 'name' => 'تيك توك'],
                    ],
                ],
                'follow_up' => ['received' => 138, 'contacted' => 96, 'overdue' => 11, 'converted' => 24],
                // GA4-DIGEST-001 — the one GA4 line: counts and USD, never spend and never a campaign name.
                'ga4' => ['sessions' => 12480.0, 'purchases' => 214.0, 'revenue_usd' => 31800.0, 'claimed' => 262.0, 'gap' => 48.0],
                'freshness' => ['state' => 'fresh', 'last_sync_at' => '2026-08-07T05:00:00Z', 'missing_days' => 0, 'sync_failed' => false, 'failing' => []],
            ], [
                /*
                 * A BRAND project — the one that proves the portfolio roll-up refuses a blended ratio.
                 *
                 * It has no orders and no revenue, because awareness money was never asked to produce
                 * any. Its cards are reach and cost per thousand, its cost per result is null rather
                 * than zero, and the account header above states no portfolio-wide cost per result at
                 * all: dividing this project's spend by the store's orders is the exact arithmetic
                 * «Do NOT aggregate incomparable ROAS/CPA/CPL» forbids.
                 */
                'project_id' => '00000000-0000-0000-0000-000000000003',
                'project_name' => 'إطلاق العلامة — Brand',
                'objective' => 'awareness',
                'metric_set' => ['spend', 'impressions', 'reach', 'cpm'],
                'totals' => [
                    'spend' => 6000.0, 'conversions' => 0, 'cpa' => null, 'revenue' => 0.0,
                    'impressions' => 1980400, 'clicks' => 8800, 'ctr' => 0.0044, 'reach' => 940000,
                    'cpm' => 3.03, 'video_views' => 412000,
                ],
                'previous' => ['spend' => 4000.0, 'conversions' => 0, 'cpa' => null, 'revenue' => 0.0],
                'reported' => [
                    'spend' => true, 'conversions' => false, 'revenue' => false, 'impressions' => true,
                    'clicks' => true, 'reach' => true, 'video_views' => true,
                ],
                'change' => ['spend' => 0.5, 'conversions' => null, 'cpa' => null, 'revenue' => null],
                'paths' => [
                    'awareness' => ['spend' => 6000.0, 'campaigns' => 3, 'conversions' => 0, 'revenue' => 0, 'cost_per_result' => null, 'roas' => null],
                ],
                'best_platform' => ['label' => 'tiktok', 'spend' => 3400.0, 'cpa' => null],
                'worst_platform' => ['label' => 'x', 'spend' => 900.0, 'cpa' => null],
                'best_campaign' => null,
                'worst_campaign' => null,
                'budget' => [],
                'funnel' => [],
                'creatives' => [
                    'best' => ['name' => 'Launch Film 30s', 'provider' => 'tiktok', 'reason' => 'أعلى مدى بأقل تكلفة ألف ظهور'],
                    'declining' => [],
                    'fatigued' => [],
                ],
                'observations' => [],
                'freshness' => ['state' => 'fresh', 'last_sync_at' => '2026-08-07T05:00:00Z', 'missing_days' => 0, 'sync_failed' => false, 'failing' => []],
            ]],
        ];
    }

    /**
     * DIGESTS-CADENCE-BUDGETS-001 — one mail per alert family, as the alert engine would compose it, for review and PNG proof.
     *
     * @return array<string, Mailable>
     */
    public static function alertTypes(string $locale): array
    {
        $ar = $locale === 'ar';
        $item = static fn (string $type, string $severity, array $ctx): array => [
            'rule_type' => $type, 'severity' => $severity, 'context' => $ctx, 'project_id' => '00000000-0000-4000-8000-000000000001',
            'project_name' => $ar ? 'متجر الندى' : 'Al Nada Store', 'event_id' => 'demo-'.$type, 'triggered_at' => '2026-11-09T05:40:00Z',
        ];
        $types = [
            'alert-budget' => $item('budget_risk', 'critical', ['provider' => 'meta', 'spend' => 8000.0, 'budget' => 10000.0, 'ratio' => 1.67, 'basis_class' => 'measured', 'budget_currency' => 'SAR']),
            'alert-roas' => $item('roas_drop', 'warning', ['provider' => 'snapchat', 'roas_previous' => 4.56, 'roas_current' => 3.10, 'threshold_pct' => 25]),
            'alert-cpl' => $item('cpl_increase', 'warning', ['provider' => 'tiktok', 'cpl_previous' => 64.13, 'cpl_current' => 98.4, 'threshold_pct' => 25]),
            'alert-sync' => $item('sync_failure', 'critical', ['provider' => 'tiktok', 'last_sync_at' => '2026-11-07T03:50:00Z']),
            'alert-tracking' => $item('ga4_tracking_loss', 'critical', ['drop' => 0.62, 'previous' => 4200, 'sessions' => 1596]),
            'alert-leads' => $item('lead_no_contact', 'warning', ['count' => 7, 'minutes' => 240]),
            'alert-launch' => $item('launch_advance', 'positive', ['from' => 'Launch 1', 'to' => 'Launch 2']),
            'alert-report-failed' => $item('report_failed', 'critical', []),
            'alert-security' => $item('security', 'critical', []),
            'alert-sample' => $item('sample_alert', 'info', []),
        ];

        return array_map(static fn (array $i): Mailable => new AlertBundleMail([AlertMailItem::build($i, $ar)], $locale, $ar ? 'محمد' : 'Mohammed'), $types);
    }

    /**
     * EMAIL-DIGESTS-OPERATIONAL-001 — the operational digests' fixtures: the portfolio above, with every platform's own rows.
     *
     * A hand-built payload shaped exactly like `DailyDigest::buildRange()` (the keys it adds are the live report's `byProvider()`
     * rows), so a preview renders on an empty machine. Three unalike projects — a store on four platforms, a lead-generation
     * client on two, a brand launch on two (X among them: a platform is a row, not code) — and the awkward cases on purpose:
     * a platform that sent no reach, a budget off plan, a source that stopped syncing.
     *
     * @return array<string,mixed>
     */
    public static function operationalDigest(bool $weekly = true): array
    {
        $d = self::digest($weekly);
        $days = ['2026-07-31', '2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06'];
        $spark = static fn (array $vals): array => array_map(static fn (string $date, ?float $v): array => ['date' => $date, 'spend' => $v], $days, $vals);
        $row = static fn (string $provider, array $f): array => ['provider' => $provider, 'spend_withheld_rows' => 0, 'revenue_withheld_rows' => 0] + $f;

        $d['projects'][0]['totals'] += ['purchases' => 263, 'roas' => 4.14];
        $d['projects'][0]['previous'] += ['purchases' => 300, 'roas' => 4.38];
        $d['projects'][0]['change'] += ['purchases' => -0.12, 'roas' => -0.055];
        $d['projects'][0] += [
            'currency' => 'USD',
            'series' => $spark([2900.0, 3500.0, 3100.0, 3800.0, 3000.0, 3700.0, 3333.18]),
            'platform_rows' => [
                $row('meta', ['spend' => 10100.0, 'purchases' => 118, 'revenue' => 44200.0, 'roas' => 4.38, 'impressions' => 300000, 'clicks' => 7000, 'ctr' => 0.0233]),
                $row('snapchat', ['spend' => 6200.0, 'purchases' => 82, 'revenue' => 31500.0, 'roas' => 5.08, 'impressions' => 220000, 'clicks' => 5100, 'ctr' => 0.0232]),
                $row('google', ['spend' => 4733.18, 'purchases' => 41, 'revenue' => 14100.0, 'roas' => 2.98, 'impressions' => 120000, 'clicks' => 2600, 'ctr' => 0.0217]),
                $row('tiktok', ['spend' => 2300.0, 'purchases' => 22, 'revenue' => 6700.0, 'roas' => 2.91, 'impressions' => 55281, 'clicks' => 919, 'ctr' => 0.0166]),
            ],
            'platform_previous' => [
                $row('meta', ['spend' => 9000.0, 'purchases' => 120, 'revenue' => 41000.0, 'roas' => 4.56]),
                $row('snapchat', ['spend' => 5500.0, 'purchases' => 70, 'revenue' => 26000.0, 'roas' => 4.73]),
                $row('google', ['spend' => 4500.0, 'purchases' => 50, 'revenue' => 17500.0, 'roas' => 3.89]),
                $row('tiktok', ['spend' => 2000.0, 'purchases' => 60, 'revenue' => 7500.0, 'roas' => 3.75]),
            ],
            'platform_reported' => [],
            'platform_series' => [
                'meta' => $spark([1300.0, 1500.0, 1400.0, 1700.0, 1350.0, 1450.0, 1400.0]),
                'snapchat' => $spark([800.0, 900.0, 850.0, 1000.0, 880.0, 920.0, 850.0]),
                'google' => $spark([500.0, 800.0, 600.0, 700.0, 550.0, 900.0, 683.18]),
                'tiktok' => $spark([300.0, 300.0, 250.0, 400.0, 220.0, 430.0, 400.0]),
            ],
            'budget_rows' => [
                ['provider' => 'meta', 'pace' => 1.67, 'spent' => 8000.0, 'budget' => 10000.0],
                ['provider' => 'snapchat', 'pace' => 1.0, 'spent' => 6200.0, 'budget' => 7000.0],
            ],
        ];
        $d['projects'][0]['observations'] = [
            ['id' => 'a', 'kind' => 'budget_pace', 'severity' => 'critical', 'metric' => 'pace', 'value' => '1.67×', 'change' => null, 'scope' => ['type' => 'platform', 'name' => 'ميتا'], 'title' => '', 'detail' => ''],
            ['id' => 'b', 'kind' => 'rising_cost', 'severity' => 'warning', 'metric' => 'cpa', 'value' => '88.72 USD', 'change' => 0.27, 'scope' => ['type' => 'period', 'name' => null], 'title' => '', 'detail' => ''],
        ];

        $d['projects'][1] += [
            'currency' => 'USD',
            'series' => $spark([1700.0, 1800.0, 1750.0, 1900.0, 1650.0, 1850.0, 1850.0]),
            'platform_rows' => [
                $row('meta', ['spend' => 6100.0, 'leads' => 85, 'cpl' => 71.76, 'clicks' => 4400, 'impressions' => 200000]),
                $row('tiktok', ['spend' => 2400.0, 'leads' => 10, 'cpl' => 240.0, 'clicks' => 1800, 'impressions' => 90000]),
            ],
            'platform_previous' => [
                $row('meta', ['spend' => 5900.0, 'leads' => 92, 'cpl' => 64.13]),
                $row('tiktok', ['spend' => 2200.0, 'leads' => 18, 'cpl' => 122.2]),
            ],
            'platform_reported' => [],
            'platform_series' => ['meta' => $spark([850.0, 900.0, 870.0, 910.0, 840.0, 870.0, 860.0]), 'tiktok' => $spark([300.0, 350.0, 330.0, 360.0, 320.0, 380.0, 360.0])],
            'budget_rows' => [['provider' => 'tiktok', 'pace' => 0.44, 'spent' => 2400.0, 'budget' => 9000.0]],
        ];
        $d['projects'][1]['totals'] += ['leads' => 95, 'cpl' => 131.58];
        $d['projects'][1]['previous'] += ['leads' => 110, 'cpl' => 98.18];
        $d['projects'][1]['change'] += ['leads' => -0.136, 'cpl' => 0.34];
        $d['projects'][1]['observations'] = [
            ['id' => 'c', 'kind' => 'rising_cost', 'severity' => 'warning', 'metric' => 'cpl', 'value' => '131.58 USD', 'change' => 0.34, 'scope' => ['type' => 'period', 'name' => null], 'title' => '', 'detail' => ''],
            ['id' => 'e', 'kind' => 'stale_data', 'severity' => 'critical', 'metric' => null, 'value' => null, 'change' => null, 'scope' => ['type' => 'data', 'name' => null], 'title' => '', 'detail' => ''],
        ];
        $d['projects'][1]['freshness']['sync_failed'] = true;
        $d['projects'][1]['freshness']['failing'] = [['name' => 'TikTok', 'provider' => 'tiktok']];

        $d['projects'][2] += [
            'currency' => 'USD',
            'series' => $spark([800.0, 850.0, 900.0, 880.0, 870.0, 900.0, 800.0]),
            'platform_rows' => [
                $row('tiktok', ['spend' => 3400.0, 'impressions' => 1100000, 'reach' => 520000, 'cpm' => 3.09]),
                // X sent no reach: the cell says so instead of printing 0.
                $row('x', ['spend' => 900.0, 'impressions' => 880400, 'reach' => null, 'cpm' => 1.02]),
            ],
            'platform_previous' => [$row('tiktok', ['spend' => 2500.0, 'impressions' => 700000, 'reach' => 400000, 'cpm' => 3.57]), $row('x', ['spend' => 600.0, 'impressions' => 500000, 'reach' => null, 'cpm' => 1.2])],
            'platform_reported' => ['x' => ['reach' => false]],
            'platform_series' => ['tiktok' => $spark([480.0, 500.0, 520.0, 490.0, 470.0, 520.0, 420.0]), 'x' => $spark([120.0, 130.0, 140.0, null, 130.0, 140.0, 140.0])],
            'budget_rows' => [],
        ];
        // DIGESTS-CADENCE-BUDGETS-001 — each project's month: ahead of plan, behind plan, and one with no budget recorded.
        $month = ['from' => '2026-08-01', 'to' => '2026-08-31', 'through' => '2026-08-06', 'elapsed' => 0.1935, 'withheld' => false, 'currencies' => 1, 'excluded' => 0, 'unmeasured' => 0, 'campaigns' => 4];
        $d['projects'][0]['budget_month'] = ['budget' => 120000.0, 'spent' => 29333.18, 'remaining' => 90666.82, 'projected' => 151590.0, 'pace' => 1.2633, 'currency' => 'USD'] + $month;
        $d['projects'][1]['budget_month'] = ['budget' => 60000.0, 'spent' => 9500.0, 'remaining' => 50500.0, 'projected' => 49090.0, 'pace' => 0.8182, 'currency' => 'USD'] + $month;
        $d['projects'][2]['budget_month'] = ['budget' => null, 'spent' => null, 'remaining' => null, 'projected' => null, 'pace' => null, 'currency' => null, 'currencies' => 0] + $month;

        $d['projects'][2]['observations'] = [
            ['id' => 'f', 'kind' => 'period_comparison', 'severity' => 'positive', 'metric' => 'cpm', 'value' => '3.03 USD', 'change' => -0.14, 'scope' => ['type' => 'period', 'name' => null], 'title' => '', 'detail' => ''],
        ];

        return $d;
    }
}
