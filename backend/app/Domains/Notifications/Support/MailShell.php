<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

/**
 * Everything `layout.blade.php` needs, built once — MAIL-009.
 *
 * ## Why this exists
 *
 * The layout takes fourteen variables, and every mailable was assembling them by hand. Two did it
 * already and the difference between them was invisible until read side by side: one pointed the
 * unsubscribe at `/account/notifications` and the other at `/app/settings/notifications`, one of
 * which only worked because the router happened to redirect it. That is what a shared shell built by
 * copy-paste always becomes — and five mailables would have made it five.
 *
 * The design tokens live in `MailDesign` and the destinations in `MailLinks`. This is the third piece:
 * the ENVELOPE those two get poured into, so a template that renders at all renders with the brand,
 * the direction, the footer and the preheader already correct.
 *
 * ## `$showPreferences`
 *
 * The one decision this class makes rather than passes through. An account message — a code, a reset,
 * a security alert, an invitation — must not offer «إدارة إشعاراتك», because the product will not
 * honour it: nothing in the preference centre can switch off a password reset, and pretending
 * otherwise is a promise broken the next time somebody needs one. The footnote says so in words
 * instead.
 */
final class MailShell
{
    /**
     * The layout payload for one message.
     *
     * @param  bool  $preferences  false for account messages — see the class docblock
     * @return array<string,mixed>
     */
    public static function build(
        string $lang,
        string $subject,
        string $preheader,
        bool $preferences = true,
        string $footerNote = '',
    ): array {
        $ar = $lang === 'ar';

        return [
            'font' => MailDesign::font($ar),
            'numericFont' => MailDesign::numericFont(),
            'locale' => $lang,
            'dir' => $ar ? 'rtl' : 'ltr',
            /*
             * The side a border or an alignment should sit on, named by ROLE.
             *
             * A template writing `border-right` is a template that is wrong in English, and the
             * mistake is invisible in review because the Arabic version looks right.
             */
            'startSide' => $ar ? 'right' : 'left',
            'endSide' => $ar ? 'left' : 'right',
            'brand' => 'CampaignsHub',
            'headerNote' => $ar
                ? 'مساعدك لمتابعة كل حملاتك الإعلانية المدفوعة من مكان واحد'
                : 'Your assistant for every paid campaign, in one place',
            'subject' => $subject,
            // The line an inbox shows beside the subject. Never the subject again — that is the only
            // preview a reader gets, and repeating the title wastes it.
            'preheader' => $preheader,
            'urls' => MailLinks::footer(),
            'showPreferences' => $preferences,
            'footerNote' => $footerNote,
            'year' => date('Y'),
            't' => self::footerStrings($ar),
        ];
    }

    /**
     * The footer's own words.
     *
     * `why` differs by message: a digest arrives because somebody follows a project, and an account
     * message arrives because somebody asked for it or because their account changed. Callers that
     * need a different sentence merge over this.
     *
     * @return array<string,string>
     */
    private static function footerStrings(bool $ar): array
    {
        return $ar
            ? [
                'manage_preferences' => 'إدارة إشعاراتك',
                'privacy' => 'الخصوصية',
                'terms' => 'الشروط',
                'security' => 'الأمان',
                'why' => 'وصلتك هذه الرسالة لأنك تتابع هذا المشروع في CampaignsHub.',
            ]
            : [
                'manage_preferences' => 'Manage your notifications',
                'privacy' => 'Privacy',
                'terms' => 'Terms',
                'security' => 'Security',
                'why' => 'You are receiving this because you follow this project in CampaignsHub.',
            ];
    }

    /**
     * The footnote that stands where the unsubscribe would be on an account message.
     *
     * Stated plainly rather than left blank: a reader who finds no way to switch a message off
     * assumes the product hid it. Telling them why it cannot be switched off is the difference
     * between a security email and a mailing list they cannot escape.
     */
    public static function accountFootnote(bool $ar): string
    {
        return $ar
            ? 'هذه رسالة تخص أمان حسابك، وتُرسل عند الحاجة فقط، ولا يمكن إيقافها من إعدادات الإشعارات.'
            : 'This message is about the security of your account. It is sent only when needed, and cannot be turned off in notification settings.';
    }
}
