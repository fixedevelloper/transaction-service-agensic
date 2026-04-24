<?php

namespace App\Services;

use App\Http\Services\FusionPay\FusionPayService;
use App\Http\Services\WacePay\WaceApiService;
use App\Models\GatewayCountryService;
use Exception;

class GatewayResolver
{
    /**
     * Résout l'implémentation du service de paiement en fonction du contexte.
     *
     * @param string $country Code ISO du pays (ex: CM, CI)
     * @param string $service Type de service (mobile, bank)
     * @return mixed Instance du service de paiement
     * @throws Exception
     */
    public static function resolve(string $country, string $service)
    {
        // 1. On cherche la configuration active la plus prioritaire
        // On charge la relation 'gateway' pour accéder au champ 'code' (ex: flutterwave, wace)
        $config = GatewayCountryService::with('gateway')
            ->where('country_code', $country)
            ->where('service_type', $service)
            ->where('is_enabled', true)
            ->orderBy('priority', 'asc') // La priorité 1 passe avant la 2
            ->first();

        if (!$config || !$config->gateway) {
            throw new Exception("Aucune passerelle de paiement configurée pour le pays ($country) et le service ($service).");
        }

        // 2. On récupère le code technique défini dans la table 'payment_gateways'
        $gatewayCode = strtolower($config->gateway->code);

        // 3. On retourne l'instance du service correspondante
        return match ($gatewayCode) {
           // 'flutterwave' => app(FlutterwaveService::class),
            'fusionpay'    => app(FusionPayService::class),
            'wace', 'wacepay' => app(WaceApiService::class),
          //  'paydunya'    => app(PayDunyaService::class),
            
            // Si tu as un driver générique ou si tu veux logger l'erreur
            default => throw new Exception("Le fournisseur de paiement [{$gatewayCode}] n'est pas supporté par le système."),
        };
    }
}