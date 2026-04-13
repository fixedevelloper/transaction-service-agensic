<?php

namespace App\Http\Services\WacePay;

use App\Models\Transaction;

interface WaceApiInterface
{
    public function authenticate(): void;

    public function sendTransaction(Transaction $transaction);

    public function sendTransactionMobile(Transaction $transaction);

    public function getStatusTransaction(string $reference);

    public function validate(string $reference);
    public function getMotifTransaction(string $type);
    public function getOriginFonds(string $type);
    public function getBanks($code, $payercode);
    public function getRelaction(string $type);
    public function postPayoutService(string $codeIso, string $currency);
    public function postPayercode(string $codeIso, string $currency, string $service);
}
