<?php

namespace App\Console\Commands;

use App\Http\Services\WacePay\WaceApiService;
use App\Models\Gateway;
use App\Models\PayerCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SetBanks extends Command
{
    protected $signature = 'app:setbanks';
    protected $description = 'Sync WacePay payout services and payer codes';

    protected $waceService;

    public function __construct(WaceApiService $waceService)
    {
        parent::__construct();
        $this->waceService = $waceService;
    }

    public function handle()
    {
             $this->bankGateway();
        $this->info('WacePay sync completed successfully.');
    }
    public function bankGateway()
    {
        $result = [];

        $payerCodes = PayerCode::all();

        foreach ($payerCodes as $payerCode) {


            if (!$payerCode->payer_code || !$payerCode->country_code) {
                continue;
            }

            $responses = $this->waceService->getBanks(
                $payerCode->country_code,
                $payerCode->payer_code
            );

            if (($responses['status'] ?? null) != 2000) {
                Log::warning("Erreur récupération banques WacePay", [
                    'country' => $payerCode->country_code,
                    'payer_code' => $payerCode->payer_code,
                    'response' => $responses
                ]);
                continue;
            }

            foreach (($responses['data'] ?? []) as $bankData) {

                if (empty($bankData['BankCode'])) {
                    continue;
                }

                $gateway = Gateway::updateOrCreate(
                    [
                        'code' => $bankData['BankCode'],
                        'payer_code_id' => $payerCode->id,
                    ],
                    [
                        'name' => $bankData['BankName'] ?? null,
                        'bank_id' => $bankData['BankID'] ?? null,
                        'method' => 'WACEPAY',
                        'type' => $payerCode->service_code === 'B'
                            ? Gateway::TYPE_BANK
                            : Gateway::TYPE_MOBILE_MONEY,
                        'is_active' => true,
                    ]
                );

                $result[] = $gateway;
            }
        }

        $this->info("Banques traitées : " . count($result));

        return $result;
    }
}
