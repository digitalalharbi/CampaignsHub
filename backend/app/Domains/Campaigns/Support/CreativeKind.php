<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Support;

use App\Domains\Campaigns\Models\ExternalCreative;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;

/**
 * CONTENT-FILTER-TRUTH-001 — ONE rule for what a creative IS, read by the card and by the filter.
 *
 * There were two. `CreativePresenter::kind()` decided what a card says, and it reads the format
 * string, then the ASSETS when the format is unhelpful. `CreativeRows` decided what «نوع المحتوى =
 * فيديو» returns, and it read `format ilike '%video%'` and nothing else. They disagree in both
 * directions, and both directions were visible in production:
 *
 *  - A Snapchat row with `format = 'SNAP_AD'` and a `video_url` renders as «فيديو» on its card, and
 *    the Video filter REMOVES it. That is the owner's «Snapchat + Sales + Video → no ads match this
 *    selection» over an estate that plainly contains Snapchat videos.
 *  - A row with `format = 'collection_video'` is a COLLECTION on its card — the presenter checks
 *    `collection` first, because a collection whose hero is a film is still a collection — and the
 *    Video filter RETURNS it. So the one shape the filter over-matched was also the one it mislabels.
 *
 * A filter that removes a card labelled «Video» is not a narrow filter; it is a page telling the
 * reader two different things about the same row. So the rule lives here once, in two spellings of
 * the same decision, and `CreativeKindParityTest` fails if they ever part:
 *
 *  - {@see of()} answers for a row in PHP, and the presenter calls it.
 *  - {@see scope()} answers for a SET in SQL, and the filter calls it.
 *
 * ## Why the SQL is written out rather than derived
 *
 * The asset arms cannot be expressed as a `LIKE` on one column: «only a film resolved» is a statement
 * about four columns at once. Writing the predicate by hand is what makes it honest; keeping it
 * beside the PHP, with a parity test over real shapes, is what keeps it true.
 */
final class CreativeKind
{
    /** The kinds a reader can filter by — the vocabulary the two spellings share. */
    public const ALL = ['image', 'video', 'carousel', 'collection', 'catalog'];

    /**
     * What this creative IS, from its format string and, where that is unhelpful, its assets.
     *
     * The order is the decision. `collection` and `catalog` come first because both contain shapes
     * that also read as images or films — «collection_video» names a collection whose hero is a film,
     * and the collection is the more specific truth. The `image` + only-a-film arm comes before the
     * plain `image` arm for the opposite reason: the platform's LABEL loses to the file it actually
     * handed over, but only when nothing still-shaped resolved at all, because a row with both an
     * image and a video is what an image ad with a preview clip looks like.
     */
    public static function of(ExternalCreative $creative): string
    {
        $format = strtolower((string) $creative->format);

        $onlyFilmResolved = $creative->video_url !== null
            && $creative->asset_url === null
            && $creative->thumbnail_url === null
            && $creative->preview_url === null;

        return match (true) {
            str_contains($format, 'collection') => 'collection',
            str_contains($format, 'catalog') || str_contains($format, 'dynamic_product') || str_contains($format, 'dpa') => 'catalog',
            str_contains($format, 'video') => 'video',
            str_contains($format, 'image') && $onlyFilmResolved => 'video',
            str_contains($format, 'carousel') => 'carousel',
            str_contains($format, 'image') => 'image',
            $creative->video_url !== null => 'video',
            $creative->asset_url !== null || $creative->thumbnail_url !== null => 'image',
            default => 'other',
        };
    }

    /**
     * The same decision as a SQL predicate: rows whose kind is one of `$kinds`.
     *
     * Each arm is written as «this kind's condition AND none of the earlier arms matched», which is
     * what a `match (true)` means and what a bare set of `orWhere`s would lose. Getting that wrong is
     * how the filter came to return collections under «Video».
     *
     * @param  list<string>  $kinds
     */
    public static function scope(QueryBuilder $query, array $kinds): void
    {
        $kinds = array_values(array_intersect($kinds, self::ALL));

        if ($kinds === []) {
            return;
        }

        $query->where(function ($outer) use ($kinds): void {
            foreach ($kinds as $kind) {
                $outer->orWhere(fn ($q) => self::arm($q, $kind));
            }
        });
    }

    /** One kind's condition, including the earlier arms it must NOT satisfy. */
    private static function arm(QueryBuilder $q, string $kind): void
    {
        $has = static fn ($b, string $needle) => $b->whereRaw('lower(coalesce(format, \'\')) like ?', ['%'.$needle.'%']);
        $hasNot = static fn ($b, string $needle) => $b->whereRaw('lower(coalesce(format, \'\')) not like ?', ['%'.$needle.'%']);

        /* Every arm below «collection» and «catalog» is unreachable for those formats. */
        $notCollectionOrCatalog = static function ($b) use ($hasNot): void {
            $hasNot($b, 'collection');
            $hasNot($b, 'catalog');
            $hasNot($b, 'dynamic_product');
            $hasNot($b, 'dpa');
        };

        /* «nothing still-shaped resolved» — the four columns the PHP arm reads, together. */
        $onlyFilm = static function ($b): void {
            $b->whereNotNull('video_url')
                ->whereNull('asset_url')
                ->whereNull('thumbnail_url')
                ->whereNull('preview_url');
        };

        match ($kind) {
            'collection' => $has($q, 'collection'),

            'catalog' => $q->where(function ($b) use ($hasNot, $has): void {
                $hasNot($b, 'collection');
                $b->where(function ($c) use ($has): void {
                    $c->where(fn ($d) => $has($d, 'catalog'))
                        ->orWhere(fn ($d) => $has($d, 'dynamic_product'))
                        ->orWhere(fn ($d) => $has($d, 'dpa'));
                });
            }),

            /*
             * Three ways to be a film, and the filter used to know only the first: the format says so;
             * the format says «image» but the only file that resolved is a film; or the format says
             * nothing this rule recognises and a film is present. The second and third are what a
             * Snapchat `SNAP_AD` or `STORY` row looks like.
             */
            'video' => $q->where(function ($b) use ($notCollectionOrCatalog, $has, $hasNot, $onlyFilm): void {
                $notCollectionOrCatalog($b);
                $b->where(function ($c) use ($has, $hasNot, $onlyFilm): void {
                    $c->where(fn ($d) => $has($d, 'video'))
                        ->orWhere(function ($d) use ($has, $onlyFilm): void {
                            $has($d, 'image');
                            $onlyFilm($d);
                        })
                        ->orWhere(function ($d) use ($hasNot, $onlyFilm): void {
                            $hasNot($d, 'video');
                            $hasNot($d, 'image');
                            $hasNot($d, 'carousel');
                            $d->whereNotNull('video_url');
                            /* The final arm is reached only when no earlier one matched. */
                            $onlyFilm($d);
                        });
                });
            }),

            'carousel' => $q->where(function ($b) use ($notCollectionOrCatalog, $hasNot, $has): void {
                $notCollectionOrCatalog($b);
                $hasNot($b, 'video');
                $has($b, 'carousel');
            }),

            'image' => $q->where(function ($b) use ($notCollectionOrCatalog, $hasNot, $has, $onlyFilm): void {
                $notCollectionOrCatalog($b);
                $hasNot($b, 'video');
                $b->where(function ($c) use ($has, $onlyFilm, $hasNot): void {
                    /* Labelled an image, and NOT the «only a film resolved» case that outranks it. */
                    $c->where(function ($d) use ($has, $onlyFilm): void {
                        $has($d, 'image');
                        $d->whereNot(fn ($e) => $onlyFilm($e));
                    })
                        /* Or labelled nothing this rule knows, with a still and no film. */
                        ->orWhere(function ($d) use ($hasNot): void {
                            $hasNot($d, 'image');
                            $hasNot($d, 'carousel');
                            $d->whereNull('video_url')
                                ->where(fn ($e) => $e->whereNotNull('asset_url')->orWhereNotNull('thumbnail_url'));
                        });
                });
            }),

            default => null,
        };
    }
}
