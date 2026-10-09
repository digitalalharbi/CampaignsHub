<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

use RuntimeException;

/**
 * GA4-INTEGRATION-001 — the Data API refused, and that is not «the site had no traffic».
 *
 * The same rule as `Ga4DiscoveryFailed`, at the reporting step, and the refusal it most often carries
 * is `RESOURCE_EXHAUSTED`: the property's daily token allowance is spent. Swallowing that into an
 * empty result would write a day of zeros into a client's report and leave nobody able to tell it
 * apart from a day nobody visited.
 */
final class Ga4ReportFailed extends RuntimeException {}
