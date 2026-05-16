<?php

namespace App\Console\Commands;

use App\Http\Services\AgensicPay\AgensicService;
use App\Http\Services\microService\UserServiceClient;
use App\Http\Services\WacePay\WaceApiService;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckStatus extends Command
{
    protected $signature = 'app:check-status';
    protected $description = 'Vérifie le statut des transactions bancaires en attente auprès de Wace';

    protected $waceService;
    protected $userService;
    protected $agensicService;
    public function __construct(WaceApiService $waceService, UserServiceClient $userServiceClient,AgensicService $agensicService)
    {
        parent::__construct();
        $this->waceService = $waceService;
        $this->userService = $userServiceClient;
        $this->agensicService=$agensicService;
    }
    public function handle()
    {
        // On récupère les transactions 'pending' ou 'processing'
        $transactions = Transaction::query()
            ->whereIn('status', ['pending', 'processing'])
            ->get();

        if ($transactions->isEmpty()) {
            $this->info("Aucune transaction en attente.");
            return;
        }

        foreach ($transactions as $transaction) {
            $this->info("Vérification de la transaction : {$transaction->reference}");

         switch ($transaction->provider){
             case 'wace':
                 $this->CheckStatusWacePay($transaction);
             case 'fusionpay':
             case 'agensicpay':
                 $this->CheckStatusAgensicPay($transaction);
         }
        }
    }
    public function handle2()
    {
        // On récupère les transactions 'pending' ou 'processing'
        $transactions = Transaction::query('provider', 'wace')
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
    protected function CheckStatusAgensicPay(Transaction $transaction){
        $json = [
            'apikey' => '87S86K61M9W11G27R25G99W30O96X23F87D79N85G',
            'transactionId' => $transaction->reference_partner,
        ];
        $response = $this->agensicService->getPayID($json);
        if (isset($response['status']) && $response['status'] == "Success") {
            $transaction->update(['status' => 'success']);
            $this->info("Transaction AGENSICPAY {$transaction->reference} marquée comme SUCCESS.");
        }elseif (isset($response['status']) && $response['status'] == "Failed"){
            // Si échoué, on met à jour et on rembourse l'utilisateur
            $transaction->update(['status' => 'failed']);

            // Remboursement via le UserService
            $refund = $this->userService->credit($transaction);

            if ($refund) {
                $this->warn("Transaction AGENSICPAY {$transaction->reference} FAILED. Utilisateur remboursé.");
            } else {
                Log::error("Échec du remboursement pour la transaction AGENSICPAY : {$transaction->reference}");
                $this->error("Erreur lors du remboursement AGENSICPAY de {$transaction->reference}");
            }
        }

    }
    protected function CheckStatusWacePay(Transaction $transaction){
        try {
            // 1. Appel au service Wace
            $res = $this->waceService->getStatusTransaction($transaction->provider_token);

            // Sécurité : Vérifier si la réponse est valide et contient les données
            if (!$res || !isset($res['transaction']['Status'])) {
                $this->error("Réponse invalide pour WACEPAY {$transaction->reference}");
                continue;
            }

            $waceStatus = $res['transaction']['Status'];

            // 2. Logique de mise à jour
            if ($waceStatus === 'PAID') {
                $transaction->update(['status' => 'success']);
                $this->info("Transaction WACEPAY {$transaction->reference} marquée comme SUCCESS.");
            }
            elseif (in_array($waceStatus, ['CANCELED', 'LOCKED', 'REJECTED'])) {
                // Si échoué, on met à jour et on rembourse l'utilisateur
                $transaction->update(['status' => 'failed']);

                // Remboursement via le UserService
                $refund = $this->userService->credit($transaction);

                if ($refund) {
                    $this->warn("Transaction WACEPAY {$transaction->reference} FAILED. Utilisateur remboursé.");
                } else {
                    Log::error("Échec du remboursement pour la transaction WACEPAY : {$transaction->reference}");
                    $this->error("Erreur lors du remboursement WACEPAY de {$transaction->reference}");
                }
            }

        } catch (\Exception $e) {
            Log::error("Erreur CheckStatus pour {$transaction->reference} : " . $e->getMessage());
            $this->error("Erreur technique pour {$transaction->reference}");
        }
    }
}
