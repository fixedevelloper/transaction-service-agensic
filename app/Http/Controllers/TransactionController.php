<?php

namespace App\Http\Controllers;

use App\Http\Helpers\Helpers;
use App\Http\Resources\TransactionResource;
use App\Http\Services\FusionPay\FusionPayService;
use App\Http\Services\microService\UserServiceClient;
use App\Http\Services\WacePay\WaceApiInterface;
use App\Http\Services\WacePay\WaceApiService;
use App\Jobs\ProcessUserDebit;
use App\Models\Beneficiary;
use App\Models\Gateway;
use App\Models\Operator;
use App\Models\Sender;
use App\Models\Transaction;
use App\Models\Ledger;
use App\Models\WaceData;
use App\Notifications\TransactionProcessed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        // 1. Initialisation de la requête avec les relations locales
        // On utilise withCount pour savoir s'il y a des écritures comptables (Ledger)
        $query = Transaction::with(['sender', 'beneficiary'])->withCount('ledgerEntries');

        // 2. Recherche textuelle (Référence, Note ou Nom du bénéficiaire)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('note', 'like', "%{$search}%")
                    ->orWhereHas('beneficiary', function ($sub) use ($search) {
                        $sub->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // 3. Filtres exacts (Statut, Type, Devise)
        $query->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->when($request->currency, fn($q) => $q->where('currency', strtoupper($request->currency)));

        // 4. Pagination et tri
        $transactions = $query->latest()->paginate($request->per_page ?? 15);

        // 5. Retour via la Resource
        return Helpers::success(TransactionResource::collection($transactions));
    }

    /**
     * Affiche le détail d'une transaction interne.
     */
    public function show(Request $request, $id)
    {
        // 1. On récupère la transaction avec ses relations et le compte des entrées ledger
        // On utilise findOrFail pour renvoyer automatiquement une 404 si l'ID est invalide
        $transaction = Transaction::with(['sender', 'beneficiary'])
            ->withCount('ledgerEntries')
            ->findOrFail($id);

        // 2. On peut éventuellement ajouter des logs d'audit ici
        // (ex: qui a consulté cette transaction sensible)

        // 3. On retourne la transaction formatée via la Resource
        return Helpers::success(new TransactionResource($transaction));
    }

    public function my_transactions(Request $request)
    {
        $userId = $request->header('X-User-Id');

        $transactions = Transaction::with(['sender', 'beneficiary', 'ledgerEntries'])
            ->whereHas('sender', fn($q) => $q->where('user_id', $userId))
            ->get();

        return Helpers::success(TransactionResource::collection($transactions));
    }

    public function store(Request $request)
    {
        $request->validate([
            'beneficiary_id' => 'required|exists:beneficiaries,id',
            'amount' => 'required|numeric|min:1',
            'type' => 'required|in:bank,mobile',
            'operator' => 'required_if:type,bank', // Optionnel mais conseillé
            'note' => 'nullable|string',
            'currency' => 'nullable|string',
            'meta_data' => 'nullable|array',
        ]);

        // --- 1. Pré-chargement hors transaction ---
        $userId = $request->header('X-User-Id');
        $beneficiary = Beneficiary::findOrFail($request->beneficiary_id);
        $gateway = Gateway::where('code', $request->operator)->first();

        if (!$gateway && $request->type == 'mobile') {
            return Helpers::error("Opérateur invalide", 400);
        }

        try {
            // --- 2. Appel UserService (Hors transaction DB pour éviter les verrous longs) ---
            $userResponse = Http::withToken(config('services.user_service.token'))
                ->timeout(10)
                ->get(config('services.user_service.url') . "/users/$userId");

            if (!$userResponse->successful()) {
                return Helpers::error("Profil utilisateur introuvable ou service indisponible.", 503);
            }

            $user = $userResponse->json()['data'];

            if ($user['balance'] < $request->amount) {
                return Helpers::error("Solde insuffisant.", 400);
            }

            // --- 3. Début de la Transaction DB ---
            return DB::transaction(function () use ($request, $userId, $user, $beneficiary, $gateway) {

                // Mise à jour du Sender
                $sender = Sender::firstOrNew(['user_id' => $userId]);
                $sender->fill([
                    'name' => $user['name'],
                    'phone' => $user['phone'],
                    'email' => $user['email'],
                    'country' => $user['country_code'] ?? null,
                    'identification_number' => (string) ($user['identification_number'] ?? ''),
                    'identification_type' => $user['identification_type'] ?? null,
                    'identification_expired' => $user['identification_expired'] ?? null,
                ]);

                if ($sender->isDirty()) {
                    $sender->save();
                }

                // Préparation des méta-données
                $meta = array_merge([
                    'flow' => 'init',
                    'source' => 'api',
                    'payer_code' => $gateway?->payerCode?->payer_code,
                    'type_transaction' => ($sender->type ?? 'P') . ($beneficiary->type ?? 'P'),
                    'payout_country' => $beneficiary->country,
                    'account_number' => $beneficiary->bank_account ?? $beneficiary->mobile_wallet,
                    'bank_name' => $gateway->name ?? null,
                    'bank_id' => $gateway->bank_id ?? null,
                    'bank_swcode' => $request->bank_swcode ?? '10002',
                    'from_country' => $sender->country,
                    'sender_currency' => 'XAF',
                    'receive_currency' => $request->currency,
                    'payout_city' => $beneficiary->city,
                    'origin_fond' => $request->origin_fond ?? 'Salary',
                    'motif_send' => $request->motif_send ?? 'Salary',
                    'relation' => $request->relation ?? 'Brother',
                ], $request->input('meta_data', []));

                $transaction = Transaction::create([
                    'sender_id' => $sender->id,
                    'beneficiary_id' => $beneficiary->id,
                    'amount' => $request->amount,
                    'type' => $request->type,
                    'status' => 'pending',
                    'currency' => $request->currency,
                    'note' => $request->note,
                    'initiated_by' => $userId,
                    'meta_data' => $meta,
                ]);

                // Ledger HOLD
                Ledger::create([
                    'user_id' => $userId,
                    'transaction_id' => $transaction->id,
                    'type' => 'debit',
                    'amount' => $transaction->amount,
                    'balance_before' => $user['balance'],
                    'balance_after' => $user['balance'],
                    'description' => "Hold for payout #{$transaction->id}",
                ]);
                $transaction->refresh();
                // --- 4. Exécution du Payout ---
                $this->executePayout($transaction, $request, $gateway);
               ProcessUserDebit::dispatch($transaction);
                return Helpers::success(
                    new TransactionResource($transaction->load(['sender', 'beneficiary'])),
                    'Payout initié avec succès',
                    201
                );
            });

        } catch (\Exception $e) {
            logger()->critical("Echec Payout: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return Helpers::error($e->getMessage(), 500);
        }
    }

    /**
     * Logique d'exécution isolée
     */
    private function executePayout($transaction, $request, $gateway)
    {
        if ($request->type == 'mobile') {
            $payout = app(FusionPayService::class)->payOut([
                'country_code' => strtolower($transaction->beneficiary->country),
                'phone' => $transaction->beneficiary->mobile_wallet,
                'amount' => $transaction->amount,
                'method' => $this->matchOperator($transaction->beneficiary->country, $request->operator),
                'webhook_url' => route('moneyfusion_webhook')
            ]);

            if (!$payout['success'])
                throw new \Exception("FusionPay: " . $payout['message']);

            $transaction->update(['provider' => 'moneyfusion', 'provider_token' => $payout['data']['token']]);
        } else {
            $waceservice = app(WaceApiService::class);

            // Sender Wace
            $sResp = $waceservice->createSender($transaction->sender);
            if (($sResp['status'] ?? 0) !== 201)
                throw new \Exception("Wace Sender: " . ($sResp['message'] ?? 'Erreur'));

            $sCode = $sResp['sender']['Code'] ?? $sResp['code'];
            $transaction->sender->update(['code' => $sCode]);

            // Beneficiary Wace
            $bResp = $waceservice->createBeneficiary($transaction->beneficiary, $sCode);
            if (($bResp['status'] ?? 0) !== 201)
                throw new \Exception("Wace Beneficiary: " . ($bResp['message'] ?? 'Erreur'));

            $bCode = $bResp['beneficiary']['Code'] ?? $bResp['Code'];
            $transaction->beneficiary->update(['code' => $bCode]);

            // Final Transaction
            $wTx = $waceservice->sendTransaction($transaction);
            logger($wTx);
            // On accepte 201 (Created) ou 200 (OK) ou 2000 (Success spécifique Wace)
            if (!in_array($wTx['data']['status'] ?? 0, [200, 201, 2000])) {
                logger()->error("Détails de l'échec Wace Final:", ['response' => $wTx]);

                throw new \Exception(
                    "Erreur prestataire Wace : " . ($wTx['message'] ?? 'La transaction n\'a pas pu être finalisée.')
                );
            }

            $transaction->update([
                'provider' => 'wace',
                'status' => 'pending'
            ]); 
        }
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

    public function deposit(Request $request)
    {

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

    /*    public function show(Transaction $transaction)
        {
            return Helpers::success(new TransactionResource($transaction->load(['sender', 'beneficiary', 'ledgerEntries'])));
        }*/

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

    public function getBankList(Request $request)
    {
    
        $type = $request->type === 'mobile' ? 'mobile_money' : 'bank';

        $operators = Gateway::with('payerCode')
            ->whereHas('payerCode', function ($query) use ($request) {
                $query->where('country_code', $request->country_code);
            })
            ->get();

        return Helpers::success($operators);
    }
        public function getWaceData(Request $request)
    {
    
        $type = $request->type;

        $wacedatas = WaceData::query()
            ->where('type',$type)
            ->get();

        return Helpers::success($wacedatas);
    }
}
