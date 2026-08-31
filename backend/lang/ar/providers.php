<?php

declare(strict_types=1);

/**
 * The eight providers, in the words their own Arabic interfaces use.
 *
 * A key that reaches a screen untranslated is a defect, not a fallback — «snapchat» in the middle of
 * an Arabic sentence is how a product tells its customer it was assembled rather than written. The
 * `google` entry is not a duplicate: stored rows say `google` while the connector registers itself as
 * `google_ads`, and both keys reach this file.
 */
return [
    'meta' => 'ميتا',
    'tiktok' => 'تيك توك',
    'snapchat' => 'سناب شات',
    'x' => 'إكس',
    'linkedin' => 'لينكدإن',
    'google_ads' => 'إعلانات جوجل',
    'google' => 'إعلانات جوجل',
    'salla' => 'سلة',
    'zid' => 'زد',
    'sandbox' => 'بيئة تجريبية',
];
