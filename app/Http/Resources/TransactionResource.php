<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'reference'     => $this->reference,

            // Détails de la transaction
            'financials' => [
                'amount'   => (float) $this->amount,
                'currency' => strtoupper($this->currency),
                'display'  => number_format($this->amount, 2, '.', ' ') . ' ' . $this->currency,
                'type'     => $this->type, // ex: transfer, deposit, withdrawal
            ],

            // Statut avec formatage pour l'UI
            'status' => [
                'value' => $this->status,
                'label' => $this->getStatusLabel(),
                'color' => $this->getStatusColor(),
            ],

            // Informations sur les acteurs
            'parties' => [
                'sender'      => new SenderResource($this->whenLoaded('sender')),
                'beneficiary' => new BeneficiaryResource($this->whenLoaded('beneficiary')),
            ],

            // Métadonnées
            'meta' => [
                'note'         => $this->note,
                'initiated_by' => $this->initiated_by, // ID de l'admin ou du système
                'has_ledger'   => $this->ledger_entries_count > 0,
            ],

            'dates' => [
                'created_at' => $this->created_at->format('d/m/Y H:i'),
                'updated_at' => $this->updated_at->format('d/m/Y H:i'),
            ],
        ];
    }

    /**
     * Traduction des statuts pour le Dashboard
     */
    private function getStatusLabel(): string
    {
        return match($this->status) {
        'pending'   => 'En attente',
            'completed' => 'Terminé',
            'failed'    => 'Échoué',
            'cancelled' => 'Annulé',
            default     => ucfirst($this->status),
        };
    }

    /**
     * Couleurs sémantiques pour les composants Badge de Shadcn/ui
     */
    private function getStatusColor(): string
    {
        return match($this->status) {
        'completed' => '#10b981', // Emerald-500
            'pending'   => '#f59e0b', // Amber-500
            'failed'    => '#ef4444', // Red-500
            'cancelled' => '#64748b', // Slate-500
            default     => '#3b82f6', // Blue-500
        };
    }
}
