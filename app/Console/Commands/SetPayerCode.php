<?php

namespace App\Console\Commands;

use App\Http\Services\WacePay\WaceApiService;
use App\Models\PayerCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SetPayerCode extends Command
{
    protected $signature = 'app:setpayercode';
    protected $description = 'Sync WacePay payout services and payer codes';

    protected $waceService;

    public function __construct(WaceApiService $waceService)
    {
        parent::__construct();
        $this->waceService = $waceService;
    }

    public function handle()
    {
        $countries = $this->getCountries();

        foreach ($countries as $country) {
            $this->syncCountry($country);
        }

        $this->info('WacePay sync completed successfully.');
    }

    private function getCountries(): array
    {
        $response = Http::withToken(env('USER_SERVICE_TOKEN'))
            ->timeout(10)
            ->get(env('USER_SERVICE_URL') . '/countries');

        if (!$response->successful()) {
            throw new \Exception('UserService countries API failed');
        }

        return $response->json('data') ?? [];
    }

    private function syncCountry($country): void
    {
        $services = $this->waceService
            ->postPayoutService($country['iso'], $country['currency']);

        if (($services['status'] ?? null) != 2000 || empty($services['data'])) {
            Log::warning('Payout services fetch failed', [
                'country' => $country['iso'],
                'response' => $services,
            ]);
            return;
        }

        foreach ($services['data'] as $service) {
            $this->syncService($country, $service);
        }
    }

    private function syncService($country, array $service): void
    {
        $payerCode = PayerCode::firstOrNew([
            'service_code' => $service['ServiceCode'],
            'country_code' => $country['iso'],
        ]);

        $payerCode->service_name = $service['ServiceName'];
        $payerCode->save();

        $resp = $this->waceService->postPayercode(
            $country['iso'],
            $country['currency'],
            $service['ServiceCode']
        );

        if (($resp['status'] ?? null) != 2000 || empty($resp['transaction']['PayerCode'])) {
            Log::warning('PayerCode sync failed', [
                'country' => $country['iso'],
                'service' => $service['ServiceCode'],
                'response' => $resp,
            ]);
            return;
        }

        $payerCode->update([
            'payer_code' => $resp['transaction']['PayerCode'],
        ]);
    }
}
