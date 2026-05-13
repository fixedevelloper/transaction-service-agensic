<?php


namespace App\Http\Services\Gateways\Mobile;


use App\Http\Helpers\Helpers;
use App\Http\Services\WacePay\WaceApiService;
use App\Models\PayerCode;
use App\Models\Transaction;
use App\Models\Beneficiary;
use App\Models\Sender;
use App\Models\Gateway;
use App\Models\Ledger;
use App\Http\Resources\TransactionResource;
use App\Jobs\ProcessUserDebit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class WacepayMobileService
{
    protected $waceApiService;

    public function __construct(WaceApiService $waceApiService)
    {
        $this->waceApiService = $waceApiService;
    }

    /**
     * Méthode principale de traitement du dispatch
     * @param array $data
     * @param string $userId
     * @return \Illuminate\Http\JsonResponse|mixed
     */
    public function process(array $data, string $userId)
    {
        // --- 1. Pré-chargement et Validations ---
        $beneficiary = Beneficiary::findOrFail($data['beneficiary_id']);
        $sender = Sender::findOrFail($data['sender_id']);
        $gateway = Gateway::where('code', $data['operator'] ?? null)->first();

        if (!$gateway && $data['type'] !== 'mobile') {
            return Helpers::error("Opérateur invalide ou manquant", 400);
        }

        try {
            // --- 2. Appel UserService (Vérification solde hors lock DB) ---
            $userResponse = Http::withToken(config('services.user_service.token'))
                ->timeout(10)
                ->get(config('services.user_service.url') . "/users/$userId");

            if (!$userResponse->successful()) {
                return Helpers::error("Service de compte indisponible.", 503);
            }

            $user = $userResponse->json()['data'];

            if ($user['balance'] < $data['amount']) {
                return Helpers::error("Solde insuffisant pour cette opération.", 400);
            }

            // --- 3. Début de la Transaction DB Locale ---
            return DB::transaction(function () use ($data, $userId, $user, $beneficiary, $sender, $gateway) {

                // Détermination du payer_code via une logique interne (ex: pays + opérateur)
                $operatorInfo = $this->getMobileWace($beneficiary->country, $data['operator'] ?? '');

                $meta = array_merge([
                    'flow'             => 'init',
                    'source'           => 'api',
                    'payer_code'       => $operatorInfo?->payer_code,
                    'type_transaction' => ($sender->account_type ?? 'P') . ($beneficiary->account_type ?? 'P'),
                    'payout_country'   => $beneficiary->country,
                    'account_number'   => $beneficiary->mobile_wallet,
                    'from_country'     => $sender->country,
                    'sender_currency'  => 'XAF',
                    'receive_currency' => $data['currency'],
                    'payout_city'      => $beneficiary->city,
                    'origin_fond'      => $data['origin_fond'] ?? 'Salary',
                    'motif_send'       => $data['motif_send'] ?? 'Family Support',
                    'relation'         => $data['relation'] ?? 'Friend',
                ], $data['meta_data'] ?? []);

                // Création de la transaction locale en statut 'pending'
                $transaction = Transaction::create([
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

                // Ledger HOLD : On marque l'intention de débit
                Ledger::create([
                    'user_id'        => $userId,
                    'transaction_id' => $transaction->id,
                    'type'           => 'debit',
                    'amount'         => $transaction->amount,
                    'balance_before' => $user['balance'],
                    'balance_after'  => $user['balance'], // Hold ne change pas le balance_after immédiatement
                    'description'    => "Hold for payout #{$transaction->id}",
                ]);

                $transaction->refresh();

                // --- 4. Intégration avec l'API Wace ---

                // A. Enregistrement / Update de l'expéditeur chez Wace
                $sResp = $this->waceApiService->createSender($transaction->sender);
                if (($sResp['status'] ?? 0) !== 201 && ($sResp['status'] ?? 0) !== 200) {
                    throw new Exception("Wace Sender: " . ($sResp['message'] ?? 'Erreur créateur expéditeur'));
                }
                $sCode = $sResp['sender']['Code'] ?? $sResp['code'] ?? $transaction->sender->code;
                $transaction->sender->update(['code' => $sCode]);

                // B. Enregistrement du bénéficiaire chez Wace
                $bResp = $this->waceApiService->createBeneficiary($transaction->beneficiary, $sCode);
                if (($bResp['status'] ?? 0) !== 201 && ($bResp['status'] ?? 0) !== 200) {
                    throw new Exception("Wace Beneficiary: " . ($bResp['message'] ?? 'Erreur créateur bénéficiaire'));
                }
                $bCode = $bResp['beneficiary']['Code'] ?? $bResp['Code'] ?? $transaction->beneficiary->code;
                $transaction->beneficiary->update(['code' => $bCode]);

                // C. Envoi de la transaction Mobile Money
                $wTx = $this->waceApiService->sendTransactionMobile($transaction);

                Log::info("Wace Transaction Attempt:", ['response' => $wTx]);

                // Validation de la réponse Wace (Codes succès : 200, 201, 2000)
                $statusCode = $wTx['data']['status'] ?? $wTx['status'] ?? 0;
                if (!in_array($statusCode, [200, 201, 2000])) {
                    throw new Exception("Erreur prestataire Wace : " . ($wTx['message'] ?? 'Transaction refusée'));
                }

                // D. Finalisation locale
                $transaction->update([
                    'provider' => 'wace',
                    'status'   => 'pending' // En attente du webhook de succès final
                ]);

                // Déclenchement du Job pour finaliser le débit réel du solde utilisateur
                ProcessUserDebit::dispatch($transaction);

                return Helpers::success(
                    new TransactionResource($transaction->load(['sender', 'beneficiary'])),
                    'Transaction Mobile initiée avec succès',
                    201
                );
            });

        } catch (Exception $e) {
            Log::error("Echec Payout Wace: " . $e->getMessage());
            return Helpers::error($e->getMessage(), 500);
        }
    }

    /**
     * Helper interne pour mapper les opérateurs vers les codes Wace
     */
    protected function getMobileWace($country, $operatorCode)
    {
        return PayerCode::query()
            ->where('country_code', $country)
            ->where('service_code', $operatorCode)
            ->first();
    }
}
