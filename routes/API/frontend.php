<?php

use App\Http\Controllers\CallbackController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SenderController;
use App\Http\Controllers\BeneficiaryController;
use App\Http\Controllers\TransactionController;

Route::middleware(['api'])->group(function () {

    // Senders
    Route::get('senders', [SenderController::class, 'index']);
    Route::post('senders', [SenderController::class, 'store']);
    Route::get('senders/{sender}', [SenderController::class, 'show']);
    Route::put('senders/{sender}', [SenderController::class, 'update']);
    Route::delete('senders/{sender}', [SenderController::class, 'destroy']);

    // Beneficiaries
    Route::get('beneficiaries', [BeneficiaryController::class, 'index']);
    Route::post('beneficiaries', [BeneficiaryController::class, 'store']);
    Route::get('beneficiaries/{beneficiary}', [BeneficiaryController::class, 'show']);
    Route::put('beneficiaries/{beneficiary}', [BeneficiaryController::class, 'update']);
    Route::delete('beneficiaries/{beneficiary}', [BeneficiaryController::class, 'destroy']);

    // Transactions
    Route::get('transactions', [TransactionController::class, 'index']);
    Route::get('my-transactions', [TransactionController::class, 'my_transactions']);
    Route::post('transactions', [TransactionController::class, 'store']);
    Route::get('transactions/{transaction}', [TransactionController::class, 'show']);
    Route::post('calculate-fees', [TransactionController::class, 'calculateFees']);
    Route::get('banks/{country_code}', [TransactionController::class, 'getBankList']);
});
Route::post('/moneyfusion/webhook', [CallbackController::class, 'payoutWebhook'])->name('moneyfusion_webhook');
Route::prefix('payments')->group(function () {
    Route::get('/link', [PaymentController::class, 'index']);
    Route::post('/link', [PaymentController::class, 'create']);
    Route::get('/link/{code}', [PaymentController::class, 'show']);

    Route::post('/pay', [PaymentController::class, 'pay']);
    Route::get('/status/{code}', [PaymentController::class, 'status']);

});

// webhook public
Route::post('/webhooks/payment', [CallbackController::class, 'handle']);
Route::get('/customer/dashboard', [
    DashboardController::class,
    'index'
]);
