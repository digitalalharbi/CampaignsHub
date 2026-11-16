<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Consent;

use App\Domains\Notifications\Engine\Content\ComposedMessage;
use App\Domains\Notifications\Engine\Contracts\WorkspaceTypes;
use App\Domains\Notifications\External\Content\Footprint;
use App\Domains\Notifications\External\Design\DesignTokens as T;

/**
 * The double opt-in invitation (spec §9): «[اسم المساحة] تدعوك لاستلام تقارير مشروع [اسم المشروع]». Sent ONLY by the
 * owner's explicit «أرسل طلبات التأكيد» (builder M's `ConfirmationRequests::send`), never automatically. Drawn from the
 * shared design tokens; one button to the confirmation page (GET shows it, POST confirms), nothing sent before.
 */
final class ConsentMessage
{
    /** @param list<string> $projects */
    public static function compose(string $workspace, array $projects, string $link, string $locale, bool $footprintRemoved = false, string $term = ''): ComposedMessage
    {
        $l = $locale === 'en' ? 'en' : 'ar';
        $names = implode($l === 'ar' ? '، ' : ', ', $projects);
        $t = static fn (string $k, array $r = []): string => (string) __('notifications_external.'.$k, $r, $l);
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $term = $term !== '' ? $term : $t('roles_fallback');
        $title = $t('confirm.title', ['workspace' => $workspace, 'project' => $names, 'term' => $term]);
        $body = $t('confirm.body', ['workspace' => $workspace, 'project' => $names]);
        $subject = $t('subject.confirm', ['workspace' => $workspace, 'project' => $names, 'term' => $term]);
        $subject = mb_substr($subject, 0, 90 - ($footprintRemoved ? 0 : 15)).($footprintRemoved ? '' : ' · CampaignsHub');
        $dir = $l === 'ar' ? 'rtl' : 'ltr';

        $html = '<div dir="'.$dir.'" style="font-family:'.str_replace('"', "'", T::emailFont($l)).';color:'.T::color('text-primary').';text-align:'.($l === 'ar' ? 'right' : 'left').'">'
            .'<p style="font-size:'.T::px('text-lg').';font-weight:700;margin:0 0 '.T::space('3').'">'.$e($title).'</p>'
            .'<p style="font-size:'.T::px('text-sm').';line-height:1.7;color:'.T::color('text-secondary').';margin:0 0 '.T::space('4').'">'.$e($body).'</p>'
            // EMAIL-CLIENT-COMPAT-001 — a bullet-proof button: padding, ground and corners on a table cell, the label never wraps.
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:0 0 '.T::space('4').'"><tr><td align="'.($l === 'ar' ? 'right' : 'left').'"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" bgcolor="'.T::color('brand-700').'" data-button="cta" style="background-color:'.T::color('brand-700').';border-radius:'.T::radius('radius-medium').';padding:'.T::space('3').' '.T::space('5').';white-space:nowrap;"><a href="'.$e($link).'" style="color:'.T::color('surface').';text-decoration:none;font-weight:600;white-space:nowrap;">'.$e($t('confirm.button')).'</a></td></tr></table></td></tr></table>'
            .'<p style="font-size:'.T::px('text-xs').';color:'.T::color('text-muted').';margin:0">'.$e($t('confirm.ignore')).'</p>'
            .($footprintRemoved ? '' : '<p style="font-size:'.T::px('text-xs').';color:'.T::color('text-muted').';margin:'.T::space('4').' 0 0">'.$e($t('footprint')).'</p>')
            .'</div>';
        $text = $title."\n\n".$body."\n\n".$t('confirm.button').': '.$link."\n\n".$t('confirm.ignore').($footprintRemoved ? '' : "\n\n".$t('footprint'));

        return new ComposedMessage(
            subject: $subject, title: $title, body: $body, html: $html, text: $text, externalReady: true,
            senderName: $footprintRemoved ? $workspace : $t('sender_via', ['workspace' => $workspace]),
        );
    }

    /** The workspace type's word for a project («مشروع» / «حملة»), in the reader's language. */
    public static function term(string $tenantId, string $locale): string
    {
        $key = (string) (app(WorkspaceTypes::class)->profileFor($tenantId)->terminology['project'] ?? '');

        return $key === '' ? '' : (string) __($key, [], $locale === 'en' ? 'en' : 'ar');
    }

    public static function footprintRemoved(string $tenantId): bool
    {
        return Footprint::removed($tenantId);
    }
}
