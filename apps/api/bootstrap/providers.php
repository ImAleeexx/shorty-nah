<?php

use App\Providers\AppServiceProvider;
use App\Providers\ClickHouseServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    ClickHouseServiceProvider::class,
    HorizonServiceProvider::class,
];
