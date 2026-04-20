<?php


namespace App\Http\Controllers;


use App\Models\Ledger;
use App\Models\PaymentLink;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CallbackController
{

    public function payoutWebhook(Request $request)
    {
        $token = $request->tokenPay;
        $event = $request->event;

        $transaction = Transaction::where('provider_token', $token)->first();

        if (!$transaction) {
            return response()->json(['error' => 'Transaction not found'], 404);
        }

        if ($transaction->status !== 'pending') {
            return response()->json(['status' => 'already processed']);
        }

        $user = $transaction->user;

        if ($event === 'payout.session.completed') {

            DB::transaction(function () use ($transaction, $user) {

                $balance_before = $user->balance;
                $balance_after = $balance_before - $transaction->amount;

                // 💰 Débit réel ici
                $user->balance = $balance_after;
                $user->save();

                Ledger::create([
                    'user_id' => $user->id,
                    'transaction_id' => $transaction->id,
                    'type' => 'debit',
                    'amount' => $transaction->amount,
                    'balance_before' => $balance_before,
                    'balance_after' => $balance_after,
                    'description' => 'Payout success',
                ]);

                $transaction->update(['status' => 'completed']);
            });

        } elseif ($event === 'payout.session.cancelled') {

            // ❌ Libérer le hold
            $transaction->update(['status' => 'failed']);
        }

        return response()->json(['status' => 'ok']);
    }
    public function handle(Request $request)
    {
        Log::info("WEBHOOK RECEIVED", $request->all());

        $token = $request->token ?? null;
        $status = $request->status ?? null;

        $payment = PaymentLink::where('provider_token', $token)->first();

        if (!$payment) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($status === 'success') {
            $payment->markAsPaid($token);
        }

        if ($status === 'failed') {
            $payment->markAsFailed();
        }

        return response()->json(['success' => true]);
    }
}
