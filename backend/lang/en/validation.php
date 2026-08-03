<?php

declare(strict_types=1);

/**
 * English validation overrides.
 *
 * Only the keys this product adds — Laravel's own English messages are already the framework default,
 * so re-listing them here would create a second copy to keep in step with upgrades for no benefit.
 */
return [
    /*
     * PHONE-001 — the message names BOTH accepted shapes on purpose.
     *
     * «Enter a valid number» tells somebody staring at a number they believe is valid nothing at all.
     * Showing the two forms this product accepts is the difference between an error they can act on
     * and one they can only retype.
     */
    'phone_number' => 'Enter a valid mobile number, for example 0501234567 or +966501234567.',
];
