<?php

namespace App\Http\Services\WacePay;

use App\Models\Beneficiary;
use App\Models\Sender;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WaceApiService implements WaceApiInterface
{
    protected $baseUrl;

    public function __construct()
    {
        logger(config('app.WACEPAY_URL'));
        $this->baseUrl = config('app.WACEPAY_URL');
    }

    /**
     * 🔐 Auth
     */
    public function authenticate(): void
    {
        $response = Http::post($this->baseUrl . '/api/v1/login', [
            "email" => config('app.WACEPAY_USERNAME'),
            "password" => config('app.WACEPAY_PASSWORD')
        ]);

        $data = $response->json();

        if (($data['status'] ?? null) === 201) {
            Cache::put('wace_token', $data['access_token'], now()->addMinutes(50));
        } else {
            Log::error('WACE AUTH FAILED', $data);
        }
    }

    /**
     * 🔁 HTTP centralisé
     */
    protected function request(string $endpoint, array $payload = [], string $method = 'POST')
    {
        Log::info('WACE REQUEST INIT', [
            'endpoint' => $endpoint,
            'method' => $method,
            'payload' => $payload,
        ]);

        $token = Cache::get('wace_token');

        if (!$token) {
            Log::warning('WACE TOKEN NOT FOUND - AUTHENTICATION TRIGGERED');
            $this->authenticate();
            $token = Cache::get('wace_token');
        }

        $http = Http::withToken($token)
            ->acceptJson()
            ->timeout(60)
            ->retry(2, 200);

        try {
            $url = $this->baseUrl . '/' . $endpoint;

            $response = $method === 'GET'
                ? $http->get($url, $payload)
                : $http->post($url, $payload);

            Log::info('WACE RESPONSE RAW', [
                'url' => $url,
                'status_http' => $response->status(),
                'body' => $response->body(),
            ]);

            $data = $response->json();

            Log::info('WACE RESPONSE PARSED', [
                'endpoint' => $endpoint,
                'response' => $data,
            ]);

            // 🔄 refresh token auto
            if (($data['status'] ?? null) == 401) {
                Log::warning('WACE TOKEN EXPIRED - REFRESHING TOKEN');

                $this->authenticate();

                return $this->request($endpoint, $payload, $method);
            }

            // ❌ erreur métier WACE
            if (($data['status'] ?? null) != 2000) {
                Log::error('WACE BUSINESS ERROR', [
                    'endpoint' => $endpoint,
                    'payload' => $payload,
                    'response' => $data,
                ]);
            }

            return $data;

        } catch (\Exception $e) {
            Log::error('WACE REQUEST EXCEPTION', [
                'endpoint' => $endpoint,
                'payload' => $payload,
                'message' => $e->getMessage(),
                //'trace'    => $e->getTraceAsString(),
            ]);

            return [
                'status' => 500,
                'message' => 'Internal error',
            ];
        }
    }

    /**
     * 👤 Sender
     * @param Sender $sender
     * @return
     */
    public function createSender(Sender $sender)
    {
        try {

            $fullName = $sender->name;

            // On sépare en deux parties au premier espace rencontré
            $parts = explode(' ', $fullName, 2);

            $firstName = $parts[0];
            $lastName = $parts[1] ?? '';
            // 1. Préparation sécurisée des données (évite les erreurs sur les dates nulles)
            $data = [
                'type' => $sender->account_type ?? 'P',
                'firstName' => $firstName,
                'lastName' => $lastName,
                'phone' => $sender->phone,
                'address' => $sender->address ?? 'douala',
                'city' => $sender->city ?? 'douala',
                'country' => $sender->country,
                'idNumber' => $sender->identification_number,
                'idType' => $sender->identification_type,
                'dateOfBirth' => optional($sender->identification_expired)->format('Y-m-d') ?? '2029-01-12',
                'expire_date' => $sender->identification_expired,
                'dateExpireId' => $sender->identification_expired,
                'email' => $sender->email,
                'gender' => $sender->gender ?? 'M',
                'civility' => $sender->civility ?? "Single",
                'businessName' => $sender->business_name,
                'businessType' => $sender->business_type,
                'nationality' => $sender->country,
                'regiterBusinessDate' => optional($sender->business_register_date)->format('Y-m-d'),

                'updateIfExist' => true,
                'occupation' => $sender->occupation,
                'state' => '',
                'zipcode' => '78958',
                'comment' => 'new sender created',
                'pep' => 0
            ];

            // 2. Appel à la méthode request (assumant qu'elle retourne un array ou lève une exception)
            $response = $this->request('api/v1/sender/create', $data);

            // 3. Vérification de la réponse API (adapter selon le format de retour de votre prestataire)
            if (!$response || (isset($response['status']) && $response['status'] === 'error')) {
                logger()->error("Wace API - Échec création Sender ID: {$sender->id}", [
                    'response' => $response,
                    'payload' => $data
                ]);
                throw new \Exception("Le prestataire a refusé la création de l'expéditeur : " . ($response['message'] ?? 'Erreur inconnue'));
            }

            return $response;

        } catch (\Throwable $e) {
            // Capture toutes les erreurs (même les erreurs de formatage de date)
            logger()->error("Erreur critique createSender: " . $e->getMessage(), [
                'sender_id' => $sender->id,
                'trace' => $e->getTraceAsString()
            ]);

            // On retourne un format cohérent pour que le code appelant sache que ça a échoué
            return [
                'success' => false,
                'message' => "Erreur technique lors de la création de l'expéditeur: " . $e->getMessage()
            ];
        }
    }

    /**
     * 👥 Beneficiary
     * @param Beneficiary $beneficiary
     * @param $senderCode
     * @return
     */
    public function createBeneficiary(Beneficiary $beneficiary, $senderCode)
    {
        try {
            $fullName = $beneficiary->name;

            // On sépare en deux parties au premier espace rencontré
            $parts = explode(' ', $fullName, 2);

            $firstName = $parts[0];
            $lastName = $parts[1] ?? '';
            // 1. Validation du senderCode
            if (empty($senderCode)) {
                throw new \Exception("Le code expéditeur (senderCode) est requis pour créer un bénéficiaire.");
            }

            // 2. Préparation des données avec protection contre les valeurs nulles
            $data = [
                'type' => $beneficiary->account_type ?? 'P',
                'firstName' => $firstName,
                'lastName' => $lastName,
                'phone' => $beneficiary->phone,
                'mobile' => $beneficiary->phone,
                'country' => $beneficiary->country,
                'city' => $beneficiary->city,
                'idNumber' => $beneficiary->identification_number,
                'idType' => $beneficiary->identification_type, // PP-CNI-RCCM
                'dob' => optional($beneficiary->date_birth)->format('Y-m-d')?? '2000-06-04',
                'expire_date' => $beneficiary->identification_expired,
                'email' => $beneficiary->email,
                'businessName' => $beneficiary->business_name,
                'businessType' => $beneficiary->business_type,
                'address' => $beneficiary->address ?? 'Douala',
                'updateIfExist' => true,
                'sender_code' => $senderCode,
            ];

            // 3. Appel API
            $response = $this->request('api/v1/beneficiary/create', $data);

            // 4. Vérification de la réussite métier (selon le format de l'API Wace)
            if (isset($response['status']) && ($response['status'] === 'error' || $response['status'] === false)) {
                logger()->warning("Wace API - Refus création Bénéficiaire", [
                    'beneficiary_id' => $beneficiary->id,
                    'sender_code' => $senderCode,
                    'response' => $response
                ]);
                throw new \Exception("Erreur API Bénéficiaire : " . ($response['message'] ?? 'Réponse invalide'));
            }

            return $response;

        } catch (\Throwable $e) {
            // 5. Logging détaillé pour faciliter le support
            logger()->error("Erreur fatale createBeneficiary : " . $e->getMessage(), [
                'beneficiary_id' => $beneficiary->id,
                'sender_code' => $senderCode,
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            // Retourner un format d'erreur standardisé pour le service appelant
            return [
                'success' => false,
                'message' => "Impossible de créer le bénéficiaire : " . $e->getMessage()
            ];
        }
    }

    /**
     * 💸 BANK
     * @param Transaction $transaction
     * @return array|int[]|null[]
     */
 public function sendTransaction(Transaction $transaction)
{
    // On récupère meta_data pour plus de lisibilité
    $meta = $transaction->meta_data;
    $payload = [
        'payerCode'       => $meta->payer_code ?? null,
        'businessType'    => $meta->type_transaction ?? null,
        'payoutCountry'   => $meta->payout_country ?? null,
        'amountToPaid'    => (float) $transaction->amount,
        'senderCode'      => $transaction->sender->code ?? null,
        'beneficiaryCode' => $transaction->beneficiary->code ?? null,
        'bankAccount'     => $meta->account_number ?? null,
        'bankName'        => $meta->bank_name ?? null,
        'bankId'          => $meta->bank_id ?? null,
        'bankBranch'      => $meta->branch_number ?? '',
         'bankSwCode'      => $meta->bank_swcode ?? '',
        'fromCountry'     => $meta->from_country ?? null,
        'fromCurrency'     => $meta->sender_currency ?? null,
        'sendingCurrency' => $meta->sender_currency ?? 'XAF',
        'receiveCurrency' => $meta->receive_currency ?? 'XAF',
        'payoutCity'      => $meta->payout_city ?? null,
        'comment'         => $meta->comment ?? 'Payout',
        'originFund'      => $meta->origin_fond ?? null,
        'reason'          => $meta->motif_send ?? null,
        'relation'        => $meta->relation ?? null,
    ];

    $res = $this->request('api/v1/transaction/bank/create', $payload);

    // Wace renvoie souvent 200 ou 201 pour un succès de création
    if (!in_array($res['status'] ?? null, [200, 201, 2000])) {
        return [
            'status'  => 'error',
            'message' => $res['message'] ?? 'Transaction failed'
        ];
    }

     $transaction->update([
                'provider' => 'wace',
                'provider_token' => $res['transaction']['reference'],
               'status' => 'processing',
               'debit_status' => 'pending',
            ]);
    // On valide la transaction si l'API demande une validation séparée
    return $this->validate($res['transaction']['reference'] ?? $res['reference']);
}

    /**
     * 📱 MOBILE
     * @param Transaction $transaction
     * @return array|int[]|null[]
     */
    public function sendTransactionMobile(Transaction $transaction)
    {
        $sender = $this->createSender($transaction);

        if (($sender['status'] ?? null) !== 200) {
            return $this->error(5001, 'Sender failed');
        }


        $beneficiary = $this->createBeneficiary($transaction, $sender['sender']['Code']);

       
        if ( !in_array($beneficiary['status'] ?? null, [200, 201, 2000])) {
            return $this->error(5002, 'Beneficiary failed');
        }

        $res = $this->request('api/v1/transaction/wallet/create', [
            'payerCode' => $transaction->gatewayItem->payer_code,
            'businessType' => $transaction->type_transaction,
            'payoutCountry' => $transaction->gatewayItem->country->codeIso2,
            'amountToPaid' => $transaction->amount_total,
            'senderCode' => $sender['sender']['Code'],
            'beneficiaryCode' => $beneficiary['beneficiary']['Code'],
            'mobileReceiveNumber' => $transaction->accountNumber,
            'service' => $transaction->gatewayItem->name,
            'fromCountry' => $transaction->country->country->codeIso2,
            //'sendingCurrency' => $transaction->sender->currency,
            // 'receiveCurrency' => $transaction->gatewayItem->country->currency,
            'payoutCity' => $transaction->city,
            'comment' => $transaction->comment,
            'originFund' => $transaction->origin_fond,
            'reason' => $transaction->motif_send,
            'relation' => $transaction->relation,
        ]);

        if (($res['status'] ?? null) !== 200) {
            return $this->error($res['status'], $res['message'] ?? 'Wallet failed');
        }

        return $this->validate($res['transaction']['reference']);
    }

    /**
     * ✅ Validation
     */
public function validate(string $reference)
{
    $res = $this->request('api/v1/transaction/confirm', [
        'reference' => $reference
    ]);

    // On vérifie si le statut est bien dans les codes de succès
    $isSuccess = in_array($res['status'] ?? null, [200, 201, 2000]);

    if ($isSuccess) {
        // Optionnel : tu peux logger la validation réussie
        logger("Wace Transaction Validée: $reference");
        
        return [
            'success' => true,
            'data' => $res,
            'message' => 'Transaction validée avec succès'
        ];
    }

    // En cas d'échec
    return [
        'success' => false,
        'status' => $res['status'] ?? 500,
        'message' => $res['message'] ?? 'La validation a échoué chez le prestataire'
    ];
}

    public function getStatusTransaction(string $reference)
    {
        return $this->request("api/v1/transaction/status/{$reference}", [], 'GET');
    }

    public function getMotifTransaction(string $type)
    {
        return $this->request("api/v1/transaction/reason/{$type}", [], 'GET');
    }

    public function getOriginFonds(string $type)
    {
        return $this->request("api/v1/transaction/origin_fund/{$type}", [], 'GET');
    }

    public function getBanks($code, $payercode)
    {
        $body = [
            "iso2" => $code,
            "payercode" => $payercode
        ];
        return $this->request("api/v1/transaction/bank/list", $body);
    }

    public function getRelaction(string $type)
    {
        return $this->request("api/v1/transaction/relation", [], 'GET');
    }

    public function postPayoutService(string $codeIso, string $currency)
    {
        $body = [
            "payoutCountry" => $codeIso,
            "payoutCurrency" => $currency
        ];
        return $this->request("api/v1/transaction/payouts/services", $body);
    }

    public function postPayercode(string $codeIso, string $currency, string $service)
    {
        $body = [
            "toCountry" => $codeIso,
            "payoutService" => $service,
            "toCurrency" => $currency
        ];
        return $this->request("api/v1/transaction/payercode", $body);
    }
    private function success($data, $message, $reference)
    {
        return compact('data', 'message', 'reference') + ['status' => 2000];
    }

    private function error($status, $message)
    {
        return compact('status', 'message') + ['data' => null];
    }

}
