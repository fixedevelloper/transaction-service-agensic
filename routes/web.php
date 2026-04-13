<?php

use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
// web.php
Route::get('/pay/{order}', [PaymentController::class, 'show']);
Route::get('/payment/success/{order}', [PaymentController::class, 'success']);
Route::get('/payment/cancel/{order}', [PaymentController::class, 'cancel']);
