<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Recipients;

use App\Domains\Notifications\Engine\Workspace\WorkspaceSettings;
use App\Domains\Notifications\Engine\Workspace\WorkspaceSettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The external section's workspace settings (spec §0 layer 2, §9, §11–§13), kept inside
 * `workspace_notification_settings.settings` under `sender` and `external` — no new table. Every value has a platform
 * default, so a new workspace of any type works untouched. R's settings UI reads and writes them through
 * `GET|PUT /settings/workspace/notifications/external/settings`.
 */
final class ExternalSettings
{
    /** @var array<string, mixed> */
    public const DEFAULTS = [
        'sender' => ['reply_to' => null, 'signature' => null],
        'external' => [
            'disclaimer' => ['ar' => null, 'en' => null],   // null = platform default; the disclaimer itself cannot be removed
            'disclaimer_updated_at' => null,
            'forbidden_words' => [],                         // added to the platform list, never replacing it
            'contact_link' => null,                          // «طلب تواصل» — off unless a URL is set (spec §7)
            'subject_templates' => [],                       // template_key => custom part
            'continuity_days' => 5,                          // «تكفي حتى» a near date → continuity planning
            'improvement_pct' => 10,                         // cost/return improved above this % → event
            'copy_delivery' => 'all',                        // all | events | digest — copies for the sender
            'review_window_hours' => 4,
            'display_currency' => null,                      // null = workspace display currency (FxPolicy)
        ],
    ];

    public const FORBIDDEN_DEFAULT_AR = ['فشل', 'خسارة', 'قصور', 'تجاوز', 'إنذار', 'عاجل', 'خطأ'];

    public const FORBIDDEN_DEFAULT_EN = ['failure', 'failed', 'loss', 'shortfall', 'overrun', 'warning', 'urgent', 'error'];

    /** Action-request phrases an external message must never carry (spec §7). */
    public const ACTION_PHRASES = [
        'مطلوب منك', 'يرجى الرد', 'نرجو الرد', 'يرجى التأكيد', 'يرجى الموافقة', 'آخر موعد', 'خلال 24 ساعة', 'أقر بالاستلام', 'وقّع',
        'action required', 'please reply', 'please confirm', 'please approve', 'deadline', 'acknowledge', 'respond by', 'due by',
    ];

    public function __construct(private readonly WorkspaceSettingsRepository $repo) {}

    /** @return array<string, mixed> */
    public function for(string $tenantId): array
    {
        $stored = $this->repo->for($tenantId)->settings;

        return [
            'sender' => array_merge(self::DEFAULTS['sender'], (array) ($stored['sender'] ?? [])),
            'external' => array_merge(self::DEFAULTS['external'], (array) ($stored['external'] ?? [])),
        ];
    }

    public function workspace(string $tenantId): WorkspaceSettings
    {
        return $this->repo->for($tenantId);
    }

    /** @return list<string> the platform list plus the workspace's additions */
    public function forbiddenWords(string $tenantId): array
    {
        $extra = array_map('strval', (array) ($this->for($tenantId)['external']['forbidden_words'] ?? []));

        return array_values(array_unique(array_filter(array_merge(self::FORBIDDEN_DEFAULT_AR, self::FORBIDDEN_DEFAULT_EN, $extra))));
    }

    /**
     * Partial update; unknown keys are ignored. The disclaimer can be edited, never emptied (an empty text falls back
     * to the platform default) — spec §13.
     *
     * @param  array<string, mixed>  $changes  ['sender' => [...], 'external' => [...]]
     */
    public function update(string $tenantId, array $changes): array
    {
        $current = $this->repo->for($tenantId)->settings;
        foreach (['sender', 'external'] as $group) {
            $patch = array_intersect_key((array) ($changes[$group] ?? []), self::DEFAULTS[$group]);
            if ($group === 'external' && array_key_exists('disclaimer', $patch)) {
                $patch['disclaimer'] = array_map(static fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null, array_merge(['ar' => null, 'en' => null], (array) $patch['disclaimer']));
                $patch['disclaimer_updated_at'] = Carbon::now()->toIso8601String();
            }
            if ($patch !== []) {
                $current[$group] = array_merge((array) ($current[$group] ?? []), $patch);
            }
        }

        $exists = DB::table('workspace_notification_settings')->where('tenant_id', $tenantId)->exists();
        $now = Carbon::now();
        if ($exists) {
            DB::table('workspace_notification_settings')->where('tenant_id', $tenantId)->update(['settings' => json_encode($current), 'updated_at' => $now]);
        } else {
            DB::table('workspace_notification_settings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'settings' => json_encode($current), 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->repo->forget($tenantId);

        return $this->for($tenantId);
    }
}
