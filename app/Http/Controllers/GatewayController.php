<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PaymentGateway;
use App\Models\GatewayCountryService;

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
        $request->validate([
            'name' => 'required|string|unique:payment_gateways,name',
            'code' => 'required|string|unique:payment_gateways,code',
            'type' => 'nullable|string',
        ]);

        $gateway = PaymentGateway::create([
            'name' => $request->name,
            'code' => $request->code,
            'type' => $request->type,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Gateway created successfully',
            'data' => $gateway
        ]);
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
}