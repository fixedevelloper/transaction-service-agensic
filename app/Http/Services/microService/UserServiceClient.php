<?php

namespace App\Http\Services\microService;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UserServiceClient
{


    public function getUsersByIds(array $ids)
    {
        if (empty($ids)) return [];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('API_SERVICE_TOKEN'),
            ])->get(env('USER_SERVICE_URL') . '/api/users', [
                'ids' => implode(',', $ids)
            ]);

            if ($response->successful()) {
                // On indexe par ID pour un accès rapide : [1 => [...], 2 => [...]]
                return collect($response->json('data'))->keyBy('id')->toArray();
            }
        } catch (\Exception $e) {
            Log::error("Échec de récupération des utilisateurs : " . $e->getMessage());
        }

        return [];
    }
    // app/Services/UserServiceClient.php

    public function getUserById($userId)
    {
        // On peut ajouter un cache court pour éviter de surcharger le réseau
        return Cache::remember("user_detail_{$userId}", 60, function () use ($userId) {
            $response = Http::withToken(env('API_SERVICE_TOKEN'))
                ->get(env('USER_SERVICE_URL') . "/api/users/{$userId}");

            return $response->successful() ? $response->json('data') : null;
        });
    }
public function credit($transaction)
{
    // 1. Sécurité : On ne rembourse JAMAIS une transaction qui a réussi 
    // ou qui a déjà été marquée comme remboursée (failed_and_refunded)
    if ($transaction->status === 'success' || $transaction->status === 'failed') {
        Log::warning("Tentative de crédit ignorée : La transaction {$transaction->reference} est déjà finalisée.");
        return true;
    }

    // 2. Appel au microservice de crédit (Remboursement)
    $response = Http::withToken(config('services.user_service.token'))
        ->timeout(15)
        ->post(config('services.user_service.url') . '/users-credit', [
            'user_id'   => $transaction->initiated_by, // Cohérence avec ton modèle (user_id vs initiated_by)
            'amount'    => $transaction->amount,
            'reference' => "REFUND-" . $transaction->reference, // Préfixe pour la traçabilité
        ]);

    // 3. Traitement de la réponse
    if ($response->successful()) {
        $transaction->update([
            'status' => 'failed', // Nouvel état pour éviter les doubles remboursements
            'processed_at' => now(),
            'note' => $transaction->note . " | Remboursé le " . now()->format('d/m/Y H:i')
        ]);

        Log::info("Remboursement réussi pour : " . $transaction->reference);
        return true;
    }

    // 4. Gestion des erreurs fatales (4xx)
    if ($response->clientError()) {
        Log::critical("Erreur fatale lors du remboursement (4xx) : " . $response->body());
        // On ne jette pas d'exception pour ne pas bloquer la file d'attente
        return false;
    }

    // 5. Erreurs temporaires (5xx, Timeout)
    throw new \Exception("UserService instable pour le crédit, nouvelle tentative prévue...");
}
  public function debit($transaction)
{
    // 1. Sécurité : On s'assure que la transaction n'est pas déjà traitée
    if (in_array($transaction->status, ['success', 'failed'])) {
        Log::info("Débit ignoré : La transaction {$transaction->reference} est déjà au statut {$transaction->status}");
        return true; 
    }

    // 2. Appel au microservice
    $response = Http::withToken(config('services.user_service.token'))
        ->timeout(15) // Ajout d'un timeout interne
        ->post(config('services.user_service.url') . '/users-debit', [
            'user_id'   => $transaction->initiated_by, // Vérifie si c'est user_id ou initiated_by dans ton modèle
            'amount'    => $transaction->amount,
            'reference' => $transaction->reference,
        ]);

    // 3. Gestion de la réussite
    if ($response->successful()) {
        $transaction->update([
            'debit_status' => 'success', // Ou 'processing' selon si tu attends encore le prestataire
            'processed_at' => now()
        ]);

        Log::info("Débit réussi pour : " . $transaction->reference);
        return true;
    }

    // 4. Gestion des échecs
    
    // Cas : Solde insuffisant (400) ou Erreur de validation (422)
    if ($response->status() === 400 || $response->status() === 422) {
        $message = $response->json()['message'] ?? "Erreur client 4xx";
        Log::error("Échec débit (Non récupérable) : " . $message);
        
        $transaction->update([
            'debit_status' => 'failed',
            'note' => 'Débit impossible : ' . $message
        ]);
        
        // On ne jette pas d'exception pour ne pas que le Job boucle inutilement
        return false;
    }

    // Cas : Erreurs serveurs (500, 503) ou Timeout réseau
    // On lance une exception pour que le Job (ProcessUserDebit) retente l'action
    throw new \Exception("Microservice User indisponible (Code: {$response->status()}), nouvelle tentative...");
}
}
