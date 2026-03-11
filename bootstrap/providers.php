<?php

return [
    App\Providers\AppServiceProvider::class,
    Laravel\Fortify\FortifyServiceProvider::class,  // Core Fortify — must come first
    App\Providers\FortifyServiceProvider::class,    // Our overrides — must come after
];
