<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Content;

use App\Domains\Notifications\Engine\Contracts\EventRegistry;
use App\Domains\Notifications\Engine\Contracts\MessageComposer;
use App\Domains\Notifications\Engine\Data\Delivery;
use App\Domains\Notifications\Engine\Enums\Priority;
use App\Domains\Notifications\Engine\Enums\RecipientKind;
use App\Domains\Notifications\Internal\EngineKinds;
use App\Domains\Notifications\Support\MailDesign;
use App\Domains\Notifications\Support\MailLinkContext;
use App\Domains\Notifications\Support\MailLinks;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The engine's own words for a delivery: the catalog title and body in the recipient's language, with the project and
 * workspace named, drawn as a card with its next step and one button to its page (ENGINE-MAIL-VISUAL-001). Internal messages only — it is NOT external-ready, so the sender
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
        // MAIL-SUBJECT — no «|» in a subject (rules §1, MAIL-NOTES-001): «الهاجري — SMARTCODE — مشروع نشط بلا ميزانية للفترة».
        $subject = $context === '' ? $title : $context.' — '.$title;
        $ar = $locale === 'ar';

        /*
         * ENGINE-MAIL-VISUAL-001 — Owner 2026-11-28: «مشروع نشط بلا ميزانية للفترة» arrived as three lines of text and a reference
         * number, with no way to the budgets. Every engine email is now a card in the approved notification style: a status ring
         * in the event's severity with its window, the sentence, the project and workspace as chips, the next step and WHO takes
         * it, and ONE button to the exact page the event is about (`EngineKinds::path()`), in the recipient's own portal and
         * workspace — only where the recipient may open it. The reference number stays in the message headers (`X-CH-Reference`)
         * and the delivery log for support; a reader has nothing to do with it, so it is no longer printed.
         */
        $url = $this->link($delivery);
        $step = $this->text('notifications_v2.events.'.str_replace('.', '_', $delivery->eventKey).'.'.$delivery->scope->value.'.step', $delivery, $replace, $locale);
        $step = str_ends_with($step, '.step') ? '' : $step;
        $action = $url === null ? '' : $this->text('notifications_v2.events.'.str_replace('.', '_', $delivery->eventKey).'.'.$delivery->scope->value.'.action', $delivery, $replace, $locale);
        $action = $url === null ? '' : (str_ends_with($action, '.action') ? ($ar ? 'افتح في كامبينز هب' : 'Open in CampaignsHub') : $action);
        $state = match ($delivery->priority) {
            Priority::Critical => 'bad',
            Priority::Important => 'watch',
            default => 'ok',
        };

        $html = view('mail.engine-event', [
            'title' => $title, 'body' => $body, 'project' => $project, 'workspace' => $workspace, 'step' => $step,
            'url' => $url, 'action' => $action, 'state' => $state, 'ar' => $ar,
            'dir' => $ar ? 'rtl' : 'ltr', 'startSide' => $ar ? 'right' : 'left', 'font' => MailDesign::font($ar),
            'window' => $this->window($delivery, $ar),
        ])->render();

        return new ComposedMessage(
            subject: mb_substr($subject, 0, 150),
            title: $title,
            body: $body,
            html: $html,
            text: implode("\n\n", array_filter([$title, $body, $context, $step, $url !== null ? $action.': '.$url : ''], static fn (string $l): bool => $l !== '')),
            // The in-app and push surfaces keep the producer's own portal-relative page; only the email button is absolute.
            url: isset($delivery->payload['url']) ? (string) $delivery->payload['url'] : null,
        );
    }

    /**
     * The page the event is about, in the recipient's portal and workspace — or null when nobody may follow it (an external
     * recipient, or a member who may not open that page of this project).
     */
    private function link(Delivery $delivery): ?string
    {
        if ($delivery->recipient->kind !== RecipientKind::User || $delivery->recipient->userId === null) {
            return null;
        }
        // The producer's own page when it named one (e.g. the morning brief's day), otherwise the event's page.
        $named = isset($delivery->payload['url']) && is_string($delivery->payload['url']) && str_starts_with($delivery->payload['url'], '/') ? $delivery->payload['url'] : null;
        $path = $named ?? EngineKinds::path($delivery->eventKey, $delivery->projectId);
        if ($path === null) {
            return null;
        }
        $capability = match (EngineKinds::group($delivery->eventKey)) {
            'budget' => ProjectCapability::BUDGET_VIEW,
            'sync' => ProjectCapability::INTEGRATIONS_MANAGE,
            'performance' => ProjectCapability::CAMPAIGNS_VIEW,
            default => null,
        };
        if ($capability !== null && $delivery->projectId !== null) {
            $user = User::query()->find($delivery->recipient->userId);
            if ($user === null || ! app(ProjectAbilities::class)->allows($user, $delivery->projectId, $capability)) {
                return null;
            }
        }

        return MailLinks::to(MailLinkContext::pathFor($delivery->recipient->userId, $delivery->tenantId, $path));
    }

    /** The window the fact was read over, for the ring's timeline: the budget rule reads the last 7 days. */
    private function window(Delivery $delivery, bool $ar): ?array
    {
        if (EngineKinds::group($delivery->eventKey) !== 'budget') {
            return null;
        }

        return ['from' => $ar ? 'آخر 7 أيام' : 'Last 7 days', 'to' => ($ar ? 'اليوم ' : 'Today ').Carbon::now('Asia/Riyadh')->format('j/n')];
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
