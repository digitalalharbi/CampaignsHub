<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Push;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Support\BellKind;
use App\Domains\Projects\Models\Project;

/**
 * NOTIF-PUSH-001 — the words on a locked phone: short, and no amounts.
 *
 * «[client] — الميزانية 90%». The title is the client (or the project when there is none), the body is what it is. A push is
 * read over a shoulder and stored by the phone and the push service, so it carries NO spend figure, no campaign name, no
 * result count — only the name of the thing and where to open it. `url` is a path in the person's own portal; the page, not the
 * push, decides what it shows.
 */
final class PushPayload
{
    /** @return array{title: string, body: string, url: string, tag: string, kind: string|null, notification: string} */
    public static function for(AppNotification $n, bool $ar, string $portalPrefix): array
    {
        $who = self::who($n) ?? 'كمبينز هب';
        $kind = $n->kind;
        $what = BellKind::label($kind, $ar)
            ?? ($ar && $n->title_ar ? (string) $n->title_ar : (string) $n->title);

        return [
            'title' => $who,
            'body' => self::short($what),
            'url' => self::path($n, $portalPrefix),
            // One push per finding per object: a newer one replaces the older on the lock screen.
            'tag' => 'ch-'.($kind ?? (string) $n->type).'-'.($n->entity_id ?? $n->id),
            'kind' => $kind,
            'notification' => (string) $n->id,
        ];
    }

    /**
     * The one summary sent for what waited through quiet hours.
     *
     * @return array{title: string, body: string, url: string, tag: string, kind: null, notification: string}
     */
    public static function summary(int $count, bool $ar, string $portalPrefix): array
    {
        return [
            'title' => $ar ? 'كمبينز هب' : 'CampaignsHub',
            'body' => $ar ? "وصلتك {$count} تنبيهات وقت الهدوء" : "{$count} alerts arrived while you were away",
            'url' => $portalPrefix.'/alerts',
            'tag' => 'ch-summary',
            'kind' => null,
            'notification' => '',
        ];
    }

    /** @return array{title: string, body: string, url: string, tag: string, kind: null, notification: string} */
    public static function test(bool $ar, string $portalPrefix): array
    {
        return [
            'title' => $ar ? 'كمبينز هب' : 'CampaignsHub',
            'body' => $ar ? 'جرّبنا إشعارات الجوال — وصلتك' : 'Mobile notifications work on this device',
            'url' => $portalPrefix.'/account/notifications',
            'tag' => 'ch-test',
            'kind' => null,
            'notification' => '',
        ];
    }

    private static function who(AppNotification $n): ?string
    {
        if ($n->client_workspace_id !== null) {
            $name = ClientWorkspace::withoutGlobalScopes()->where('tenant_id', $n->tenant_id)->whereKey($n->client_workspace_id)->value('name');
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }
        if ($n->project_id !== null) {
            $name = Project::withoutGlobalScopes()->where('tenant_id', $n->tenant_id)->whereKey($n->project_id)->value('name');
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return null;
    }

    private static function path(AppNotification $n, string $prefix): string
    {
        $path = BellKind::openPath($n->kind, $n->project_id === null ? null : (string) $n->project_id) ?? (string) ($n->action_url ?? '/alerts');
        // An action_url written before portals were relative may already carry one; a leading `/agency` or `/app` is kept.
        if (preg_match('#^/(agency|app|admin|portal|influencers)(/|$)#', $path) === 1) {
            return $path;
        }

        return $prefix.($path === '' || $path[0] !== '/' ? '/'.$path : $path);
    }

    private static function short(string $text): string
    {
        return mb_strlen($text) > 80 ? mb_substr($text, 0, 79).'…' : $text;
    }
}
