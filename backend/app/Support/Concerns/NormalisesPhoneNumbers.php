<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Support\PhoneNumber;

/**
 * PHONE-001 — a model normalises its own phone columns on the way in.
 *
 * ## Why on the model and not at the call sites
 *
 * A phone number reaches `contacts` from a registration form, a lead conversion, a CSV import, a
 * portal profile edit, a seeder and a backfill command. Normalising at each of those means six places
 * that must remember, and the seventh — written next month — will not. The whole problem this feature
 * exists to solve is that the readings had already drifted apart exactly that way.
 *
 * Setting it here makes the rule structural: assigning `$contact->phone = '050 123 4567'` stores
 * `+966501234567`, from any caller, including ones that predate this trait and ones nobody has written
 * yet. It is the same reasoning that put the audit log in observers rather than in controllers.
 *
 * ## Unreadable input is kept, not discarded
 *
 * If `normalise()` cannot read the value, the ORIGINAL string is stored. That looks lax and is the
 * deliberate choice: this trait is a normaliser, not a validator, and silently nulling a field because
 * a model could not parse it would destroy data the user typed and believes is saved. Rejecting bad
 * input is `PhoneNumberRule`'s job, at the edge, where there is a form to show the error on.
 *
 * ## Usage
 *
 * Declare the columns; the trait does the rest.
 *
 *     protected array $phoneColumns = ['phone', 'contact_phone'];
 */
trait NormalisesPhoneNumbers
{
    public static function bootNormalisesPhoneNumbers(): void
    {
        static::saving(function ($model): void {
            foreach ($model->phoneColumns() as $column) {
                if (! $model->isDirty($column)) {
                    continue;
                }

                $raw = $model->getAttribute($column);
                if ($raw === null || $raw === '') {
                    continue;
                }

                $model->setAttribute($column, PhoneNumber::normalise((string) $raw) ?? $raw);
            }
        });
    }

    /** @return list<string> */
    public function phoneColumns(): array
    {
        /** @var list<string> */
        return property_exists($this, 'phoneColumns') ? $this->phoneColumns : ['phone'];
    }
}
