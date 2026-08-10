<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * PAY-TOKEN-001 — a card the platform may charge again, without the customer present.
 *
 * The token is the whole credential and it is encrypted at rest. `brand`, `last4` and the expiry are
 * labels so somebody can tell one card from another in `/admin` and in their own billing page; none
 * of them authorises anything, and none of them is enough to reconstruct a card.
 *
 * A method is retired by setting `detached_at`, never by deleting the row. The row is the reason a
 * charge in the ledger exists — remove it and a payment two months ago is attributed to nothing.
 */
final class SubscriptionPaymentMethod extends Model
{
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'provider', 'provider_token', 'provider_customer_id',
        'brand', 'last4', 'exp_month', 'exp_year', 'is_default', 'last_used_at', 'detached_at',
    ];

    /**
     * `encrypted` on the token, and it is load-bearing.
     *
     * A stored token is a bearer credential for somebody's card: anybody who can read the column can
     * charge it. Encrypting means a database dump, a replica, or a leaked backup is not a wallet —
     * it is ciphertext against `APP_KEY`, which lives somewhere else entirely.
     */
    protected $casts = [
        'provider_token' => 'encrypted',
        'is_default' => 'boolean',
        'exp_month' => 'integer',
        'exp_year' => 'integer',
        'last_used_at' => 'datetime',
        'detached_at' => 'datetime',
    ];

    /**
     * Never serialise the credential.
     *
     * Belt and braces beside the encryption: an API resource that forgot to whitelist columns, a
     * `dd($method)` in a controller, or a log line that dumps a model would otherwise put the
     * decrypted token somewhere it can be read.
     *
     * @var list<string>
     */
    protected $hidden = ['provider_token'];

    public function isUsable(): bool
    {
        return $this->detached_at === null;
    }

    /**
     * Has the card already expired?
     *
     * Checked to the END of the stated month, because a card marked 08/2026 is good through the 31st
     * of August — treating the 1st as expired would refuse a perfectly live card for thirty days.
     * An unknown expiry is not treated as expired: several providers publish none, and refusing on
     * absence would disable every method they issue.
     */
    public function isExpired(?\DateTimeInterface $at = null): bool
    {
        if ($this->exp_month === null || $this->exp_year === null) {
            return false;
        }

        $now = $at === null ? now() : Carbon::instance($at);

        return $this->exp_year < $now->year
            || ($this->exp_year === $now->year && $this->exp_month < $now->month);
    }

    /** «Visa ···· 4242» — what a person recognises, and all anyone here ever sees. */
    public function label(): string
    {
        $brand = $this->brand === null || $this->brand === '' ? 'Card' : $this->brand;

        return $this->last4 === null ? $brand : $brand.' ···· '.$this->last4;
    }
}
