<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Console;

use App\Domains\Notifications\Mail\AlertBundleMail;
use App\Domains\Notifications\Mail\CredentialMail;
use App\Domains\Notifications\Mail\DailyDigestMail;
use App\Domains\Notifications\Mail\InvitationMail;
use App\Domains\Notifications\Mail\OperationalMail;
use App\Domains\Notifications\Mail\SecurityAlertMail;
use App\Domains\Notifications\Support\MailLinks;
use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;

/**
 * Every email this product can send, rendered to a file — MAIL-008.
 *
 * ## Why a command rather than a route
 *
 * A preview route is a page that has to be authorised, hosted and remembered; this is a person
 * asking «what does the budget alert actually look like in Arabic?» and getting an answer they can
 * open in a browser, forward to a colleague, or paste into a real inbox to see what Gmail does to it.
 *
 * It is also the only way to review the emails at all until SMTP credentials exist. The templates,
 * the scheduler, the preferences and the ledger are complete and tested; REAL SENDING IS
 * `Awaiting Credentials`, and nothing in this product claims otherwise.
 *
 * ## The fixtures are shaped like real data, and say so
 *
 * The digest below is a hand-built block rather than a database read, because a preview must render
 * the same way on an empty machine. Where the shape matters — a metric nobody reported, a funnel
 * stage that is absent rather than zero — the fixture carries the awkward case rather than the tidy
 * one, since the tidy one is not what needs reviewing.
 */
final class RenderMailPreviews extends Command
{
    protected $signature = 'notifications:preview
        {--out=storage/app/mail-previews : Where to write the files}
        {--locale= : One language only — `ar` or `en`. Both by default}';

    protected $description = 'Render every email type to HTML, in both languages, for visual review.';

    public function handle(): int
    {
        $dir = (string) $this->option('out');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->error("Could not create {$dir}");

            return self::FAILURE;
        }

        $locales = $this->option('locale') !== null ? [(string) $this->option('locale')] : ['ar', 'en'];
        $written = 0;

        foreach ($locales as $locale) {
            foreach ($this->messages($locale) as $name => $mailable) {
                $path = sprintf('%s/%s.%s.html', rtrim($dir, '/'), $name, $locale);
                file_put_contents($path, $mailable->render());
                $this->line($path);
                $written++;
            }
        }

        $this->info("{$written} previews written. Real sending remains Awaiting Credentials.");

        return self::SUCCESS;
    }

    /** @return array<string, Mailable> */
    private function messages(string $locale): array
    {
        $ar = $locale === 'ar';

        return $this->accountMessages($locale) + [
            'digest-daily' => new DailyDigestMail($this->digest(), $locale, $ar ? 'محمد' : 'Mohammed', 'daily'),
            'digest-weekly' => new DailyDigestMail($this->digest(weekly: true), $locale, $ar ? 'محمد' : 'Mohammed', 'weekly'),

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
                        'context' => $ar ? 'متجر تجريبي' : 'Demo store',
                        'title' => $ar ? 'حملة «الصيف» تستهلك الميزانية أسرع من الخطة' : '“Summer” is spending ahead of plan',
                        'detail' => $ar
                            ? 'صُرف 8,000.00 SAR من أصل 10,000.00 SAR، أي 167% من الإنفاق المتوقع حتى الآن.'
                            : '8,000.00 SAR of a 10,000.00 SAR budget is spent — 167% of what was expected by now.',
                    ],
                    [
                        'severity' => 'warning',
                        'context' => $ar ? 'متجر تجريبي' : 'Demo store',
                        'title' => $ar ? 'تراجع معدل النقر' : 'Click-through rate is falling',
                        'detail' => $ar
                            ? 'تراجع معدل النقر 30% ليصل إلى 1.20%، وقد يشير ذلك إلى بداية ضعف في أداء المحتوى.'
                            : 'Click-through rate fell 30% to 1.20%, which can be the first sign of creative wearing out.',
                    ],
                    [
                        'severity' => 'critical',
                        'context' => $ar ? 'عميل تجريبي' : 'Demo client',
                        'title' => $ar ? 'تعذّرت آخر مزامنة لبيانات سناب شات' : 'The Snapchat sync could not complete',
                        'detail' => $ar
                            ? 'لم تتحدث بيانات سناب شات منذ ست ساعات، لذلك قد تكون بعض المؤشرات غير مكتملة.'
                            : 'Snapchat data has not updated for six hours, so some figures may be incomplete.',
                    ],
                ],
                lang: $locale, recipientName: $ar ? 'محمد' : 'Mohammed',
            ),

            // The single-finding case, which reads differently: the subject is the finding itself
            // rather than a count, and one card must not look like a list with an item missing.
            'alerts-one' => new AlertBundleMail(
                items: [[
                    'severity' => 'warning',
                    'context' => $ar ? 'متجر تجريبي' : 'Demo store',
                    'title' => $ar ? 'ارتفاع معدل التكرار' : 'Frequency is climbing',
                    'detail' => $ar
                        ? 'يشاهد الشخص الواحد الإعلان 6 مرات في المتوسط، ونوصي بتوسيع الجمهور أو تحديث المحتوى.'
                        : 'The average person has seen the ad 6 times — worth widening the audience or refreshing the creative.',
                ]],
                lang: $locale, recipientName: $ar ? 'محمد' : 'Mohammed',
            ),

            'report-ready' => new OperationalMail(
                kind: 'report_ready', severity: 'positive',
                title: $ar ? 'تقرير يوليو جاهز' : 'The July report is ready',
                detail: $ar
                    ? 'اكتمل إعداد تقرير الأداء الشهري ويمكنك فتحه أو مشاركته مع العميل.'
                    : 'The monthly performance report has finished generating and is ready to open or share.',
                context: $ar ? 'متجر تجريبي' : 'Demo store',
                lang: $locale, recipientName: $ar ? 'محمد' : 'Mohammed',
                path: '/app/reports', action: $ar ? 'فتح التقرير' : 'Open the report',
            ),

            'billing' => new OperationalMail(
                kind: 'billing', severity: 'info',
                title: $ar ? 'فاتورة اشتراكك الجديدة' : 'Your new subscription invoice',
                detail: $ar
                    ? 'صدرت فاتورة اشتراك شهر أغسطس، ويمكنك مراجعتها أو تنزيلها من صفحة الفواتير.'
                    : 'The August subscription invoice has been issued and can be reviewed or downloaded from your billing page.',
                context: $ar ? 'الاشتراك' : 'Subscription',
                lang: $locale, recipientName: $ar ? 'محمد' : 'Mohammed',
                path: '/app/billing', action: $ar ? 'عرض الفاتورة' : 'View the invoice',
            ),

            'approval' => new OperationalMail(
                kind: 'approval', severity: 'info',
                title: $ar ? 'طلب ينتظر موافقتك' : 'A request is waiting for your approval',
                detail: $ar
                    ? 'أُرسل طلب إعلاني جديد إلى فريقك وينتظر مراجعتك قبل بدء التنفيذ.'
                    : 'A new advertising request has reached your team and needs your review before work starts.',
                context: $ar ? 'الطلبات' : 'Requests',
                lang: $locale, recipientName: $ar ? 'محمد' : 'Mohammed',
                path: '/app/requests', action: $ar ? 'مراجعة الطلب' : 'Review the request',
            ),

            'message' => new OperationalMail(
                kind: 'message', severity: 'info',
                title: $ar ? 'رسالة جديدة من العميل' : 'A new message from your client',
                detail: $ar
                    ? 'وصلت رسالة جديدة في محادثة المشروع وتنتظر ردك.'
                    : 'There is a new message in the project conversation waiting for a reply.',
                context: $ar ? 'متجر تجريبي' : 'Demo store',
                lang: $locale, recipientName: $ar ? 'محمد' : 'Mohammed',
                path: '/app/conversations', action: $ar ? 'فتح المحادثة' : 'Open the conversation',
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
    private function accountMessages(string $locale): array
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

            'account-invitation' => new InvitationMail(
                workspace: $ar ? 'وكالة المدى' : 'Almada Agency',
                roleSlug: 'manager',
                acceptUrl: "{$app}/invite/accept?token={$token}",
                lang: $locale,
                invitedBy: $ar ? 'محمد الحربي' : 'Mohammed Alharbi',
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
    private function digest(bool $weekly = false): array
    {
        return [
            'sendable' => true,
            'date' => '2026-08-06',
            'to_date' => $weekly ? '2026-08-06' : '2026-08-06',
            'days' => $weekly ? 7 : 1,
            'previous_date' => $weekly ? '2026-07-30' : '2026-08-05',
            'totals' => ['spend' => 23333.18, 'conversions' => 263, 'revenue' => 96500.0, 'projects' => 1],
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
                'budget' => [['campaign' => 'حملة الصيف', 'pace' => 1.67, 'spent' => 8000.0, 'budget' => 10000.0, 'direction' => 'ahead']],
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
                        'title' => 'حملة «الصيف» تستهلك الميزانية أسرع من الخطة',
                        'detail' => 'صُرف 8,000.00 SAR من أصل 10,000.00 SAR، أي 167% من الإنفاق المتوقع حتى الآن.',
                        'scope' => ['type' => 'campaign', 'name' => 'الصيف'],
                    ],
                    [
                        'id' => 'b', 'kind' => 'rising_cost', 'severity' => 'warning', 'reveals' => ['spend'],
                        'title' => 'ارتفاع تكلفة النتيجة',
                        'detail' => 'ارتفعت تكلفة النتيجة 27% لتصل إلى 88.72 SAR، وقد يعود ذلك إلى منافسة أعلى أو ضعف في أداء المحتوى.',
                        'scope' => ['type' => 'period', 'name' => null],
                    ],
                ],
                'freshness' => ['state' => 'fresh', 'last_sync_at' => '2026-08-07T05:00:00Z', 'missing_days' => 0, 'sync_failed' => false, 'failing' => []],
            ]],
        ];
    }
}
