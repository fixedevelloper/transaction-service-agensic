<?php

use Illuminate\Support\Facades\Route;

Route::middleware('api')
    ->group(function () {

        require __DIR__.'/API/frontend.php';
        require __DIR__.'/API/service.php';

    });

