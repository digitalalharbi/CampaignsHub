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
        $withdrawn = array_keys(array_filter(
            self::MODULE_PORTALS,
            static fn (Portal $portal): bool => ! $portal->isEnabled(),
        ));

        return $withdrawn === [] ? $query : $query->whereNotIn('module', $withdrawn);
    }
}
