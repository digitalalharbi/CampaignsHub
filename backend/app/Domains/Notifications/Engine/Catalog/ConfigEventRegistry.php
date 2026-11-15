<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Catalog;

use App\Domains\Notifications\Engine\Contracts\EventRegistry;
use App\Domains\Notifications\Engine\Data\EventDefinition;
use App\Domains\Notifications\Engine\Enums\Scope;
use Illuminate\Support\Facades\DB;

/**
 * The ONE event catalog (spec §3): `config/notification_events.php` defaults, with the platform admin's overrides from
 * `notification_event_catalog` merged over them. An override is partial; `default_roles` and `channels` replace the
 * default wholesale. `EventDefinition::fromArray` keeps an internal-only event internal whatever the override says.
 */
final class ConfigEventRegistry implements EventRegistry
{
    /** @var array<string, EventDefinition>|null */
    private ?array $events = null;

    public function get(string $eventKey): ?EventDefinition
    {
        return $this->load()[$eventKey] ?? null;
    }

    public function all(): array
    {
        return array_values($this->load());
    }

    public function forScope(Scope $scope): array
    {
        return array_values(array_filter(
            $this->load(),
            static fn (EventDefinition $e): bool => $e->enabled && $e->scope->includes($scope),
        ));
    }

    /** Forget the merged catalog (after an admin edit). */
    public function flush(): void
    {
        $this->events = null;
    }

    /** @return array<string, EventDefinition> */
    private function load(): array
    {
        if ($this->events !== null) {
            return $this->events;
        }

        /** @var array<string, array<string, mixed>> $defaults */
        $defaults = (array) config('notification_events.events', []);
        $overrides = DB::table('notification_event_catalog')->get(['event_key', 'overrides', 'enabled', 'version'])->keyBy('event_key');

        $events = [];
        foreach ($defaults as $key => $definition) {
            $row = $overrides->get($key);
            if ($row !== null) {
                $override = is_string($row->overrides) ? (array) json_decode($row->overrides, true) : (array) $row->overrides;
                $definition = array_merge($definition, $override, ['enabled' => (bool) $row->enabled, 'version' => (int) $row->version]);
            }
            $events[(string) $key] = EventDefinition::fromArray((string) $key, $definition);
        }
        ksort($events);

        return $this->events = $events;
    }
}
