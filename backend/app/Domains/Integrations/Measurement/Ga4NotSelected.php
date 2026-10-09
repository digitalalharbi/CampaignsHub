<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

use RuntimeException;

/**
 * GA4-INTEGRATION-001 — asked to sync a property nobody selected.
 *
 * The normal state for most of an agency's discovered estate, and therefore not an error in the
 * Google sense: nothing is wrong with the property, it simply is not this project's. Raised rather
 * than skipped so the caller says «select it for a project first» instead of reporting a successful
 * sync that wrote nothing.
 */
final class Ga4NotSelected extends RuntimeException {}
