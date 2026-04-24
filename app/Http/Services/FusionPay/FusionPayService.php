<?php


namespace App\Http\Services\FusionPay;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FusionPayService
{
    protected string $apiKey;
    protected string $payinUrl;
    protected string $payoutUrl;

    public function __construct()
    {
        $this->apiKey = config('services.moneyfusion.api_key');
        $this->payinUrl = config('services.moneyfusion.payin_url'); // dashboard
        $this->payoutUrl = "https://pay.moneyfusion.net/api/v1/withdraw";
    }

    /**
     * PAYIN (Créer un paiement)
     * @param array $data
     * @return
     */

    public function payIn(array $data)
    {
        try {
            $payload = [
                "totalPrice" => $data['amount'],
                "article" => $data['articles'] ?? [],
                "personal_Info" => [
                    [
                        "userId" => $data['user_id'],
                        "orderId" => $data['order_id']
                    ]
                ],
                "numeroSend" => $data['phone'],
                "nomclient" => $data['name'],
                "return_url" => $data['return_url'],
                "webhook_url" => $data['webhook_url']
            ];

            Log::info("PAYIN REQUEST", $payload);

            $response = Http::timeout(30)
                ->post($this->payinUrl, $payload);

            $body = $response->json();
            Log::info("PAYIN BODY", $body);
            // ❌ erreur HTTP ou métier
            if (!$response->successful() || ($body['statut'] ?? true) === false) {

                Log::error("PAYIN FAILED", [
                    'payload' => $payload,
                    'response' => $body,
                    'status' => $response->status()
                ]);

                return [
                    'success' => false,
                    'message' => $body['message'] ?? 'Erreur lors du paiement',
                    'code' => $response->status()
                ];
            }

            // ✅ succès
            return [
                'success' => true,
                'data' => $body
            ];

        } catch (\Throwable $e) {
    Log::critical("PAYIN EXCEPTION", [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString() // Pour voir où ça bloque exactement
    ]);

}
    }

    /**
     * PAYOUT (Retrait)
     * @param array $data
     * @return
     */
    public function payOut(array $data)
    {
        logger(json_encode($data));
        try {
            $payload = [
                "countryCode" => $data['country_code'],
                "phone" => $data['phone'],
                "amount" => intval($data['amount']),
                "withdraw_mode" => $data['method'],
                "webhook_url" => $data['webhook_url'] ?? null
            ];

            Log::info("PAYOUT REQUEST", $payload);

            $response = Http::withHeaders([
                "moneyfusion-private-key" => $this->apiKey,
                "Content-Type" => "application/json"
            ])->post($this->payoutUrl, $payload);

            $body = $response->json();

            // ❌ erreur API métier (comme ton cas)
            if (!$response->successful() || ($body['statut'] ?? true) === false) {

                Log::error("PAYOUT FAILED", [
                    'payload' => $payload,
                    'response' => $body
                ]);

                return [
                    'success' => false,
                    'message' => $body['message'] ?? 'Erreur lors du paiement',
                    'code' => $response->status()
                ];
            }

            // ✅ succès
            return [
                'success' => true,
                'data' => $body
            ];

        } catch (\Throwable $e) {

            Log::critical("PAYOUT EXCEPTION", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Erreur serveur lors du paiement'
            ];
        }
    }

    /**
     * Vérifier statut paiement
     */
    public function checkPayment(string $token)
    {
        $url = "https://www.pay.moneyfusion.net/paiementNotif/" . $token;

        $response = Http::get($url);

        return $response->json();
    }
}
