<?php

declare(strict_types=1);

use App\Domains\Notifications\Providers\NullEmailProvider;
use App\Domains\Notifications\Providers\NullSmsProvider;
use App\Domains\Notifications\Providers\NullWhatsAppProvider;
use App\Domains\Notifications\Providers\TwilioWhatsAppProvider;

return [
    /*
    |---------------------------------------------------------------------------
    | Delivery channel adapters
    |---------------------------------------------------------------------------
    | Each outbound channel maps to a MessageProvider implementation. The Null*
    | adapters are placeholders that never send and never report success — the
    | delivery ledger records `awaiting_provider_credentials`. Wire a real
    | provider by binding a configured class here; nothing else changes.
    */
    'channels' => [
        'email' => env('NOTIFICATION_EMAIL_PROVIDER', NullEmailProvider::class),
        // The Twilio adapter is registered only once a WhatsApp sender exists; until then the honest Null adapter stays.
        'whatsapp' => (env('TWILIO_WHATSAPP_FROM') || env('TWILIO_WHATSAPP_MESSAGING_SERVICE_SID'))
            ? TwilioWhatsAppProvider::class
            : NullWhatsAppProvider::class,
        'sms' => NullSmsProvider::class,
    ],

    // Kept explicit so a static scan proves the defaults are the honest Null adapters.
    'defaults' => [
        'email' => NullEmailProvider::class,
        'whatsapp' => NullWhatsAppProvider::class,
        'sms' => NullSmsProvider::class,
    ],
];
