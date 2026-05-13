<?php


namespace App\Http\Services;

use App\Http\Services\Gateways\Bank\WacepayBankService;
use App\Http\Services\Gateways\Mobile\WacepayMobileService;
use App\Models\GatewayCountryService;
use Exception;

class TransferService
{
    /**
     * Pilote le transfert vers la bonne passerelle en fonction du contexte pays/opérateur.
     * @param array $data
     * @param string $userId
     * @throws Exception
     */
    public function dispatchTransfer(array $data, string $userId)
    {
        $type = strtolower($data['type'] ?? '');
        $operatorSlug = strtolower($data['operator'] ?? ''); // Ex: 'orange', 'mtn'
        $countryCode = strtoupper($data['receiver_country'] ?? '');

        if (empty($countryCode)) {
            throw new Exception("Le code pays de destination est requis.");
        }

        // 1. On cherche la config qui lie le pays, le type de service et le slug de l'opérateur
        $serviceConfig = GatewayCountryService::query()
            ->with('gateway') // On charge la relation pour éviter les requêtes N+1
            ->where('country_code', $countryCode)
            ->where('service_type', $type)
           // ->where('gateway_slug', $operatorSlug) // CRUCIAL : On vérifie que c'est le bon opérateur
          //  ->where('status', 'active')
            ->first();

        if (!$serviceConfig) {
            throw new Exception("Le service [{$operatorSlug}] n'est pas disponible pour le pays [{$countryCode}] en mode [{$type}].");
        }

        // On récupère le code de la gateway parente (ex: 'WACEPAY_API', 'ORANGE_API')
        $gatewayCode = $serviceConfig->gateway->code;

        // 2. Résolution de l'instance
        $gateway = $this->resolveGateway($serviceConfig, $type, $gatewayCode);

        // 3. Exécution
        return $gateway->process($data, $userId);
    }

    /**
     * Résout l'instance du service.
     */
    protected function resolveGateway($config, $type, $gatewayCode)
    {
        // Priorité 1 : Classe spécifique définie dans la config pays (Handler personnalisé)
        if (!empty($config->handler_class) && class_exists($config->handler_class)) {
            return app($config->handler_class);
        }

        // Priorité 2 : Fallback sur le moteur par défaut selon le code de la gateway
        return match ($type) {
        'mobile' => $this->getMobileGateway($gatewayCode),
            'bank'   => $this->getBankGateway($gatewayCode),
            'cash'   => $this->getCashGateway($gatewayCode),
            default  => throw new Exception("Type de transfert [{$type}] non supporté."),
        };
    }

    protected function getMobileGateway($gatewayCode)
    {
        return match (strtolower($gatewayCode)) {
        'wacepay' => app(WacepayMobileService::class),
            'orange'  => app(OrangeMoneyService::class),
            'mtn'     => app(MtnMoneyService::class),
            default   => throw new Exception("Passerelle Mobile [{$gatewayCode}] non implémentée."),
        };
    }

    protected function getBankGateway($gatewayCode)
    {
        return match (strtolower($gatewayCode)) {
        'wacepay' => app(WacepayBankService::class),
            default   => throw new Exception("Passerelle Bancaire [{$gatewayCode}] non implémentée."),
        };
    }

    protected function getCashGateway($gatewayCode)
    {
        return match (strtolower($gatewayCode)) {
        'wari' => app(WariService::class),
            default => throw new Exception("Passerelle Cash [{$gatewayCode}] non implémentée."),
        };
    }
}
