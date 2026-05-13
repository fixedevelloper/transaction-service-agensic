<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use App\Models\PaymentGateway;
use App\Models\GatewayCountryService;
use Illuminate\Support\Facades\Validator;

class GatewayController extends Controller
{
    /**
     * =========================================
     * LIST ALL PAYMENT GATEWAYS
     * =========================================
     */
    public function index()
    {
        return response()->json([
            'data' => PaymentGateway::all()
        ]);
    }

    /**
     * =========================================
     * CREATE NEW PAYMENT GATEWAY
     * =========================================
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'code'        => 'required|string|unique:payment_gateways,code',
            'type'        => 'required|in:fintech,bank_api,mobile_money',
            'is_active'   => 'boolean',
            'logo'        => 'nullable|string',
            'website'     => 'nullable|url',
            'credentials' => 'nullable|array',
            'settings'    => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $gateway = PaymentGateway::create($request->all());

            return response()->json([
                'status' => 'success',
                'message' => 'Gateway créée avec succès',
                'data' => $gateway
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * =========================================
     * LOAD MATRIX (FOR NEXTJS UI)
     * =========================================
     */
    public function matrix()
    {
        $gateways = PaymentGateway::all();
        $services = GatewayCountryService::with('gateway')->get();

        $matrix = [];

        foreach ($services as $service) {
            $gatewayName = $service->gateway->name;
            $country = $service->country_code;
            $type = $service->service_type;

            $matrix[$country][$gatewayName][$type] = (bool) $service->is_enabled;
        }

        return response()->json([
            'matrix' => $matrix
        ]);
    }
    public function matrixByIDCountry($iso)
    {
        logger($iso);
        // 1. Récupérer les services avec la relation gateway
        // Attention : Vérifie si ta colonne est 'country_iso' ou 'country_code'
        // d'après ta migration précédente c'était 'country_code'
        $services = GatewayCountryService::with('gateway')
            ->where('country_code', $iso)
            ->get();

        // 2. Transformer la collection pour le frontend
        $data = $services->map(function ($service) {
            return [
                'id' => $service->id,
                'gateway_name' => $service->gateway->name ?? 'Unknown',
                'service_type' => $service->service_type,
                'fixed_fee' => (float) $service->fixed_fee,
                'percent_fee' => (float) $service->percent_fee,
                'is_enabled' => (bool) $service->is_enabled,
                'meta' => $service->meta, // Laravel cast automatiquement en array si défini dans le Model
            ];
        });

        return response()->json([
            'data' => $data
        ]);
    }

    /**
     * =========================================
     * SAVE MATRIX (FROM NEXTJS)
     * =========================================
     */
    public function save(Request $request)
    {
        foreach ($request->matrix as $countryCode => $gateways) {

            foreach ($gateways as $gatewayName => $services) {

                $gateway = PaymentGateway::where('name', $gatewayName)->first();

                if (!$gateway) continue;

                foreach (['mobile', 'bank'] as $type) {

                    GatewayCountryService::updateOrCreate(
                        [
                            'gateway_id' => $gateway->id,
                            'country_code' => $countryCode,
                            'service_type' => $type,
                        ],
                        [
                            'is_enabled' => $services[$type] ?? false,
                        ]
                    );
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Matrix saved successfully'
        ]);
    }
    public function saveSync(Request $request)
    {
        $configs = $request->input('configs');

        foreach ($configs as $config) {
            GatewayCountryService::where('id', $config['id'])->update([
                'meta' => $config['meta']
            ]);
        }

        return response()->json(['message' => 'Success']);
    }
    public static function calculateFees($country, $service, $amount)
    {
        $config = GatewayCountryService::where('country_code', $country)
            ->where('service_type', $service)
            ->where('is_enabled', true)
            ->first();

        if (!$config) throw new Exception("Configuration introuvable");

        // Valeurs par défaut (si aucun palier ne match)
        $fixedFee = $config->fixed_fee;
        $percentFee = $config->percent_fee;

        // Vérification des paliers dans meta
        if ($config->meta && isset($config->meta['tiers'])) {
            foreach ($config->meta['tiers'] as $tier) {
                $min = $tier['min'];
                $max = $tier['max'] ?? PHP_INT_MAX; // Si max est null, c'est l'infini

                if ($amount >= $min && $amount <= $max) {
                    $fixedFee = $tier['fixed_fee'];
                    $percentFee = $tier['percent_fee'];
                    break; // On a trouvé le bon palier
                }
            }
        }

        return ($amount * ($percentFee / 100)) + $fixedFee;
    }
}
