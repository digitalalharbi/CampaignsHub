<?php

declare(strict_types=1);

namespace App\Domains\Requests\Support;

use Illuminate\Support\Str;

/**
 * CP-ISOLATION-001 — an id in a client-portal URL or body is a guess until it is looked up, and a guess of the wrong SHAPE must read exactly
 * like a guess of the right shape that matches nothing.
 *
 * Postgres refuses `where id = 'not-a-uuid'` on a uuid column and `where id = 'abc'` on a bigint one with a driver exception, which the
 * client sees as a 500 carrying the SQL. That answer is different from the 404 a well-formed id that is not theirs gets, so the prober
 * learns the shape of the keys, and the exception text can name tables and columns. A value that cannot be a key of this kind is simply
 * a key that is not there, so callers test the shape first and treat a miss as «not found» without asking the database.
 */
final class PortalIdShape
{
    public static function uuid(string $id): bool
    {
        return Str::isUuid($id);
    }

    /** A positive integer key that fits a signed 64-bit column. */
    public static function number(string $id): bool
    {
        return preg_match('/^[1-9][0-9]{0,17}$/', $id) === 1;
    }
}
