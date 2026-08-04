<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Requests\Models\ExternalRequest;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Str;

/**
 * LOGIN-UNIFIED-001 — which sign-in method an identifier uses, decided by the SERVER.
 *
 * ## Why this exists
 *
 * The sign-in page used to ask the visitor to pick a portal: «إدارة الحملات», «وكالة», «متابعة
 * الطلبات». That question is one only the system can answer correctly. A client who picked
 * «إدارة الحملات» was shown a password field their account has never had, and an operator who picked
 * «متابعة الطلبات» was asked for a code that would never arrive. Both look like the product is
 * broken, and neither is something the person could have got right — the answer depends on the
 * account, not on the visitor's understanding of the words.
 *
 * So there is one door now, and it asks for an identifier only. This class answers the one question
 * that made the choice necessary: does this identifier sign in with a **password** or with a
 * **one-time code**?
 *
 * ## Why an unknown identifier answers "password"
 *
 * Because the alternative leaks. If an unknown email answered `code`, this endpoint would tell any
 * stranger which addresses belong to a client of which agency, and it would send a real code to an
 * address nobody here has a relationship with. Answering `password` puts them on the password form,
 * where a wrong identifier gets the same «بيانات الدخول غير صحيحة» as a wrong password — the
 * existing, deliberately uninformative answer.
 *
 * The one exception is an identifier that is a PHONE. No account on this platform signs in with a
 * phone and a password, so `code` there reveals nothing that the shape of the input did not already
 * say.
 *
 * ## What this deliberately does NOT do
 *
 * It does not say whether the account exists, which portal it holds, or whether it is suspended.
 * Those are answered after authentication, by `PortalResolver`. This resolver only picks which form
 * to render.
 */
final class SignInMethodResolver
{
    public const PASSWORD = 'password';

    public const CODE = 'code';

    /**
     * @return array{method: string, channel: string}
     */
    public function resolve(string $identifier): array
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return ['method' => self::PASSWORD, 'channel' => 'email'];
        }

        // A phone can only ever be a one-time code: no password account is addressable by one.
        if (! str_contains($identifier, '@')) {
            return ['method' => self::CODE, 'channel' => 'sms'];
        }

        $email = Str::lower($identifier);

        /*
         * A platform user wins over a contact record with the same address.
         *
         * An agency operator is frequently also named as the contact on a request they filed for a
         * client. Answering `code` for them would take somebody with a real password and a real
         * portal and send them round the one-time-code path into `/portal`, which holds none of
         * their work. The stronger credential is the right answer whenever both exist.
         */
        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            return ['method' => self::PASSWORD, 'channel' => 'email'];
        }

        /*
         * A client contact is somebody whose address appears on a request. `withoutGlobalScopes` is
         * required and correct here: this runs before any session exists, so there is no tenant in
         * context to scope by, and the question being asked is deliberately cross-tenant — "does
         * this address belong to a client anywhere". Nothing about the tenant is returned.
         */
        $isContact = ExternalRequest::query()
            ->withoutGlobalScopes()
            ->whereRaw('lower(contact_email) = ?', [$email])
            ->exists();

        return $isContact
            ? ['method' => self::CODE, 'channel' => 'email']
            : ['method' => self::PASSWORD, 'channel' => 'email'];
    }

    /**
     * The destination an identifier should be sent to when it takes the code path.
     *
     * Kept beside `resolve()` so the two answers cannot drift: a caller that learns the method has
     * everything it needs to start the flow, without a second rule living in the browser.
     */
    public function normaliseForCode(string $identifier, string $channel): string
    {
        return $channel === 'sms'
            ? PhoneNumber::normalise($identifier) ?? trim($identifier)
            : Str::lower(trim($identifier));
    }
}
