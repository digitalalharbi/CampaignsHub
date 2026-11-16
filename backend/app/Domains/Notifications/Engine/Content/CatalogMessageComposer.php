<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Content;

use App\Domains\Notifications\Engine\Contracts\EventRegistry;
use App\Domains\Notifications\Engine\Contracts\MessageComposer;
use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Support\MailDesign;

/**
 * The engine's own words for a delivery: the catalog title and body in the recipient's language, with the project and
 * workspace named and the reference number printed. Internal messages only — it is NOT external-ready, so the sender
 * never mails an external recipient with it (the §11 design replaces it for the external scope).
 */
final class CatalogMessageComposer implements MessageComposer
{
    public function __construct(private readonly EventRegistry $events) {}

    public function compose(Delivery $delivery): ComposedMessage
    {
        $locale = $delivery->recipient->locale;
        $event = $this->events->get($delivery->eventKey);
        $project = (string) ($delivery->payload['project_name'] ?? '');
        $workspace = (string) ($delivery->payload['workspace_name'] ?? '');
        $replace = array_merge(
            array_filter($delivery->payload, static fn ($v): bool => is_scalar($v)),
            ['project' => $project, 'workspace' => $workspace],
        );

        $title = $event === null ? $delivery->eventKey : $this->text($event->textKey($delivery->scope, 'title'), $delivery, $replace, $locale);
        $body = $event === null ? '' : $this->text($event->textKey($delivery->scope, 'body'), $delivery, $replace, $locale);
        if ($delivery->groupCount > 1) {
            $body .= $locale === 'ar' ? " ({$delivery->groupCount} حالات)" : " ({$delivery->groupCount} items)";
        }

        $context = trim($project.($project !== '' && $workspace !== '' ? ' — ' : '').$workspace);
        $subject = $context === '' ? $title : $context.' | '.$title;
        $ref = ($locale === 'ar' ? 'الرقم المرجعي: ' : 'Reference: ').$delivery->reference;
        $dir = $locale === 'ar' ? 'rtl' : 'ltr';
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        return new ComposedMessage(
            subject: mb_substr($subject, 0, 150),
            title: $title,
            body: $body,
            html: "<div dir=\"{$dir}\" style=\"font-family:".MailDesign::font($locale === 'ar')."\"><p><strong>{$e($title)}</strong></p><p>{$e($body)}</p><p>{$e($context)}</p><p style=\"color:#6b7280;font-size:12px\">{$e($ref)}</p></div>",
            text: $title."\n\n".$body."\n\n".$context."\n\n".$ref,
            url: isset($delivery->payload['url']) ? (string) $delivery->payload['url'] : null,
        );
    }

    /**
     * NOTIF-V2-INTERNAL-001 — an event may carry a `variant` («created», «edited», «renewed», «deleted»): its own sentence is
     * `<key>_<variant>` and the plain key is the fallback, so one catalog event tells four different facts truthfully.
     *
     * @param  array<string, mixed>  $replace
     */
    private function text(string $key, Delivery $delivery, array $replace, string $locale): string
    {
        $variant = $delivery->payload['variant'] ?? null;
        if (is_string($variant) && $variant !== '') {
            $specific = $key.'_'.$variant;
            $found = (string) __($specific, $replace, $locale);
            if ($found !== $specific) {
                return $found;
            }
        }

        return (string) __($key, $replace, $locale);
    }
}
