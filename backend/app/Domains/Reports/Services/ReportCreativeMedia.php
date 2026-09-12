<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativePresenter;
use Illuminate\Support\Collection;

/**
 * REPORT-CREATIVE-MEDIA-001 — the report's pictures are resolved when it is READ, not when it is stored.
 *
 * ## The defect this exists for
 *
 * The owner opened a real Detailed Report on production and found creative previews missing, with
 * rows reading «لا يوجد غلاف» for creatives whose media the Content library shows perfectly well.
 * A client reads that document: it says the agency ran ads nobody has a picture of.
 *
 * `CreativeRows::lean()` builds every roster row and never sets `preview`. The renderer draws
 * `<AdPoster preview={row.preview ?? null} />` and `RosterRow.preview` is optional, so the field was
 * always absent, the poster was always handed `null`, and the absence state fired for every creative
 * in the roster whatever media it had. Nothing objected: `preview?:` says it may be missing and
 * `?? null` says that is fine.
 *
 * ## Why the fix is not «put the preview back in `lean()`»
 *
 * `lean()` leaves it out ON PURPOSE, and the reason is good: «no preview, so nothing here can leak a
 * signed URL into a stored document that outlives it.» A snapshot is stored and read months later.
 * A signed platform URL written into it is dead long before then — and worse, it is a credential
 * sitting inside a document that was deliberately shared with somebody outside the agency.
 *
 * Both constraints are real, and they stop conflicting once the question is asked at the right
 * moment. A report is a claim about a PERIOD, so its figures must stay the snapshot's: re-pricing
 * them on open would quietly turn a March report into a September one. Its MEDIA is not a claim
 * about a period at all — it is «what does this creative look like», which has one true answer now.
 * So the figures are stored and the pictures are resolved on every open.
 *
 * ## One resolver
 *
 * Through {@see CreativePresenter::preview()} — the same call the Content library, the analytics
 * table and the quick popup all make, which already owns the recovery chain the requirement lists:
 * the ad's own hero, then the first usable card of a carousel or collection, then a stated absence
 * with the reason it is absent. A report-only media resolver is what would let a report and the
 * library disagree about one creative, and it is explicitly refused.
 *
 * ## Before the client boundary, because it is keyed on the id
 *
 * {@see ClientEntityBoundary::roster()} strips `id` — a client report must not carry the agency's
 * internal identifiers. This resolves by that id, so it has to run first. Attaching the picture by
 * keeping the id in the client payload instead would trade one owner requirement for another.
 */
final class ReportCreativeMedia
{
    public function __construct(private readonly CreativePresenter $presenter) {}

    /**
     * Every creative-bearing section of a report snapshot, with fresh media.
     *
     * @param  array<string, mixed>  $data  the internal snapshot
     * @return array<string, mixed>
     */
    public function refresh(array $data): array
    {
        /*
         * Section by section, through the one implementation — {@see attach()}.
         *
         * A creative that no longer exists keeps whatever the snapshot held: deleting a stored
         * preview would turn «here is what it looked like» into «no cover» for an ad whose only
         * remaining record IS this document, which is the defect applied to the one case where the
         * stored copy is the better answer. `attach()` leaves such a row untouched.
         */
        return $this->walkSections($data, fn (array $rows): array => $this->attach($rows));
    }

    /**
     * One list of creative rows, given the media the library would show them with.
     *
     * Public because the LIVE path cannot use {@see refresh()} and the difference is not a detail.
     * `LiveReportService::build()` applies {@see ClientEntityBoundary} to each section INSIDE
     * itself, so a refresh placed after it walks over rows whose `id` has already been stripped and
     * silently changes nothing — which is exactly what the first version of this fix did, on the
     * one path the owner's own report uses. The live builder therefore attaches the media where the
     * ids still exist, section by section, and the boundary runs afterwards as it always did.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function attach(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $ids = [];

        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['id'] ?? null) && $row['id'] !== '') {
                $ids[$row['id']] = true;
            }
        }

        if ($ids === []) {
            return $rows;
        }

        $creatives = $this->load(array_keys($ids));

        return array_map(function ($row) use ($creatives) {
            if (! is_array($row)) {
                return $row;
            }

            $creative = $creatives->get((string) ($row['id'] ?? ''));

            if ($creative === null) {
                return $row;
            }

            $row['preview'] = $this->presenter->preview($creative);

            return $row;
        }, $rows);
    }

    /**
     * The creatives a document names, in one query.
     *
     * `withoutGlobalScopes` is deliberate and safe: a SHARED report is read with no tenant context
     * at all — the reader is a stranger with a token — and the ids come from the report's own
     * payload, which was built under the tenant's scope. Resolving only what the document itself
     * names cannot reach another tenant's creative.
     *
     * @param  list<string>  $ids
     * @return Collection<string, ExternalCreative>
     */
    private function load(array $ids)
    {
        return ExternalCreative::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(static fn (ExternalCreative $c): string => (string) $c->getKey());
    }

    /**
     * The sections that hold creative rows, named rather than discovered.
     *
     * A recursive sweep for «anything with an id» would also find campaigns, ad sets, platforms and
     * funnel stages, and would hand each of them a creative's preview envelope. The document's shape
     * is known; guessing at it is how a report comes to carry a picture of something else.
     *
     * @param  array<string, mixed>  $data
     * @param  callable(list<array<string, mixed>>): list<array<string, mixed>>  $each
     * @return array<string, mixed>
     */
    private function walkSections(array $data, callable $each): array
    {
        foreach (['ads', 'ads_roster', 'worst_creatives', 'top_creatives'] as $key) {
            if (is_array($data[$key] ?? null)) {
                $data[$key] = $each(array_values($data[$key]));
            }
        }

        // The objective groups nest their own ranked ads — REPORT-AD-PREVIEW-001 §A.
        if (is_array($data['ads_groups'] ?? null)) {
            $data['ads_groups'] = array_map(static function ($group) use ($each) {
                if (is_array($group) && is_array($group['ads'] ?? null)) {
                    $group['ads'] = $each(array_values($group['ads']));
                }

                return $group;
            }, $data['ads_groups']);
        }

        return $data;
    }
}
