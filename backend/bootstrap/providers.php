<?php

use App\Domains\Notifications\Engine\NotificationsEngineServiceProvider;
use App\Domains\Notifications\Internal\InternalNotificationsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    NotificationsEngineServiceProvider::class,
    InternalNotificationsServiceProvider::class,
];
