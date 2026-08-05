<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Review;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * REVIEW-001 — one declared requirement of one provider's review.
 *
 * `ready` and `submitted` are kept apart deliberately. «We have done our part» and «the platform has
 * been asked» are different positions, and collapsing them is how a review sits unsubmitted for a
 * month while a board shows it in progress.
 */
final class ProviderReviewItem extends Model
{
    use HasUuidKey;

    protected $fillable = ['provider', 'requirement', 'status', 'note', 'updated_by'];

    public const STATUSES = ['missing', 'ready', 'submitted', 'approved'];
}
