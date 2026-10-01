<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

// TelescopeServiceProvider is registered from AppServiceProvider::register()
// only when Telescope is installed (it's a require-dev package).
return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
];
