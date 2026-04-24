<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Http;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncDepositWithServiceA implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $data;

    // Tentatives en cas d'échec (Service A indisponible)
    public $tries = 5;
    public $backoff = 60; // Attendre 60s entre chaque tentative

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function handle()
    {
        $response = Http::withToken(config('services.user_service.token'))
            ->timeout(20)
            ->post(config('services.user_service.url').'/deposits', [
                'user_id'     => $this->data['user_id'],
                'amount'      => $this->data['amount'],
                'reference'   => $this->data['order_id'],
                'token'   => $this->data['token'],
                'operator_id' => $this->data['operator_id'],
                'status'      => 'pending',
            ]);

        if (!$response->successful()) {
            throw new \Exception("Échec de synchronisation avec le Service A");
        }
    }
}