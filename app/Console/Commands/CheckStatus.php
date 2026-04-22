<?php

<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\WaceApiService;
use App\Services\UserServiceClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckStatus extends Command
{
    protected $signature = 'app:check-status';
    protected $description = 'Vérifie le statut des transactions bancaires en attente auprès de Wace';

    protected $waceService;
    protected $userService;

    public function __construct(WaceApiService $waceService, UserServiceClient $userServiceClient)
    {
        parent::__construct();
        $this->waceService = $waceService;
        $this->userService = $userServiceClient;
    }

    public function handle()
    {
        // On récupère les transactions 'pending' ou 'processing'
        $transactions = Transaction::where('type', 'bank')
            ->whereIn('status', ['pending', 'processing'])
            ->get();

        if ($transactions->isEmpty()) {
            $this->info("Aucune transaction en attente.");
            return;
        }

        foreach ($transactions as $transaction) {
            $this->info("Vérification de la transaction : {$transaction->reference}");

            try {
                // 1. Appel au service Wace
                $res = $this->waceService->getStatusTransaction($transaction->provider_token);

                // Sécurité : Vérifier si la réponse est valide et contient les données
                if (!$res || !isset($res['transaction']['Status'])) {
                    $this->error("Réponse invalide pour {$transaction->reference}");
                    continue;
                }

                $waceStatus = $res['transaction']['Status'];

                // 2. Logique de mise à jour
                if ($waceStatus === 'PAID') {
                    $transaction->update(['status' => 'success']);
                    $this->info("Transaction {$transaction->reference} marquée comme SUCCESS.");
                } 
                elseif (in_array($waceStatus, ['CANCELED', 'LOCKED', 'REJECTED'])) {
                    // Si échoué, on met à jour et on rembourse l'utilisateur
                    $transaction->update(['status' => 'failed']);
                    
                    // Remboursement via le UserService
                    $refund = $this->userService->credit($transaction);
                    
                    if ($refund) {
                        $this->warn("Transaction {$transaction->reference} FAILED. Utilisateur remboursé.");
                    } else {
                        Log::error("Échec du remboursement pour la transaction : {$transaction->reference}");
                        $this->error("Erreur lors du remboursement de {$transaction->reference}");
                    }
                }

            } catch (\Exception $e) {
                Log::error("Erreur CheckStatus pour {$transaction->reference} : " . $e->getMessage());
                $this->error("Erreur technique pour {$transaction->reference}");
            }
        }
    }
}
