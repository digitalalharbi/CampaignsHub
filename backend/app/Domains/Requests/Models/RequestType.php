<?php

declare(strict_types=1);

namespace App\Domains\Requests\Models;

use App\Domains\Tenancy\Enums\Portal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $module
 * @property string $name_ar
 * @property string $name_en
 */
class RequestType extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'bool'];

    /**
     * The module a request type belongs to, and the portal that serves it.
     *
     * Only modules whose availability can change need an entry; anything unlisted is always offered.
     *
     * @var array<string, Portal>
     */
    private const MODULE_PORTALS = [
        'influencer_marketing' => Portal::Influencers,
    ];

    /**
     * The types a NEW request may be opened against right now (INFL-OFF-001).
     *
     * `is_active` says the platform owner still wants this type; this says the service behind it is
     * being offered in this release. Keeping them separate is the whole point: switching the
     * influencer sub-system off must not deactivate the type row, because that row is what every
     * existing influencer request is attached to — its name, its module and its history all hang off
     * it, and deactivating it would turn hundreds of real requests into rows pointing at a type the
     * product no longer admits exists.
     *
     * So the type is PRESERVED and merely stops being offered. Reads never apply this scope: an
     * influencer request submitted last month still opens, still shows its type, and still moves
     * through its stages. Only the intake — the form's catalogue and the payload that form posts —
     * narrows to what is on offer.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOffered(Builder $query): Builder
    {
        $withdrawn = self::withdrawnModules();

        return $withdrawn === [] ? $query : $query->whereNotIn('module', $withdrawn);
    }

    /**
     * The exact complement of {@see scopeOffered()} — announced, and not openable (INFL-SOON-001).
     *
     * The intake form names «علاقات المؤثرين وUGC» so a visitor looking for it learns it is coming
     * rather than concluding the product does not do it. That announcement must never become an
     * order, so it is a SEPARATE list from the one the form submits against — not a flag on a row in
     * the same list. A caller iterating the offerable types cannot reach these rows at all, which is
     * a stronger guarantee than remembering to check `disabled` at every call site.
     *
     * Complement rather than its own predicate, deliberately: written as a second condition the two
     * could drift until a type was in both lists — offered AND coming soon — and the form would show
     * it disabled while the API happily accepted it.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeComingSoon(Builder $query): Builder
    {
        $withdrawn = self::withdrawnModules();

        // No withdrawn module means nothing is coming soon — match nothing, never everything.
        return $withdrawn === [] ? $query->whereRaw('1 = 0') : $query->whereIn('module', $withdrawn);
    }

    /** @return list<string> modules whose portal is switched off in this release. */
    private static function withdrawnModules(): array
    {
        return array_keys(array_filter(
            self::MODULE_PORTALS,
            static fn (Portal $portal): bool => ! $portal->isEnabled(),
        ));
    }
}
