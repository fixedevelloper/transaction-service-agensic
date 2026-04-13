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
        logger( config('app.WACEPAY_URL'));
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
            'method'   => $method,
            'payload'  => $payload,
        ]);

        $token = Cache::get('wace_token');

        if (!$token) {
            Log::warning('WACE TOKEN NOT FOUND - AUTHENTICATION TRIGGERED');
            $this->authenticate();
            $token = Cache::get('wace_token');
        }

        $http = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 200);

        try {
            $url = $this->baseUrl . '/' . $endpoint;

            $response = $method === 'GET'
                ? $http->get($url, $payload)
                : $http->post($url, $payload);

            Log::info('WACE RESPONSE RAW', [
                'url'        => $url,
                'status_http'=> $response->status(),
                'body'       => $response->body(),
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
                    'payload'  => $payload,
                    'response' => $data,
                ]);
            }

            return $data;

        } catch (\Exception $e) {
            Log::error('WACE REQUEST EXCEPTION', [
                'endpoint' => $endpoint,
                'payload'  => $payload,
                'message'  => $e->getMessage(),
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
    protected function createSender(Sender $sender)
    {
        return $this->request('api/v1/sender/create', [
            'type'=>$sender->account_type, //P if personnal account, B if Business Account
            'firstName' => $sender->first_name,
            'lastName' => $sender->last_name,
            'phone' => $sender->phone,
            'address' => $sender->address,
            'country' => $sender->country,
            'idNumber' => $sender->num_document, //ID number if personnal account, business number (No RCCM, SIRET,VAT) if business account
            'idType' => $sender->identification_document,
            'dateOfBirth' =>  $sender->date_birth->format('Y-m-d'),
            'expire_date' => $sender->expired_document,
            'dateExpireId' =>$sender->expired_document,
            'email' => $sender->email,
            'gender' => $sender->gender, //F:Female, M:Male
            'civility' => $sender->civility ?? "Maried", //Maried, Single,Others
            'businessName' => $sender->business_name,
            'businessType' => $sender->business_type,
            'nationality' => $sender->country,
            'regiterBusinessDate'=>$sender->business_register_date->format('Y-m-d'),
            'updateIfExist' => true,
            'occupation'=>$sender->occupation, //optionnal,
            'state'=>'',//optionnal
            'comment'=>'new sender created',
            'pep'=>0
        ]);
    }

    /**
     * 👥 Beneficiary
     * @param Beneficiary $beneficiary
     * @param $senderCode
     * @return
     */
    protected function createBeneficiary(Beneficiary $beneficiary, $senderCode)
    {
        return $this->request('api/v1/beneficiary/create', [
            'type'=>$beneficiary->account_type, //P if personnal account, B if Business Account
            'firstName' => $beneficiary->first_name,
            'lastName' => $beneficiary->last_name,
            'phone' => $beneficiary->phone,
            'mobile'=>$beneficiary->phone,
            'country' => $beneficiary->country,
            'city' => $beneficiary->city,
            'idNumber' => $beneficiary->num_document,
            'idType' => $beneficiary->identification_document, //PP-CNI-RCCM
            'dob' => $beneficiary->date_birth->format('Y-m-d'),
            // 'dateOfBirth' =>  $beneficiary->date_birth->format('Y-m-d'),
            'expire_date' => $beneficiary->expired_document,
            'email' => $beneficiary->email,
            'businessName' => $beneficiary->business_name,
            'businessType' => $beneficiary->business_type,
            'address'=>$beneficiary->address,
            'updateIfExist' => true,
            'sender_code' => $senderCode,
        ]);
    }

    /**
     * 💸 BANK
     * @param Transaction $transaction
     * @return array|int[]|null[]
     */
    public function sendTransaction(Transaction $transaction)
    {
        $sender = $this->createSender($transaction->sender);

        if (($sender['status'] ?? null) !== 2000) {
            return $this->error(5001, 'Sender failed');
        }

        $beneficiary = $this->createBeneficiary($transaction->beneficiary, $sender['sender']['Code']);

        if (($beneficiary['status'] ?? null) !== 2000) {
            return $this->error(5002, 'Beneficiary failed');
        }

        $res = $this->request('api/v1/transaction/bank/create', [
            'payerCode'=>$transaction->meta_data->payer_code,
            'businessType' => $transaction->meta_data->type_transaction,
            'payoutCountry' => $transaction->meta_data->payout_country,
            'amountToPaid' => (float)$transaction->amount,
            'senderCode' => $sender['sender']['Code'],
            'beneficiaryCode' => $beneficiary['beneficiary']['Code'],
            //'sendingCurrency' => 'XAF',
            'bankAccount' => $transaction->meta_data->account_number,
            'bankName' => $transaction->meta_data->bank_name,
            'bankId'=>$transaction->meta_data->bank_id,
            'bankBranch'=>$transaction->meta_data->branch_number,
            'fromCountry' => $transaction->country->from_country,
            'sendingCurrency' => $transaction->meta_data->sender_currency,
            'receiveCurrency' => $transaction->meta_data->receive_currency,
            'payoutCity' => $transaction->meta_data->payout_city,
            'comment' => $transaction->meta_data->comment,
            'originFund' => $transaction->meta_data->origin_fond,
            'reason' => $transaction->meta_data->motif_send,
            'relation' => $transaction->meta_data->relation,
        ]);

        if (($res['status'] ?? null) !== 200) {
            return $this->error($res['status'], $res['message'] ?? 'Transaction failed');
        }

        return $this->validate($res['transaction']['reference']);
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

        if (($beneficiary['status'] ?? null) !== 200) {
            return $this->error(5002, 'Beneficiary failed');
        }

        $res = $this->request('api/v1/transaction/wallet/create', [
            'payerCode'=>$transaction->gatewayItem->payer_code,
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

        return ($res['status'] ?? null) === 2000
            ? $this->success($res, 'Transaction validée', $reference)
            : $this->error($res['status'], 'Validation failed');
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
