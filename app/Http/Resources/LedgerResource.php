<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LedgerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount' => $this->amount,

            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,

            'description' => $this->description,

            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
