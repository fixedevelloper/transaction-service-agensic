<?php


namespace App\Http\Services\Gateways\Mobile;


use App\Http\Helpers\Helpers;
use App\Http\Resources\TransactionResource;
use App\Http\Services\FusionPay\FusionPayService;
use App\Jobs\ProcessUserDebit;
use App\Models\Beneficiary;
use App\Models\Ledger;
use App\Models\Sender;
use App\Models\Transaction;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;


class MoneyFusionService
{
    protected $fusionPayService;

    public function __construct(FusionPayService $fusionPayService)
    {
        $this->fusionPayService = $fusionPayService;
    }

    public function process(array $data, string $userId)
    {
        try {
            // 1. Préparation et validation des données
            $beneficiary = Beneficiary::findOrFail($data['beneficiary_id']);
            $sender = Sender::findOrFail($data['sender_id']);
            $user = $this->fetchUserData($userId);

            if ($user['balance'] < $data['amount']) {
                return Helpers::error("Solde insuffisant pour cette opération.", 400);
            }

            // 2. Opérations de base de données atomiques
            $transaction = DB::transaction(function () use ($data, $userId, $beneficiary, $sender, $user) {

                $transaction = $this->createTransaction($data, $userId, $beneficiary, $sender);

                $this->createLedgerHold($transaction, $user);

                return $transaction;
            });

            // 3. Appel au fournisseur externe (FusionPay)
            return $this->initiateExternalPayout($transaction, $data['operator']);

        } catch (Exception $e) {
            Log::error("MoneyFusion Error: " . $e->getMessage(), ['user_id' => $userId, 'data' => $data]);
            return Helpers::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }

    /**
     * Récupère les infos de l'utilisateur via le microservice
     * @param string $userId
     * @return array
     * @throws Exception
     */
    private function fetchUserData(string $userId): array
    {
        $response = Http::withToken(config('services.user_service.token'))
            ->timeout(10)
            ->get(config('services.user_service.url') . "/users/$userId");

        if (!$response->successful()) {
            throw new Exception("Service de compte indisponible.", 503);
        }

        return $response->json()['data'];
    }

    /**
     * Initialise la transaction en base de données
     * @param array $data
     * @param string $userId
     * @param Beneficiary $beneficiary
     * @param Sender $sender
     * @return Transaction
     */
    private function createTransaction(array $data, string $userId, Beneficiary $beneficiary, Sender $sender): Transaction
    {
        $meta = array_merge([
            'flow'             => 'init',
            'source'           => 'api',
            'receive_currency' => $data['currency'],
            'payout_city'      => $beneficiary->city,
            'origin_fond'      => $data['origin_fond'] ?? 'Salary',
            'motif_send'       => $data['motif_send'] ?? 'Family Support',
            'relation'         => $data['relation'] ?? 'Friend',
        ], $data['meta_data'] ?? []);

        return Transaction::create([
            'sender_id'      => $sender->id,
            'beneficiary_id' => $beneficiary->id,
            'amount'         => $data['amount'],
            'type'           => $data['type'],
            'status'         => 'pending',
            'currency'       => $data['currency'],
            'note'           => $data['note'] ?? null,
            'initiated_by'   => $userId,
            'meta_data'      => $meta,
        ]);
    }

    /**
     * Crée une trace Ledger (Hold)
     */
    private function createLedgerHold(Transaction $transaction, array $user): void
    {
        Ledger::create([
            'user_id'        => $transaction->initiated_by,
            'transaction_id' => $transaction->id,
            'type'           => 'debit',
            'amount'         => $transaction->amount,
            'balance_before' => $user['balance'],
            'balance_after'  => $user['balance'], // Le débit réel se fera via le Job
            'description'    => "Hold for payout #{$transaction->id}",
        ]);
    }

    /**
     * Gère la communication avec le service tiers
     * @param Transaction $transaction
     * @param string $operatorCode
     * @return \Illuminate\Http\JsonResponse
     * @throws Exception
     */
    private function initiateExternalPayout(Transaction $transaction, string $operatorCode)
    {
        $payout = $this->fusionPayService->payOut([
            'country_code' => strtolower($transaction->beneficiary->country),
            'phone'        => $transaction->beneficiary->mobile_wallet,
            'amount'       => $transaction->amount,
            'method'       => $this->matchOperator($transaction->beneficiary->country, $operatorCode),
            'webhook_url'  => route('moneyfusion_webhook')
        ]);

        if (!$payout['success']) {
            // Optionnel : Update le statut en 'failed' ici si le provider rejette immédiatement
            throw new Exception("FusionPay: " . $payout['message'], 422);
        }

        $transaction->update(['provider' => 'fusionpay']);

        // Finalisation asynchrone
        ProcessUserDebit::dispatch($transaction);

        return Helpers::success(
            new TransactionResource($transaction->load(['sender', 'beneficiary'])),
            'Transaction Mobile initiée avec succès',
            201
        );
    }

    private function matchOperator(?string $country_code, ?string $operator_code): ?string
    {
        if (!$country_code || !$operator_code) return null;
        return strtolower(trim($operator_code) . "-" . trim($country_code));
    }
}
