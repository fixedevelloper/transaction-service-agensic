<?php

namespace App\Console\Commands;

use App\Http\Services\WacePay\WaceApiService;
use App\Models\WaceData;
use Illuminate\Console\Command;

class CreateWaceData extends Command
{
    protected $signature = 'app:create-wace-data';
    protected $description = 'Import Wace data';

    protected $waceService;

    public function __construct(WaceApiService $waceService)
    {
        parent::__construct();
        $this->waceService = $waceService;
    }

    public function handle()
    {
        $this->info('Début import WACE...');

        $this->process('origin_fonds', fn($type) => $this->waceService->getOriginFonds($type));
        $this->process('relaction', fn($type) => $this->waceService->getRelaction($type));
        $this->process('raison', fn($type) => $this->waceService->getMotifTransaction($type));

        $this->info('Import terminé ✅');
    }

    private function process(string $service, callable $callback)
    {
        $types = ['B2P', 'B2B', 'P2B', 'P2P'];

        foreach ($types as $type) {
            $this->info("Traitement $service - $type");

            try {
                $resp = $callback($type);

                foreach ($resp as $value) {
                    WaceData::updateOrCreate(
                        [
                            'name' => $value,
                            'type' => $type,
                            'service' => $service
                        ],
                        []
                    );
                }

            } catch (\Exception $e) {
                $this->error("Erreur $service - $type : " . $e->getMessage());
            }
        }
    }
}
