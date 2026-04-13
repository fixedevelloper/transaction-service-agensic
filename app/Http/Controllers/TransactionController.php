<?php
namespace App\Http\Controllers;

use App\Http\Helpers\Helpers;
use App\Http\Resources\TransactionResource;
use App\Http\Services\FusionPay\FusionPayService;
use App\Http\Services\WacePay\WaceApiInterface;
use App\Http\Services\WacePay\WaceApiService;
use App\Models\Gateway;
use App\Models\Operator;
use App\Models\Sender;
use App\Models\Transaction;
use App\Models\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->header('X-User-Id');

        $transactions = Transaction::with(['sender','beneficiary','ledgerEntries'])
            ->whereHas('sender', fn($q)=>$q->where('user_id',$userId))
            ->get();

        return Helpers::success(TransactionResource::collection($transactions));
    }

    public function store(Request $request)
    {
        $request->validate([
           // 'sender_id' => 'required|exists:senders,id',
            'beneficiary_id' => 'required|exists:beneficiaries,id',
            'amount' => 'required|numeric|min:1',
            'type' => 'required|in:bank,mobile',
            'note' => 'nullable|string',
            'currency' => 'nullable|string',
            'meta_data' => 'nullable|array',
        ]);

        return DB::transaction(function () use ($request) {

            $userId = $request->header('X-User-Id');

            logger(config('api_service_token'));
            // 🔥 1. Récupérer user depuis UserService
            $userResponse = Http::withToken('secret_microservice_123')
                ->get(env('USER_SERVICE_URL') . "/users/$userId");


            if (!$userResponse->successful()) {
                throw new \Exception("UserService indisponible");
            }

            $user = $userResponse->json()['data'];

            $sender = Sender::updateOrCreate(
                ['user_id' => $userId], // condition
                [
                    'name' => $user['name'],
                    'phone' => $user['phone'],
                    'email' => $user['email'],
                    'country' => $user['country']['iso'] ?? null,
                    'address' => $user['address'] ?? null,
                    'identification_number' => $user['identification_number'] ?? null,
                    'identification_type' => $user['identification_type'] ?? null,
                    'identification_expired' => $user['identification_expired'] ?? null,
                ]
            );
            // 🔒 2. Vérifier solde
            if ($user['balance'] < $request->amount) {
                throw new \Exception("Solde insuffisant");
            }
            $meta = $request->input('meta_data', []);
            // 🔥 3. Créer transaction
            $transaction = Transaction::create([
                'sender_id' => $sender->id,
                'beneficiary_id' => $request->beneficiary_id,
                'amount' => $request->amount,
                'type' => $request->type,
                'status' => 'pending',
                'currency' => $request->currency,
                'note' => $request->note,
                'initiated_by' => $userId,
               /* 'meta_data'=>[
                    'payer_code'=>'',
                    'type_transaction'=>'',
                    'payout_country'=>'',
                    'account_number'=>'',
                    'bank_name'=>'',
                    'bank_id'=>'',
                    'branch_number'=>'',
                    'from_country'=>'',
                    'sender_currency'=>'',
                    'receive_currency'=>'',
                    'payout_city'=>'',
                    'comment'=>'',
                    'origin_fond'=>'',
                    'motif_send'=>'',
                    'relation'=>'',
                ],*/
                'meta_data' => array_merge([
                    'flow' => 'init',
                    'source' => 'api',
                ], $meta),
            ]);

            // 🧾 4. Ledger HOLD (pas débit réel)
            Ledger::create([
                'user_id' => $userId,
                'transaction_id' => $transaction->id,
                'type' => 'debit',
                'amount' => $transaction->amount,
                'balance_before' => $user['balance'],
                'balance_after' => $user['balance'],
                'description' => 'Hold for payout ' . $transaction->id,
            ]);

            if ($request->type=='mobile'){
                // 📡 5. Appel payout
                $service = app(FusionPayService::class);

                $payout = $service->payOut([
                    'country_code' => strtolower($transaction->beneficiary->country),
                    'phone' => $transaction->beneficiary->phone,
                    'amount' => $transaction->amount,
                    'method' => $this->matchOperator(
                        $transaction->beneficiary->country,
                        $request->operator
                    ),
                    'webhook_url' => route('moneyfusion_webhook')
                ]);

// ❌ erreur métier
                if (!$payout['success']) {
                    throw new \Exception($payout['message']);
                }

// 🔥 récupération token
                $token = $payout['data']['token'] ?? null;

                if (!$token) {
                   // return Helpers::error("Token payout introuvable");
                    throw new \Exception("Token payout introuvable");
                }

// 🔗 sauvegarde
                $transaction->update([
                    'provider' => 'moneyfusion',
                    'provider_token' => $token
                ]);
            }else{
                $waceservice = app(WaceApiInterface::class);
                $waceservice->sendTransaction($transaction);

            }


            return Helpers::success(
                new TransactionResource($transaction->load(['sender','beneficiary'])),
                'Payout en cours',
                201
            );
        });
    }

    public function deposit(Request $request)
    {
        logger($request->all());
        $data = $request->validate([
            'user_id' => 'required|numeric',
            'phone' => 'required|string',
            'amount' => 'required|numeric|min:1',
            'name' => 'nullable|string',
            'return_url' => 'nullable|string',
            'webhook_url' => 'nullable|string',
        ]);


        try {
            $service = app(FusionPayService::class);

            // 🔥 Générer un order_id unique
            $orderId = uniqid('ORD_');

            $response = $service->payIn([
                'amount' => $data['amount'],
                'user_id' => $data['user_id'],
                'order_id' => $orderId,
                'phone' => $data['phone'],
                'name' => $data['name'] ?? 'Client',
                'return_url' => $data['return_url'] ?? route('payment.return'),
                'webhook_url' => $data['webhook_url'] ?? route('payment.webhook')
            ]);

// ❌ erreur API
            if (!$response['success']) {
                return Helpers::error(
                    $response['message'] ?? 'Erreur paiement',
                    $response
                );
            }

// 🔥 données API
            $dataApi = $response['data'] ?? [];

            $url = $dataApi['url'] ?? null;
            $token = $dataApi['token'] ?? null;

// ❌ sécurité
            if (!$url || !$token) {
                return Helpers::error(
                    'Réponse API invalide (url/token manquant)',
                    $dataApi
                );
            }

// ✅ OK
            return Helpers::success('Paiement initié', [
                'payment_url' => $url,
                'token' => $token,
                'order_id' => $orderId
            ]);

        /*    return Helpers::success([
                'payment_url' => $response['url'],
                'token' => $response['token'],
                'order_id' => $orderId
            ]);*/

        } catch (\Exception $e) {
            return Helpers::error('Erreur serveur', $e->getMessage());
        }
    }

    public function show(Transaction $transaction)
    {
        return Helpers::success(new TransactionResource($transaction->load(['sender','beneficiary','ledgerEntries'])));
    }
    public function calculateFees(Request $request)
    {
        $request->validate([
            'sending_country' => 'required|string',
            'receiving_country' => 'required|string',
            'sending_currency' => 'required|string',
            'receiving_currency' => 'required|string',
            'amount' => 'required|numeric|min:1',
        ]);

        $amount = $request->amount;

        // =========================
        // 1. FRAIS
        // =========================
        $feePercentage = 2; // 2%
        $fees = ($amount * $feePercentage) / 100;

        // Minimum fee
        if ($fees < 100) {
            $fees = 100;
        }

        // =========================
        // 2. TAUX DE CHANGE
        // =========================
        $rate = $this->getExchangeRate(
            $request->sending_currency,
            $request->receiving_currency
        );

        // =========================
        // 3. MONTANT REÇU
        // =========================
        $amountAfterFees = $amount - $fees;
        $receivedAmount = $amountAfterFees * $rate;

        return response()->json([
            'status' => true,
            'data' => [
                'fees' => round($fees, 2),
                'received_amount' => round($receivedAmount, 2),
                'rate' => $rate,
            ]
        ]);
    }

    private function getExchangeRate($from, $to)
    {
        // Cas simple Afrique CFA
        if ($from === $to) {
            return 1;
        }

        // Exemple taux fixe (à remplacer par API réelle)
        $rates = [
            'XAF_XOF' => 1,
            'XAF_USD' => 0.0016,
            'USD_XAF' => 620,
        ];

        $key = "{$from}_{$to}";

        return $rates[$key] ?? 1;
    }
    public function getBankList(Request $request,$country_code)
    {

        $type = $request->type === 'mobile' ? 'mobile_money' : 'bank';

        $operators = Gateway::with('payerCode')
            ->whereHas('payerCode', function ($query) use ($request) {
                $query->where('country_code', $request->country_code);
            })
            ->get();

        return Helpers::success($operators);
    }
    private function matchOperator(?string $country_code, ?string $operator_code): ?string
    {
        if (!$country_code || !$operator_code) {
            return null;
        }

        $country = strtoupper(trim($country_code));
        $operator = strtoupper(trim($operator_code));

        return strtolower("{$operator}-{$country}");
    }
}
