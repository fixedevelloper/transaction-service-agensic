<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,

            'amount' => $this->amount,
            'currency' => $this->currency,
            'type' => $this->type,
            'status' => $this->status,

            'note' => $this->note,

            // Relations
            'sender' => new SenderResource($this->whenLoaded('sender')),
            'beneficiary' => new BeneficiaryResource($this->whenLoaded('beneficiary')),

            'ledger' => LedgerResource::collection(
                $this->whenLoaded('ledgerEntries')
            ),

            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
