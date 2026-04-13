<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth.api'])->group(function () {

    Route::post('/deposit', [TransactionController::class, 'deposit']);

});
