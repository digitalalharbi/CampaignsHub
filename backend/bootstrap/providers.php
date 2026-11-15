<?php

use App\Domains\Notifications\Engine\NotificationsEngineServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    NotificationsEngineServiceProvider::class,
];
