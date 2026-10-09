<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

use RuntimeException;

/**
 * GA4-INTEGRATION-001 — Google refused, and that is not «no properties».
 *
 * Discovery returning an empty list when the API refused is the failure this exception exists to
 * prevent: a customer shown «no properties found» after a 403 goes looking for a problem in their
 * Analytics account that does not exist, while the real cause — a Cloud project without the Admin
 * API enabled, or a consent that granted nothing — goes unmentioned.
 */
final class Ga4DiscoveryFailed extends RuntimeException {}
