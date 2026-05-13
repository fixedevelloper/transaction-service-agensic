<?php

namespace App\Http\Controllers;

use App\Http\Services\TransferService;
use App\Models\Sender;
use Illuminate\Http\Request;
use App\Models\GatewayCountryService;
use App\Models\User;
use Illuminate\Support\Facades\Log;

// Supposons que tes expéditeurs sont des Users

class SimulationController extends Controller
{
    protected $transferService;

    public function __construct(TransferService $transferService)
    {
        $this->transferService = $transferService;
    }
    /**
     * Étape 1 & 5 : Calcul des frais et récupération des passerelles
     */
    public function calculateFees(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'country' => 'required|string|size:2',
            'type' => 'required|string|in:mobile,bank,cash',
        ]);

        $amount = $request->amount;
        $country = $request->country;
        $type = $request->type;

        // Récupérer les passerelles actives pour ce pays et ce service
        $configs = GatewayCountryService::with('gateway')
            ->where('country_code', $country)
            ->where('service_type', $type)
           // ->where('is_enabled', true)
            ->orderBy('priority', 'asc')
            ->get();

        if ($configs->isEmpty()) {
            return response()->json(['message' => 'Aucune passerelle disponible'], 200);
        }

        $results = $configs->map(function ($config) use ($amount) {
            $fees = $this->computeTieredFees($amount, $config);
            return [
                'gateway_id' => $config->gateway_id,
                'gateway_name' => $config->gateway->name,
                'fixed_fee' => $fees['fixed'],
                'percent_fee' => $fees['percent'],
                'total_fees' => $fees['total'],
                'total_to_pay' => $amount + $fees['total'],
            ];
        });

        // Pour l'étape 1, on renvoie souvent la passerelle par défaut (la première)
        return response()->json([
            'selected_config' => $results->first(),
            'available_gateways' => $results
        ]);
    }

    /**
     * Logique de calcul basée sur les paliers (Tiers)
     */
    private function computeTieredFees($amount, $config)
    {
        $fixed = $config->fixed_fee;
        $percent = $config->percent_fee;

        // Vérifier si des paliers existent dans le JSON meta
        if ($config->meta && isset($config->meta['tiers'])) {
            foreach ($config->meta['tiers'] as $tier) {
                $min = $tier['min'] ?? 0;
                $max = $tier['max'] ?? PHP_INT_MAX;

                if ($amount >= $min && $amount <= $max) {
                    $fixed = $tier['fixed_fee'];
                    $percent = $tier['percent_fee'];
                    break;
                }
            }
        }

        $totalFees = ($amount * ($percent / 100)) + $fixed;

        return [
            'fixed' => $fixed,
            'percent' => $percent,
            'total' => $totalFees
        ];
    }

    public function send(Request $request)
    {
        // 1. Validation stricte
        // Note: 'operator' remplace 'gateway' pour matcher ta logique Wace
        $validated = $request->validate([
            // Champs de base
            'sender_id'        => 'required|exists:senders,id',
            'beneficiary_id'   => 'required|exists:beneficiaries,id',
            'receiver_country' => 'required|string|max:3', // ex: FR, CM
            'amount'           => 'required|numeric|min:1',
            'operator'         => 'required|string', // Reçoit data.gateway
            'type'             => 'required|in:mobile,bank,cash',
            'currency'         => 'required|string|size:3',

            // Nouveaux champs de conformité (Etape 6)
            'origin_fond'      => 'required|string',
            'motif_send'       => 'required|string',
            'relation'         => 'required|string',

            // Note et Meta
            'note'             => 'nullable|string|max:255',
            'meta_data'        => 'nullable|array'
        ]);

        try {
            // 2. Extraction de l'ID utilisateur (NextAuth)
            $userId = $request->header('X-User-Id');

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié (Header X-User-Id manquant)'
                ], 401);
            }

            // 3. Dispatch vers le service
            // On passe $validated (un array) et $userId
            $result = $this->transferService->dispatchTransfer($validated, $userId);

            // Si le service Helpers::success est utilisé dans le dispatch,
            // il retournera déjà une JsonResponse. Sinon, on la forge ici.
            return $result;

        } catch (\Exception $e) {
            Log::error("Erreur de transfert : " . $e->getMessage(), [
                'user_id' => $request->header('X-User-Id'),
                'payload' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Échec du transfert : ' . $e->getMessage()
            ], 500);
        }
    }
}
