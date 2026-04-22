<?php

namespace App\Jobs;

use App\Http\Services\microService\UserServiceClient;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessUserDebit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $transaction;

    /**
     * Le nombre de fois que le job peut être tenté.
     */
    public $tries = 3;

    /**
     * Le nombre de secondes à attendre avant de retenter le job.
     */
    public $backoff = 10;

    public function __construct(Transaction $transaction)
    {
        // On passe l'objet Transaction complet
        $this->transaction = $transaction;
    }

    public function handle(UserServiceClient $userService)
    {
        try {
            // Laravel injecte automatiquement UserServiceClient via le Type-hint
            $success = $userService->debit($this->transaction);

            if (!$success) {
                throw new \Exception("Échec du débit pour la transaction {$this->transaction->id}");
            }

            Log::info("Débit réussi pour la transaction : {$this->transaction->reference}");

        } catch (\Exception $e) {
            Log::error("Erreur Job Debit: " . $e->getMessage());
            
            // Si c'est la dernière tentative, on peut marquer la transaction comme échouée
            if ($this->attempts() >= $this->tries) {
                $this->transaction->update(['status' => 'failed', 'note' => 'Debit failed after retries']);
            }

            throw $e; // Relancer pour que Laravel gère le retry
        }
    }
}